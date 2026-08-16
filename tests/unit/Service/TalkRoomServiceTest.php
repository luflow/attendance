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

		$this->service = new TalkRoomService(
			$this->container,
			$this->appManager,
			$this->userManager,
			$this->createMock(IURLGenerator::class),
			$l10nFactory,
			$this->appointmentMapper,
			$this->responseMapper,
			$this->configService,
			$logger,
		);
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
		$room->method('getObjectType')->willReturn('event');
		$room->method('getObjectId')->willReturn('0#0');

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
			$this->appointmentMapper,
			$this->responseMapper,
			$this->configService,
			$this->createMock(LoggerInterface::class),
		);

		$this->assertFalse($service->isAvailable());
		$this->assertNull($service->createForAppointment($this->appointment()));
	}

	/**
	 * With planning on, only the people who actually got a place belong in the
	 * room — a yes without a booking does not.
	 */
	public function testTargetsBookedPeopleWhenPlanningIsOn(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->responseMapper->method('findByAppointment')->willReturn([
			$this->response('alice', 'yes', 'booked'),
			$this->response('bob', 'yes', null),
			$this->response('carol', 'yes', 'declined'),
			$this->response('dave', 'no', null),
		]);

		$this->assertSame(['olivia', 'alice'], $this->service->targetUserIds($this->appointment()));
	}

	/**
	 * Without the planning feature there is no booked state to read, so a yes
	 * is the only commitment there is.
	 */
	public function testTargetsAllYesRespondersWhenPlanningIsOff(): void {
		$this->configService->method('isBookingEnabled')->willReturn(false);
		$this->responseMapper->method('findByAppointment')->willReturn([
			$this->response('alice', 'yes', null),
			$this->response('bob', 'yes', null),
			$this->response('carol', 'maybe', null),
			$this->response('dave', 'no', null),
		]);

		$this->assertSame(['olivia', 'alice', 'bob'], $this->service->targetUserIds($this->appointment()));
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
				'Rehearsal',
				$this->callback(fn (IUser $owner) => $owner->getUID() === 'olivia'),
			)
			->willReturn($this->room());

		$appointment = $this->appointment();
		$this->appointmentMapper->expects($this->atLeastOnce())->method('update');

		$this->assertSame('tok123', $this->service->createForAppointment($appointment));
		$this->assertSame('tok123', $appointment->getTalkRoomToken());
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

	public function testOrganisersBecomeModerators(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->responseMapper->method('findByAppointment')->willReturn([]);

		$room = $this->room();
		$this->talkManager->method('getRoomByToken')->willReturn($room);

		// participantType 3 = plain user, so the co-organiser needs promoting.
		$participants = $this->participants(['olivia', 'oscar']);
		$this->participantService->method('getParticipantsForRoom')->willReturn($participants);
		$this->participantService->method('getParticipantByActor')->willReturnCallback(
			fn (Room $r, string $type, string $id) => $participants[$id === 'olivia' ? 0 : 1],
		);

		$this->participantService->expects($this->exactly(2))
			->method('updateParticipantType')
			->with($room, $this->anything(), 2);

		$this->service->syncParticipants($this->appointment(['olivia', 'oscar'], 'tok123'));
	}
}
