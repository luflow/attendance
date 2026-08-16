<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Manager as TalkManager;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\RoomService;
use OCP\App\IAppManager;
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
 * Membership is a confidentiality promise, not a convenience: the final details
 * are handed out in that room, so someone who loses their place has to leave
 * it. The reconcile is therefore authoritative — but only over people this app
 * knows about. Anyone a moderator added by hand in Talk is invisible to it and
 * never removed.
 */
class TalkRoomService {
	private const TALK_APP_ID = 'spreed';

	/**
	 * Talk constants, inlined rather than referenced: touching
	 * `OCA\Talk\Room::TYPE_GROUP` would autoload Talk even when it is absent.
	 * Verified against Talk stable32–stable34.
	 */
	private const ROOM_TYPE_GROUP = 2;          // Room::TYPE_GROUP
	private const OBJECT_TYPE_EVENT = 'event';  // Room::OBJECT_TYPE_EVENT
	private const ACTOR_USERS = 'users';        // Attendee::ACTOR_USERS
	private const PARTICIPANT_MODERATOR = 2;    // Participant::MODERATOR
	private const REASON_REMOVED = 'remove';    // AAttendeeRemovedEvent::REASON_REMOVED

	private ?bool $talkEnabled = null;

	public function __construct(
		private ContainerInterface $container,
		private IAppManager $appManager,
		private IUserManager $userManager,
		private IURLGenerator $urlGenerator,
		private IFactory $l10nFactory,
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
				// from cron, where there is no session user and the per-user
				// check would answer false on a server that has Talk.
				//
				// Deliberately no class_exists() here — Talk's autoloader is
				// only registered once its app is loaded, so the check would
				// answer false on a server that has Talk. resolve() loads the
				// app first and verifies the classes there.
				$this->talkEnabled = $this->appManager->isEnabledForAnyone(self::TALK_APP_ID);
			} catch (\Throwable $e) {
				$this->logger->debug('Checking Talk availability failed', ['app' => 'attendance', 'exception' => $e]);
				$this->talkEnabled = false;
			}
		}

		return $this->talkEnabled;
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

		// Nothing to talk about if the appointment has nobody in it.
		$targets = $this->targetUserIds($appointment);
		if ($targets === []) {
			return null;
		}

		try {
			$roomService = $this->getRoomService();
			if ($roomService === null) {
				return null;
			}

			// Named arguments on purpose: stable34 inserted `$attributes` into
			// the middle of this signature.
			$room = $roomService->createConversation(
				type: self::ROOM_TYPE_GROUP,
				name: mb_substr($appointment->getName(), 0, 255),
				owner: $owner,
				objectType: self::OBJECT_TYPE_EVENT,
				objectId: $this->meetingWindow($appointment),
			);

			// Persist the token before anything else can fail — a room we
			// created but did not record is a room nobody can find again.
			$appointment->setTalkRoomToken($room->getToken());
			$this->appointmentMapper->update($appointment);

			try {
				$description = $this->buildDescription($appointment, $owner->getUID());
				if ($description !== '') {
					$roomService->setDescription($room, $description);
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Setting the Talk conversation description failed', [
					'app' => 'attendance',
					'appointmentId' => $appointment->getId(),
					'exception' => $e,
				]);
			}

			$this->reconcile($room, $appointment, $owner);

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
	 * Bring the room membership back in line with who is scheduled. No-op
	 * without a linked room, so callers can fire it on every response change
	 * without checking first.
	 */
	public function syncParticipants(Appointment $appointment): void {
		if (!$this->isAvailable() || $appointment->getTalkRoomToken() === null) {
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
	 * Keep Talk's meeting window in step when the appointment moves.
	 */
	public function updateMeetingWindow(Appointment $appointment): void {
		if (!$this->isAvailable() || $appointment->getTalkRoomToken() === null) {
			return;
		}

		try {
			$room = $this->resolveExistingRoom($appointment);
			$roomService = $this->getRoomService();
			if ($room === null || $roomService === null) {
				return;
			}

			$window = $this->meetingWindow($appointment);
			if ($room->getObjectType() === self::OBJECT_TYPE_EVENT && $room->getObjectId() !== $window) {
				$roomService->setObject($room, self::OBJECT_TYPE_EVENT, $window);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Updating the Talk meeting window failed', [
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
	 * @return list<string>
	 */
	public function targetUserIds(Appointment $appointment): array {
		$bookingEnabled = $this->configService->isBookingEnabled();

		$targets = $appointment->getOrganizersList();
		$responses = $this->responseMapper->findByAppointment($appointment->getId());
		foreach ($responses as $response) {
			if ($response->getResponse() !== 'yes') {
				continue;
			}
			if ($bookingEnabled && $response->getBookingStatus() !== BookingService::STATUS_BOOKED) {
				continue;
			}
			$targets[] = $response->getUserId();
		}

		return array_values(array_unique($targets));
	}

	/**
	 * The people this app may act on: everyone who answered, plus the
	 * organisers. Whoever sits in the room outside this set was put there by a
	 * moderator and stays untouched.
	 *
	 * @return list<string>
	 */
	private function governedUserIds(Appointment $appointment): array {
		$governed = $appointment->getOrganizersList();
		$responses = $this->responseMapper->findByAppointment($appointment->getId());
		foreach ($responses as $response) {
			$governed[] = $response->getUserId();
		}

		return array_values(array_unique($governed));
	}

	private function reconcile(Room $room, Appointment $appointment, ?IUser $addedBy): void {
		$participantService = $this->getParticipantService();
		if ($participantService === null) {
			return;
		}

		$target = $this->targetUserIds($appointment);
		$governed = $this->governedUserIds($appointment);

		$present = [];
		foreach ($participantService->getParticipantsForRoom($room) as $participant) {
			$attendee = $participant->getAttendee();
			if ($attendee->getActorType() === self::ACTOR_USERS) {
				$present[] = $attendee->getActorId();
			}
		}

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
		}

		// Only ever remove people we are responsible for.
		foreach (array_intersect(array_diff($present, $target), $governed) as $userId) {
			$user = $this->userManager->get($userId);
			if ($user === null) {
				continue;
			}
			$participantService->removeUser($room, $user, self::REASON_REMOVED);
		}

		$this->promoteOrganisers($room, $appointment, $participantService);
	}

	/**
	 * Organisers hand out the final details, so they need to be able to rename
	 * the room and pull someone in. The creator is already owner.
	 */
	private function promoteOrganisers(Room $room, Appointment $appointment, ParticipantService $participantService): void {
		foreach ($appointment->getOrganizersList() as $userId) {
			try {
				$participant = $participantService->getParticipantByActor($room, self::ACTOR_USERS, $userId);
			} catch (ParticipantNotFoundException) {
				continue;
			}

			// Owner outranks moderator — do not demote the creator.
			if ($participant->getAttendee()->getParticipantType() > self::PARTICIPANT_MODERATOR) {
				$participantService->updateParticipantType($room, $participant, self::PARTICIPANT_MODERATOR);
			}
		}
	}

	/**
	 * The linked room, or null when it is gone. Talk deletes event rooms 28
	 * days after they end (`retention_event_rooms`), so a stale token is the
	 * normal end of life, not an anomaly — clear it and let the UI offer a new
	 * conversation.
	 */
	private function resolveExistingRoom(Appointment $appointment): ?Room {
		$token = $appointment->getTalkRoomToken();
		if ($token === null) {
			return null;
		}

		$manager = $this->getTalkManager();
		if ($manager === null) {
			return null;
		}

		try {
			return $manager->getRoomByToken($token);
		} catch (RoomNotFoundException) {
			$appointment->setTalkRoomToken(null);
			$this->appointmentMapper->update($appointment);
			return null;
		}
	}

	/**
	 * Talk reads the meeting time off the object id as `start#end`, both unix
	 * timestamps — the same shape spreed's own CalDavEventListener writes.
	 */
	private function meetingWindow(Appointment $appointment): string {
		$start = strtotime($appointment->getStartDatetime() . ' UTC');
		$end = strtotime($appointment->getEndDatetime() . ' UTC');

		return ($start === false ? 0 : $start) . '#' . ($end === false ? 0 : $end);
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
	 * Translated into the owner's language: a background job has no UI locale,
	 * and the description is stored once rather than rendered per reader.
	 */
	private function buildDescription(Appointment $appointment, string $ownerId): string {
		$language = $this->l10nFactory->getUserLanguage($this->userManager->get($ownerId));
		$l = $this->l10nFactory->get('attendance', $language);

		$url = $this->urlGenerator->linkToRouteAbsolute(
			'attendance.page.appointment',
			['id' => $appointment->getId()],
		);

		return $l->t('Conversation for everyone scheduled into this appointment. Details: %1$s', [$url]);
	}

	private function getRoomService(): ?RoomService {
		$service = $this->resolve(RoomService::class);

		return $service instanceof RoomService ? $service : null;
	}

	private function getParticipantService(): ?ParticipantService {
		$service = $this->resolve(ParticipantService::class);

		return $service instanceof ParticipantService ? $service : null;
	}

	private function getTalkManager(): ?TalkManager {
		$service = $this->resolve(TalkManager::class);

		return $service instanceof TalkManager ? $service : null;
	}

	/**
	 * Talk registers its services in its own app container, which only exists
	 * once the app is loaded — hence loadApp() before the lookup.
	 */
	private function resolve(string $class): ?object {
		if (!$this->isAvailable()) {
			return null;
		}

		try {
			// Load first, then look: the class only becomes visible once Talk's
			// autoloader is registered.
			$this->appManager->loadApp(self::TALK_APP_ID);
			if (!class_exists($class)) {
				return null;
			}
			$instance = $this->container->get($class);

			return is_object($instance) ? $instance : null;
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
