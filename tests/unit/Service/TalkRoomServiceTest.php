<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\TalkRoomService;
use OCA\Talk\Manager as TalkManager;
use OCA\Talk\Model\Attendee;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class TalkRoomServiceTest extends TestCase {
	private ContainerInterface|MockObject $container;
	private IAppManager|MockObject $appManager;
	private IUserManager|MockObject $userManager;
	private AppointmentMapper|MockObject $appointmentMapper;
	private AttendanceResponseMapper|MockObject $responseMapper;
	private ConfigService|MockObject $configService;
	private IUserConfig|MockObject $config;
	private string $userTimezone = '';
	private RoomService|MockObject $roomService;
	private ParticipantService|MockObject $participantService;
	private TalkManager|MockObject $talkManager;
	private TalkRoomService $service;

	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);
		$this->configService = $this->createMock(ConfigService::class);

		$this->roomService = $this->createMock(RoomService::class);
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->talkManager = $this->createMock(TalkManager::class);

		$this->appManager->method('isEnabledForAnyone')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->container->method('get')->willReturnCallback(fn (string $class) => match ($class) {
			RoomService::class => $this->roomService,
			ParticipantService::class => $this->participantService,
			TalkManager::class => $this->talkManager,
			default => throw new \RuntimeException('unexpected ' . $class),
		});

		// Any user id resolves to a user, unless a test says otherwise.
		$this->userManager->method('get')->willReturnCallback(function (string $uid): IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn(ucfirst($uid));
			return $user;
		});

		// The service swallows its own failures so a Talk hiccup never breaks a
		// request. That would also swallow genuine breakage in these tests, so
		// here a logged error fails the test instead.
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
			$exception = $context['exception'] ?? null;
			$this->fail($message . ': ' . ($exception instanceof \Throwable ? $exception->getMessage() : 'no exception'));
		});

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $parameters = []) => vsprintf($text, $parameters));
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);

		// No timezone set, unless a test says otherwise.
		$this->userTimezone = '';
		$this->config = $this->createMock(IUserConfig::class);
		$this->config->method('getValueString')->willReturnCallback(fn () => $this->userTimezone);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/attendance/appointment/7');

		$this->service = new TalkRoomService(
			$this->container,
			$this->appManager,
			$this->userManager,
			$urlGenerator,
			$l10nFactory,
			$this->config,
			$this->dateTimeFormatter(),
			$this->appointmentMapper,
			$this->responseMapper,
			$this->configService,
			$logger,
		);
	}

	/**
	 * Formats German-style, and honours the timezone it is handed — the two
	 * properties the room name depends on.
	 */
	private function dateTimeFormatter(): IDateTimeFormatter|MockObject {
		$formatter = $this->createMock(IDateTimeFormatter::class);
		$formatter->method('formatDate')->willReturnCallback(
			function (\DateTime $timestamp, string $format = 'long', ?\DateTimeZone $timeZone = null): string {
				$date = clone $timestamp;
				if ($timeZone !== null) {
					$date->setTimezone($timeZone);
				}

				return $date->format('d.m.Y');
			},
		);

		return $formatter;
	}

	private function appointment(array $organizers = ['olivia'], ?string $token = null): Appointment {
		$appointment = new Appointment();
		$appointment->setId(7);
		$appointment->setName('Rehearsal');
		$appointment->setStartDatetime('2026-09-01 18:00:00');
		$appointment->setEndDatetime('2026-09-01 20:00:00');
		$appointment->setCreatedBy('olivia');
		$appointment->setOrganizers(json_encode($organizers));
		$appointment->setTalkRoomToken($token);

		return $appointment;
	}

	private function response(string $userId, string $answer, ?string $bookingStatus = null): AttendanceResponse {
		$response = new AttendanceResponse();
		$response->setUserId($userId);
		$response->setResponse($answer);
		$response->setBookingStatus($bookingStatus);

		return $response;
	}

	private function room(string $token = 'tok123'): Room|MockObject {
		$room = $this->createMock(Room::class);
		$room->method('getToken')->willReturn($token);

		return $room;
	}

	/**
	 * @param list<string> $userIds
	 */
	private function participants(array $userIds, int $participantType = 3): array {
		return array_map(function (string $userId) use ($participantType): Participant {
			$attendee = $this->createMock(Attendee::class);
			$attendee->method('getActorType')->willReturn('users');
			$attendee->method('getActorId')->willReturn($userId);
			$attendee->method('getParticipantType')->willReturn($participantType);

			$participant = $this->createMock(Participant::class);
			$participant->method('getAttendee')->willReturn($attendee);

			return $participant;
		}, $userIds);
	}

	public function testUnavailableWithoutTalk(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturn(false);

		$service = new TalkRoomService(
			$this->container,
			$appManager,
			$this->userManager,
			$this->createMock(IURLGenerator::class),
			$this->createMock(IFactory::class),
			$this->config,
			$this->dateTimeFormatter(),
			$this->appointmentMapper,
			$this->responseMapper,
			$this->configService,
			$this->createMock(LoggerInterface::class),
		);

		$this->assertFalse($service->isAvailable());
		$this->assertNull($service->createForAppointment($this->appointment()));
	}

	/**
	 * Whoever ends up being invited when the room is created — the observable
	 * form of "who belongs in here".
	 *
	 * @return list<string>
	 */
	private function invitedOnCreate(): array {
		$this->participantService->method('getParticipantsForRoom')->willReturn($this->participants(['olivia']));
		$this->roomService->method('createConversation')->willReturn($this->room());

		$invited = [];
		$this->participantService->method('addUsers')->willReturnCallback(
			function (Room $room, array $participants) use (&$invited): array {
				$invited = array_column($participants, 'actorId');
				return [];
			},
		);

		$this->service->createForAppointment($this->appointment());

		return $invited;
	}

	/**
	 * With planning on, only the people who actually got a place belong in the
	 * room — a yes without a booking does not.
	 */
	public function testInvitesBookedPeopleWhenPlanningIsOn(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->responseMapper->method('findByAppointment')->willReturn([
			$this->response('alice', 'yes', 'booked'),
			$this->response('bob', 'yes', null),
			$this->response('carol', 'yes', 'declined'),
			$this->response('dave', 'no', null),
		]);

		$this->assertSame(['alice'], $this->invitedOnCreate());
	}

	/**
	 * Without the planning feature there is no booked state to read, so a yes
	 * is the only commitment there is.
	 */
	public function testInvitesAllYesRespondersWhenPlanningIsOff(): void {
		$this->configService->method('isBookingEnabled')->willReturn(false);
		$this->responseMapper->method('findByAppointment')->willReturn([
			$this->response('alice', 'yes', null),
			$this->response('bob', 'yes', null),
			$this->response('carol', 'maybe', null),
			$this->response('dave', 'no', null),
		]);

		$this->assertSame(['alice', 'bob'], $this->invitedOnCreate());
	}

	/**
	 * A plain group room, deliberately not a Talk "event" room: all three
	 * clients hide event rooms from the conversation list until 16 hours before
	 * the start, and the room is opened when the inquiry closes — often weeks
	 * ahead, which is exactly when people start using it.
	 */
	public function testCreatesPlainGroupConversationAndStoresToken(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->responseMapper->method('findByAppointment')->willReturn([
			$this->response('alice', 'yes', 'booked'),
		]);
		$this->participantService->method('getParticipantsForRoom')->willReturn($this->participants(['olivia']));

		$this->roomService->expects($this->once())
			->method('createConversation')
			->with(
				2,            // group, not public — there is no link to pass around
				'Rehearsal (01.09.2026)',
				$this->callback(fn (IUser $owner) => $owner->getUID() === 'olivia'),
			)
			->willReturn($this->room());

		$appointment = $this->appointment();
		$this->appointmentMapper->expects($this->atLeastOnce())->method('update');

		$this->assertSame('tok123', $this->service->createForAppointment($appointment));
		$this->assertSame('tok123', $appointment->getTalkRoomToken());
	}

	/**
	 * The name and description the room is created with.
	 *
	 * @return array{name: string, description: string}
	 */
	private function createdLabels(Appointment $appointment): array {
		$this->responseMapper->method('findByAppointment')->willReturn([$this->response('alice', 'yes', null)]);
		$this->participantService->method('getParticipantsForRoom')->willReturn($this->participants(['olivia']));

		$name = '';
		$this->roomService->method('createConversation')->willReturnCallback(
			function (int $type, string $roomName) use (&$name): Room {
				$name = $roomName;

				return $this->room();
			},
		);

		$description = '';
		$this->roomService->method('setDescription')->willReturnCallback(
			function (Room $room, string $text) use (&$description): void {
				$description = $text;
			},
		);

		$this->service->createForAppointment($appointment);

		return ['name' => $name, 'description' => $description];
	}

	/**
	 * The date tells the rooms of a series apart; a conversation list full of
	 * "Rehearsal" does not.
	 */
	public function testRoomNameCarriesTheStartDate(): void {
		$this->assertSame('Rehearsal (01.09.2026)', $this->createdLabels($this->appointment())['name']);
	}

	/**
	 * 23:00 UTC is the next day in Berlin, and the name is written once — in
	 * the owner's timezone, for the same reason it is in their language.
	 */
	public function testRoomNameFollowsTheOwnersTimezone(): void {
		$this->userTimezone = 'Europe/Berlin';
		$appointment = $this->appointment();
		$appointment->setStartDatetime('2026-09-01 23:00:00');

		$this->assertSame('Rehearsal (02.09.2026)', $this->createdLabels($appointment)['name']);
	}

	/**
	 * The name is capped, and the date is the part that has to survive.
	 */
	public function testRoomNameKeepsTheDateWhenTheNameIsTooLong(): void {
		$appointment = $this->appointment();
		$appointment->setName(str_repeat('a', 300));

		$name = $this->createdLabels($appointment)['name'];

		$this->assertSame(255, mb_strlen($name));
		$this->assertStringEndsWith(' (01.09.2026)', $name);
	}

	/**
	 * What the appointment says is what the people in the room need: the
	 * description travels into the room rather than only sitting behind a link.
	 */
	public function testDescriptionCarriesTheAppointmentDescription(): void {
		$appointment = $this->appointment();
		$appointment->setDescription("Bring your own music stand.\nWe start on time.");

		$description = $this->createdLabels($appointment)['description'];

		$this->assertStringContainsString('everyone who accepted', $description);
		$this->assertStringContainsString('https://cloud.example/apps/attendance/appointment/7', $description);
		$this->assertStringContainsString("Bring your own music stand.\nWe start on time.", $description);
	}

	public function testDescriptionIsJustTheIntroWhenTheAppointmentHasNone(): void {
		$description = $this->createdLabels($this->appointment())['description'];

		$this->assertStringContainsString('everyone who accepted', $description);
		$this->assertStringNotContainsString("\n\n", $description);
	}

	/**
	 * Talk rejects anything over 2000 characters outright, which would leave
	 * the room with no description at all.
	 */
	public function testDescriptionStaysWithinTalksLimit(): void {
		$appointment = $this->appointment();
		$appointment->setDescription(str_repeat('b', 2500));

		$description = $this->createdLabels($appointment)['description'];

		$this->assertLessThanOrEqual(2000, mb_strlen($description));
		$this->assertStringEndsWith('…', $description);
	}

	/**
	 * An edited appointment is the same appointment — a room still carrying
	 * last week's date and the old briefing is worse than no detail at all.
	 */
	public function testEditingTheAppointmentRewritesNameAndDescription(): void {
		$this->talkManager->method('getRoomByToken')->willReturn($this->room());

		$appointment = $this->appointment(token: 'tok123');
		$appointment->setName('Dress rehearsal');
		$appointment->setStartDatetime('2026-09-08 18:00:00');
		$appointment->setDescription('Now with the band.');

		$this->roomService->expects($this->once())
			->method('setName')
			->with($this->anything(), 'Dress rehearsal (08.09.2026)');
		$this->roomService->expects($this->once())
			->method('setDescription')
			->with($this->anything(), $this->stringContains('Now with the band.'));

		$this->service->syncRoomDetails($appointment);
	}

	public function testDetailSyncIsANoOpWithoutALinkedRoom(): void {
		$this->roomService->expects($this->never())->method('setName');
		$this->roomService->expects($this->never())->method('setDescription');

		$this->service->syncRoomDetails($this->appointment());
	}

	public function testDoesNotCreateASecondRoomForALinkedAppointment(): void {
		$this->talkManager->method('getRoomByToken')->willReturn($this->room());
		$this->roomService->expects($this->never())->method('createConversation');

		$this->assertSame('tok123', $this->service->createForAppointment($this->appointment(token: 'tok123')));
	}

	/**
	 * The heart of the feature: losing a place closes the conversation behind
	 * you, and gaining one lets you in.
	 */
	public function testSyncAddsScheduledAndRemovesUnscheduled(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->responseMapper->method('findByAppointment')->willReturn([
			$this->response('alice', 'yes', 'booked'),
			$this->response('bob', 'yes', null),
		]);

		$room = $this->room();
		$this->talkManager->method('getRoomByToken')->willReturn($room);
		// bob is still in the room but lost his place; alice is not in yet.
		$this->participantService->method('getParticipantsForRoom')->willReturn($this->participants(['olivia', 'bob']));

		$this->participantService->expects($this->once())
			->method('addUsers')
			->with($room, [[
				'actorType' => 'users',
				'actorId' => 'alice',
				'displayName' => 'Alice',
			]], $this->anything());

		$this->participantService->expects($this->once())
			->method('removeUser')
			->with($room, $this->callback(fn (IUser $user) => $user->getUID() === 'bob'), 'remove');

		$this->service->syncParticipants($this->appointment(token: 'tok123'));
	}

	/**
	 * Someone a moderator pulled in by hand has no response on the
	 * appointment, so the reconcile must leave them alone however
	 * authoritative it otherwise is.
	 */
	public function testSyncLeavesManuallyAddedPeopleAlone(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->responseMapper->method('findByAppointment')->willReturn([
			$this->response('alice', 'yes', 'booked'),
		]);

		$this->talkManager->method('getRoomByToken')->willReturn($this->room());
		$this->participantService->method('getParticipantsForRoom')
			->willReturn($this->participants(['olivia', 'alice', 'stranger']));

		$this->participantService->expects($this->never())->method('removeUser');

		$this->service->syncParticipants($this->appointment(token: 'tok123'));
	}

	/**
	 * The token tells a viewer a room exists. Only members may be told — Talk
	 * refuses everyone else, so the badge would link into a wall.
	 */
	public function testOnlyMembersMayBeToldTheRoomExists(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);

		// Organisers and managers are in regardless of their own answer.
		$this->assertTrue($this->service->belongsInRoom(true, null));
		$this->assertTrue($this->service->belongsInRoom(true, $this->response('bob', 'no', null)));

		// Everyone else needs a place.
		$this->assertTrue($this->service->belongsInRoom(false, $this->response('alice', 'yes', 'booked')));
		$this->assertFalse($this->service->belongsInRoom(false, $this->response('bob', 'yes', null)));
		$this->assertFalse($this->service->belongsInRoom(false, $this->response('carol', 'no', null)));
		$this->assertFalse($this->service->belongsInRoom(false, null));
	}

	/**
	 * With planning off a yes is the whole commitment, so it also settles who
	 * may be told about the room.
	 */
	public function testAnyYesResponderMayBeToldWhenPlanningIsOff(): void {
		$this->configService->method('isBookingEnabled')->willReturn(false);

		$this->assertTrue($this->service->belongsInRoom(false, $this->response('bob', 'yes', null)));
		$this->assertFalse($this->service->belongsInRoom(false, $this->response('carol', 'maybe', null)));
	}

	/**
	 * With planning on, who holds a place is only settled at the close, so the
	 * room waits. Without planning it must not wait: auto-close fires at the
	 * appointment's start, which is far too late to agree on where to meet.
	 */
	public function testWaitsForTheCloseOnlyWhilePlanningIsOn(): void {
		$open = $this->appointment();
		$closed = $this->appointment();
		$closed->setClosedAt('2026-08-16 12:00:00');

		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->assertFalse($this->service->mayOpenRoom($open));
		$this->assertTrue($this->service->mayOpenRoom($closed));
	}

	public function testOpensBeforeTheCloseWhenPlanningIsOff(): void {
		$this->configService->method('isBookingEnabled')->willReturn(false);

		$this->assertTrue($this->service->mayOpenRoom($this->appointment()));
	}

	/**
	 * A room holding nobody but the organisers is not a conversation — it waits
	 * for the first acceptance.
	 */
	public function testDoesNotOpenForOrganisersAlone(): void {
		$this->configService->method('isBookingEnabled')->willReturn(false);
		$this->responseMapper->method('findByAppointment')->willReturn([]);
		$this->roomService->expects($this->never())->method('createConversation');

		$this->assertNull($this->service->createForAppointment($this->appointment()));
	}

	/**
	 * openOrSync() is the single call every membership-changing write makes: it
	 * opens the room when the appointment opted in and may have one, and
	 * reconciles an existing one otherwise.
	 */
	public function testOpenOrSyncOpensTheRoomOnFirstAcceptance(): void {
		$this->configService->method('isBookingEnabled')->willReturn(false);
		$this->responseMapper->method('findByAppointment')->willReturn([
			$this->response('alice', 'yes', null),
		]);
		$this->participantService->method('getParticipantsForRoom')->willReturn($this->participants(['olivia']));

		$appointment = $this->appointment();
		$appointment->setCreateTalkRoom(true);

		$this->roomService->expects($this->once())
			->method('createConversation')
			->willReturn($this->room());

		$this->service->openOrSync($appointment);

		$this->assertSame('tok123', $appointment->getTalkRoomToken());
	}

	public function testOpenOrSyncStaysQuietWithoutTheOptIn(): void {
		$this->configService->method('isBookingEnabled')->willReturn(false);
		$this->roomService->expects($this->never())->method('createConversation');

		$this->service->openOrSync($this->appointment());
	}

	public function testSyncIsANoOpWithoutALinkedRoom(): void {
		$this->talkManager->expects($this->never())->method('getRoomByToken');
		$this->participantService->expects($this->never())->method('getParticipantsForRoom');

		$this->service->syncParticipants($this->appointment());
	}

	/**
	 * Talk deletes event rooms 28 days after they end, so a token pointing at
	 * nothing is the normal end of life — clear it and let the UI offer a new
	 * conversation.
	 */
	public function testClearsTheTokenWhenTheRoomIsGone(): void {
		$this->talkManager->method('getRoomByToken')
			->willThrowException(new \OCA\Talk\Exceptions\RoomNotFoundException());

		$appointment = $this->appointment(token: 'gone');
		$this->appointmentMapper->expects($this->once())->method('update');

		$this->service->syncParticipants($appointment);

		$this->assertNull($appointment->getTalkRoomToken());
	}

	public function testDeleteRemovesTheRoomAndTheOptIn(): void {
		$room = $this->room();
		$this->talkManager->method('getRoomByToken')->willReturn($room);
		$this->roomService->expects($this->once())->method('deleteRoom')->with($room);

		$appointment = $this->appointment(token: 'tok123');
		$appointment->setCreateTalkRoom(true);

		$this->assertTrue($this->service->deleteForAppointment($appointment));
		$this->assertNull($appointment->getTalkRoomToken());
		$this->assertFalse($appointment->getCreateTalkRoom());
	}

	public function testDeleteIsANoOpWithoutALinkedRoom(): void {
		$this->roomService->expects($this->never())->method('deleteRoom');

		$this->assertFalse($this->service->deleteForAppointment($this->appointment()));
	}

	public function testDeleteSucceedsWhenTheRoomIsAlreadyGone(): void {
		$this->talkManager->method('getRoomByToken')
			->willThrowException(new \OCA\Talk\Exceptions\RoomNotFoundException());
		$this->roomService->expects($this->never())->method('deleteRoom');

		$appointment = $this->appointment(token: 'gone');
		$appointment->setCreateTalkRoom(true);
		// One write, not one per place that clears the link.
		$this->appointmentMapper->expects($this->once())->method('update');

		$this->assertTrue($this->service->deleteForAppointment($appointment));
		$this->assertNull($appointment->getTalkRoomToken());
		$this->assertFalse($appointment->getCreateTalkRoom());
	}

	public function testOrganisersBecomeModerators(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->responseMapper->method('findByAppointment')->willReturn([]);

		$room = $this->room();
		$this->talkManager->method('getRoomByToken')->willReturn($room);

		// participantType 3 = plain user, so the co-organiser needs promoting.
		$participants = $this->participants(['olivia', 'oscar']);
		$this->participantService->method('getParticipantsForRoom')->willReturn($participants);
		$this->participantService->expects($this->exactly(2))
			->method('updateParticipantType')
			->with($room, $this->anything(), 2);

		$this->service->syncParticipants($this->appointment(['olivia', 'oscar'], 'tok123'));
	}
}
