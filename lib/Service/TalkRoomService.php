<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Manager as TalkManager;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\RoomService;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Talk conversation for the people scheduled into an appointment.
 *
 * Talk exposes no public PHP API for participants — `OCP\Talk\IBroker` can only
 * create a conversation with moderators — so this reaches into
 * `OCA\Talk\Service\*` through the container, the same way GuestService reaches
 * into the Guests app. Everything degrades to a no-op when Talk is missing or
 * its API moved: the room stays as it is and the app keeps working.
 *
 * Membership is a confidentiality promise: the final details are handed out in
 * that room, so someone who loses their place has to leave it.
 */
class TalkRoomService {
	private const TALK_APP_ID = 'spreed';

	/**
	 * Talk constants, inlined rather than referenced: touching
	 * `OCA\Talk\Room::TYPE_GROUP` would autoload Talk even when it is absent.
	 * Verified against Talk stable32–stable34.
	 */
	private const ROOM_TYPE_GROUP = 2;          // Room::TYPE_GROUP
	private const ACTOR_USERS = 'users';        // Attendee::ACTOR_USERS
	private const PARTICIPANT_MODERATOR = 2;    // Participant::MODERATOR
	private const REASON_REMOVED = 'remove';    // AAttendeeRemovedEvent::REASON_REMOVED
	private const NAME_MAX_LENGTH = 255;        // conversations.name column
	private const DESCRIPTION_MAX_LENGTH = 2000; // Room::DESCRIPTION_MAXIMUM_LENGTH

	private ?bool $talkEnabled = null;

	/** @var array<class-string, ?object> Talk services already looked up */
	private array $resolved = [];

	public function __construct(
		private ContainerInterface $container,
		private IAppManager $appManager,
		private IUserManager $userManager,
		private IURLGenerator $urlGenerator,
		private IFactory $l10nFactory,
		private IUserConfig $userConfig,
		private IDateTimeFormatter $dateTimeFormatter,
		private AppointmentMapper $appointmentMapper,
		private AttendanceResponseMapper $responseMapper,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether conversations can be offered at all. Cheap enough to call on
	 * every capabilities request.
	 */
	public function isAvailable(): bool {
		if ($this->talkEnabled === null) {
			try {
				// isEnabledForAnyone, not isEnabledForUser: most of this runs
				// from cron, where there is no session user. Class presence is
				// resolve()'s job — the autoloader only exists after loadApp().
				$this->talkEnabled = $this->appManager->isEnabledForAnyone(self::TALK_APP_ID);
			} catch (\Throwable $e) {
				$this->logger->debug('Checking Talk availability failed', ['app' => 'attendance', 'exception' => $e]);
				$this->talkEnabled = false;
			}
		}

		return $this->talkEnabled;
	}

	/**
	 * Whether a room may exist for this appointment yet.
	 *
	 * With planning on, who holds a place is only settled when the inquiry
	 * closes, so the room waits for that. Without planning a yes is the whole
	 * commitment — waiting would open the room at the appointment's start,
	 * which is far too late to agree on where to meet.
	 */
	public function mayOpenRoom(Appointment $appointment): bool {
		return $appointment->isClosed() || !$this->configService->isBookingEnabled();
	}

	/**
	 * Create the conversation for an appointment and store its token. Returns
	 * the token, or null when nothing was created — Talk unavailable, a room
	 * already linked, or nobody to invite.
	 *
	 * Idempotent: an appointment that already carries a live token keeps it.
	 */
	public function createForAppointment(Appointment $appointment): ?string {
		if (!$this->isAvailable()) {
			return null;
		}

		if ($this->resolveExistingRoom($appointment) !== null) {
			return $appointment->getTalkRoomToken();
		}

		$owner = $this->resolveOwner($appointment);
		if ($owner === null) {
			$this->logger->warning('No owner available for the Talk conversation', [
				'app' => 'attendance',
				'appointmentId' => $appointment->getId(),
			]);
			return null;
		}

		// Loaded once and threaded through the reconcile below — the room's
		// membership and the "may we touch this person" set both read it.
		$responses = $this->responseMapper->findByAppointment($appointment->getId());
		$targets = $this->targetsFrom($appointment, $responses);
		// Organisers alone are not a conversation. Wait for somebody to be in.
		if (array_diff($targets, $appointment->getOrganizersList()) === []) {
			return null;
		}

		try {
			$roomService = $this->resolve(RoomService::class);
			if ($roomService === null) {
				return null;
			}

			// Plain group room, not a Talk "event" room: every client hides
			// event rooms from the list until 16 hours before the start, and
			// this room is opened when the inquiry closes — usually much
			// earlier, which is when people start using it.
			//
			// Named arguments: stable34 inserted `$attributes` mid-signature.
			$room = $roomService->createConversation(
				type: self::ROOM_TYPE_GROUP,
				name: $this->buildName($appointment, $owner),
				owner: $owner,
			);

			// Persist the token before anything else can fail — a room we
			// created but did not record is a room nobody can find again.
			$appointment->setTalkRoomToken($room->getToken());
			$this->appointmentMapper->update($appointment);

			try {
				$roomService->setDescription($room, $this->buildDescription($appointment, $owner));
			} catch (\Throwable $e) {
				$this->logger->warning('Setting the Talk conversation description failed', [
					'app' => 'attendance',
					'appointmentId' => $appointment->getId(),
					'exception' => $e,
				]);
			}

			$this->reconcile($room, $appointment, $owner, $responses);

			return $room->getToken();
		} catch (\Throwable $e) {
			$this->logger->error('Creating the Talk conversation failed', [
				'app' => 'attendance',
				'appointmentId' => $appointment->getId(),
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * Open the room if the appointment opted in and may have one, otherwise just
	 * bring an existing room back in step. The one call every write path that
	 * changes membership makes.
	 */
	public function openOrSync(Appointment $appointment): void {
		if (!$this->isAvailable()) {
			return;
		}

		if ($appointment->getTalkRoomToken() === null) {
			if ($appointment->getCreateTalkRoom() && $this->mayOpenRoom($appointment)) {
				$this->createForAppointment($appointment);
			}
			return;
		}

		$this->syncParticipants($appointment);
	}

	/**
	 * Delete the linked conversation, and the opt-in with it — left standing, the
	 * next answer would open a fresh room. False when Talk refused.
	 */
	public function deleteForAppointment(Appointment $appointment): bool {
		if ($appointment->getTalkRoomToken() === null || !$this->isAvailable()) {
			return false;
		}

		$roomService = $this->resolve(RoomService::class);
		if ($roomService === null) {
			return false;
		}

		try {
			// A room already gone in Talk is the end state asked for; the write
			// below covers its token too.
			$room = $this->resolveExistingRoom($appointment, persist: false);
			if ($room !== null) {
				$roomService->deleteRoom($room);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Deleting the Talk conversation failed', [
				'app' => 'attendance',
				'appointmentId' => $appointment->getId(),
				'exception' => $e,
			]);
			return false;
		}

		$appointment->setTalkRoomToken(null);
		$appointment->setCreateTalkRoom(false);
		$this->appointmentMapper->update($appointment);

		return true;
	}

	/**
	 * Carry an edited name, date or description into the room. Talk drops a
	 * write that changes nothing, so an edit that touched neither costs a read.
	 *
	 * A room renamed by hand loses that name here — the appointment is the one
	 * source both sides agree on.
	 */
	public function syncRoomDetails(Appointment $appointment): void {
		if ($appointment->getTalkRoomToken() === null || !$this->isAvailable()) {
			return;
		}

		try {
			$room = $this->resolveExistingRoom($appointment);
			$owner = $this->resolveOwner($appointment);
			if ($room === null || $owner === null) {
				return;
			}

			$roomService = $this->resolve(RoomService::class);
			if ($roomService === null) {
				return;
			}

			$roomService->setName($room, $this->buildName($appointment, $owner));
			$roomService->setDescription($room, $this->buildDescription($appointment, $owner));
		} catch (\Throwable $e) {
			$this->logger->warning('Updating the Talk room name and description failed', [
				'app' => 'attendance',
				'appointmentId' => $appointment->getId(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * Bring the room membership back in line with who is scheduled. No-op
	 * without a linked room, so callers can fire it on every response change
	 * without checking first.
	 */
	public function syncParticipants(Appointment $appointment): void {
		if (!$this->isAvailable()) {
			return;
		}

		try {
			$room = $this->resolveExistingRoom($appointment);
			if ($room === null) {
				return;
			}
			$this->reconcile($room, $appointment, $this->resolveOwner($appointment));
		} catch (\Throwable $e) {
			$this->logger->error('Syncing Talk participants failed', [
				'app' => 'attendance',
				'appointmentId' => $appointment->getId(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * User IDs that belong in the room: the organisers, plus the people who
	 * hold a place. With planning switched off instance-wide there is no
	 * "booked" state to read, so a yes is the only commitment there is.
	 *
	 * @param list<AttendanceResponse> $responses
	 * @return list<string>
	 */
	private function targetsFrom(Appointment $appointment, array $responses): array {
		$bookingEnabled = $this->configService->isBookingEnabled();

		$targets = $appointment->getOrganizersList();
		foreach ($responses as $response) {
			if ($this->holdsPlace($response, $bookingEnabled)) {
				$targets[] = $response->getUserId();
			}
		}

		return array_values(array_unique($targets));
	}

	/**
	 * Whether one answer amounts to holding a place. The single-response form of
	 * the rule targetsFrom() applies to the whole set — kept together so the two
	 * cannot drift.
	 */
	private function holdsPlace(AttendanceResponse $response, bool $bookingEnabled): bool {
		if ($response->getResponse() !== 'yes') {
			return false;
		}

		return !$bookingEnabled || $response->getBookingStatus() === BookingService::STATUS_BOOKED;
	}

	/**
	 * Whether a viewer is in the room, and may therefore be told it exists.
	 * Organisers and managers always are; everyone else needs a place.
	 */
	public function belongsInRoom(bool $canManage, ?AttendanceResponse $ownResponse): bool {
		if ($canManage) {
			return true;
		}

		return $ownResponse !== null
			&& $this->holdsPlace($ownResponse, $this->configService->isBookingEnabled());
	}

	/**
	 * The people this app may act on: everyone who answered, plus the
	 * organisers. Whoever sits in the room outside this set was put there by a
	 * moderator and stays untouched.
	 *
	 * @param list<AttendanceResponse> $responses
	 * @return list<string>
	 */
	private function governedFrom(Appointment $appointment, array $responses): array {
		$governed = $appointment->getOrganizersList();
		foreach ($responses as $response) {
			$governed[] = $response->getUserId();
		}

		return array_values(array_unique($governed));
	}

	/**
	 * @param ?list<AttendanceResponse> $responses Already-loaded responses, to
	 *                                             save the query when the caller has them
	 */
	private function reconcile(Room $room, Appointment $appointment, ?IUser $addedBy, ?array $responses = null): void {
		$participantService = $this->resolve(ParticipantService::class);
		if ($participantService === null) {
			return;
		}

		$responses ??= $this->responseMapper->findByAppointment($appointment->getId());
		$target = $this->targetsFrom($appointment, $responses);
		$governed = $this->governedFrom($appointment, $responses);

		$byActor = $this->indexByActor($participantService->getParticipantsForRoom($room));
		$present = array_keys($byActor);

		$toAdd = [];
		foreach (array_diff($target, $present) as $userId) {
			$user = $this->userManager->get($userId);
			// Talk can be limited to certain groups; putting someone into a
			// conversation they cannot open helps nobody.
			if ($user === null || !$this->appManager->isEnabledForUser(self::TALK_APP_ID, $user)) {
				continue;
			}
			$toAdd[] = [
				'actorType' => self::ACTOR_USERS,
				'actorId' => $userId,
				'displayName' => $user->getDisplayName(),
			];
		}

		if ($toAdd !== []) {
			$participantService->addUsers($room, $toAdd, $addedBy);
			// Re-read once so the promotion below sees the new arrivals.
			$byActor = $this->indexByActor($participantService->getParticipantsForRoom($room));
		}

		// Only ever remove people we are responsible for.
		foreach (array_intersect(array_diff($present, $target), $governed) as $userId) {
			$user = $this->userManager->get($userId);
			if ($user === null) {
				continue;
			}
			$participantService->removeUser($room, $user, self::REASON_REMOVED);
		}

		$this->promoteOrganisers($room, $appointment, $participantService, $byActor);
	}

	/**
	 * Organisers hand out the final details, so they need to be able to rename
	 * the room and pull someone in. The creator is already owner.
	 */
	/**
	 * @param array<string, Participant> $byActor
	 */
	private function promoteOrganisers(Room $room, Appointment $appointment, ParticipantService $participantService, array $byActor): void {
		foreach ($appointment->getOrganizersList() as $userId) {
			$participant = $byActor[$userId] ?? null;
			// Owner outranks moderator — do not demote the creator.
			if ($participant !== null && $participant->getAttendee()->getParticipantType() > self::PARTICIPANT_MODERATOR) {
				$participantService->updateParticipantType($room, $participant, self::PARTICIPANT_MODERATOR);
			}
		}
	}

	/**
	 * @param list<Participant> $participants
	 * @return array<string, Participant> User participants by user id
	 */
	private function indexByActor(array $participants): array {
		$byActor = [];
		foreach ($participants as $participant) {
			$attendee = $participant->getAttendee();
			if ($attendee->getActorType() === self::ACTOR_USERS) {
				$byActor[$attendee->getActorId()] = $participant;
			}
		}

		return $byActor;
	}

	/**
	 * The linked room, or null when it is gone. Somebody deleting the room in
	 * Talk is a normal end of life, not an anomaly — clear the token and let
	 * the UI offer a new room. Callers that write the appointment themselves
	 * pass $persist false, so one request never updates the row twice.
	 */
	private function resolveExistingRoom(Appointment $appointment, bool $persist = true): ?Room {
		$token = $appointment->getTalkRoomToken();
		if ($token === null) {
			return null;
		}

		$manager = $this->resolve(TalkManager::class);
		if ($manager === null) {
			return null;
		}

		try {
			return $manager->getRoomByToken($token);
		} catch (RoomNotFoundException) {
			$appointment->setTalkRoomToken(null);
			if ($persist) {
				$this->appointmentMapper->update($appointment);
			}
			return null;
		}
	}

	/**
	 * First organiser, falling back to the creator. Determines who owns the
	 * conversation — and on auto-close there is nobody clicking, so it has to
	 * be derived rather than taken from the session.
	 */
	private function resolveOwner(Appointment $appointment): ?IUser {
		foreach ($appointment->getOrganizersList() as $userId) {
			$user = $this->userManager->get($userId);
			if ($user !== null) {
				return $user;
			}
		}

		return $this->userManager->get($appointment->getCreatedBy());
	}

	/**
	 * "Probe (12.09.2026)" — the room outlives the appointment in a conversation
	 * list where a bare name says nothing about which date it belongs to.
	 */
	private function buildName(Appointment $appointment, IUser $owner): string {
		try {
			$start = new \DateTime($appointment->getStartDatetime(), new \DateTimeZone('UTC'));
		} catch (\Exception) {
			return mb_substr($appointment->getName(), 0, self::NAME_MAX_LENGTH);
		}

		$date = $this->dateTimeFormatter->formatDate(
			$start,
			'medium',
			$this->timezoneOf($owner),
			$this->ownerL10n($owner),
		);
		$suffix = ' (' . $date . ')';

		return mb_substr($appointment->getName(), 0, self::NAME_MAX_LENGTH - mb_strlen($suffix)) . $suffix;
	}

	/**
	 * Translated into the owner's language: a background job has no UI locale,
	 * and the description is stored once rather than rendered per reader.
	 */
	private function buildDescription(Appointment $appointment, IUser $owner): string {
		$l = $this->ownerL10n($owner);

		$url = $this->urlGenerator->linkToRouteAbsolute(
			'attendance.page.appointment',
			['id' => $appointment->getId()],
		);

		// Same distinction the UI hint makes: without planning nobody is
		// "scheduled", everyone who accepted is simply in.
		$intro = $this->configService->isBookingEnabled()
			? $l->t('Talk room for everyone scheduled into this appointment. Details: %1$s', [$url])
			: $l->t('Talk room for everyone who accepted this appointment. Details: %1$s', [$url]);

		$own = trim($appointment->getDescription() ?? '');
		if ($own === '') {
			return $intro;
		}

		// Talk rejects an over-long description outright, so a trimmed tail
		// beats no description at all.
		$separator = "\n\n";
		$ellipsis = '…';
		$available = self::DESCRIPTION_MAX_LENGTH - mb_strlen($intro) - mb_strlen($separator);
		if ($available <= mb_strlen($ellipsis)) {
			return $intro;
		}
		if (mb_strlen($own) > $available) {
			$own = mb_substr($own, 0, $available - mb_strlen($ellipsis)) . $ellipsis;
		}

		return $intro . $separator . $own;
	}

	/**
	 * The owner's timezone, for the same reason as their language: name and
	 * description are stored once, not rendered per reader.
	 */
	private function timezoneOf(IUser $owner): \DateTimeZone {
		try {
			$timezone = $this->userConfig->getValueString($owner->getUID(), 'core', 'timezone');

			return new \DateTimeZone($timezone !== '' ? $timezone : date_default_timezone_get());
		} catch (\Exception) {
			return new \DateTimeZone('UTC');
		}
	}

	private function ownerL10n(IUser $owner): IL10N {
		return $this->l10nFactory->get('attendance', $this->l10nFactory->getUserLanguage($owner));
	}

	/**
	 * Talk registers its services in its own app container, which only exists
	 * once the app is loaded — hence loadApp() before the lookup.
	 *
	 * Cached per request: a series edit reconciles one room per appointment, and
	 * every miss would load the app and walk the container again.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @return ?T
	 */
	private function resolve(string $class): ?object {
		if (array_key_exists($class, $this->resolved)) {
			/** @var ?T */
			return $this->resolved[$class];
		}

		$this->resolved[$class] = $this->load($class);

		/** @var ?T */
		return $this->resolved[$class];
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return ?T
	 */
	private function load(string $class): ?object {
		try {
			// Load first, then look: the class only becomes visible once Talk's
			// autoloader is registered.
			$this->appManager->loadApp(self::TALK_APP_ID);
			if (!class_exists($class)) {
				return null;
			}
			$instance = $this->container->get($class);

			return $instance instanceof $class ? $instance : null;
		} catch (\Throwable $e) {
			$this->logger->warning('Resolving a Talk service failed', [
				'app' => 'attendance',
				'class' => $class,
				'exception' => $e,
			]);
			return null;
		}
	}
}
