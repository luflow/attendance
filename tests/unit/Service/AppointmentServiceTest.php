<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Audit\Verb;
use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Db\Category;
use OCA\Attendance\Db\CategoryMapper;
use OCA\Attendance\Service\AppointmentListQuery;
use OCA\Attendance\Service\AppointmentSerializer;
use OCA\Attendance\Service\AppointmentService;
use OCA\Attendance\Service\AttachmentService;
use OCA\Attendance\Service\AuditEventService;
use OCA\Attendance\Service\BookingService;
use OCA\Attendance\Service\CapacityService;
use OCA\Attendance\Service\CheckinService;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\DeadlineUpdate;
use OCA\Attendance\Service\GuestService;
use OCA\Attendance\Service\NotificationService;
use OCA\Attendance\Service\OrgCalendarSyncService;
use OCA\Attendance\Service\PermissionService;
use OCA\Attendance\Service\ResponsePolicyService;
use OCA\Attendance\Service\ResponseService;
use OCA\Attendance\Service\ResponseSummaryService;
use OCA\Attendance\Service\TalkRoomService;
use OCA\Attendance\Service\TimezoneService;
use OCA\Attendance\Service\VacationService;
use OCA\Attendance\Service\VisibilityService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Config\IUserConfig;
use OCP\IDateTimeZone;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AppointmentServiceTest extends TestCase {
	/** @var AppointmentMapper|MockObject */
	private $appointmentMapper;

	/** @var AttendanceResponseMapper|MockObject */
	private $responseMapper;

	/** @var IGroupManager|MockObject */
	private $groupManager;

	/** @var IUserManager|MockObject */
	private $userManager;

	/** @var ConfigService|MockObject */
	private $configService;

	/** @var VisibilityService|MockObject */
	private $visibilityService;

	/** @var ResponseSummaryService|MockObject */
	private $responseSummaryService;

	/** @var NotificationService|MockObject */
	private $notificationService;

	/** @var AttachmentService|MockObject */
	private $attachmentService;

	/** @var GuestService|MockObject */
	private $guestService;

	/** @var AuditEventService|MockObject */
	private $auditEventService;

	/** @var BookingService|MockObject */
	private $bookingService;

	/** @var PermissionService|MockObject */
	private $permissionService;

	/** @var OrgCalendarSyncService|MockObject */
	private $orgCalendarSyncService;

	/** @var CategoryMapper|MockObject */
	private $categoryMapper;
	private $talkRoomService;

	private ResponsePolicyService $responsePolicyService;
	private CapacityService $capacityService;
	/** @var VacationService|MockObject */
	private $vacationService;
	/** @var CheckinService|MockObject */
	private $checkinService;

	private AppointmentService $service;

	protected function setUp(): void {
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->visibilityService = $this->createMock(VisibilityService::class);
		$this->responseSummaryService = $this->createMock(ResponseSummaryService::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->attachmentService = $this->createMock(AttachmentService::class);
		$this->guestService = $this->createMock(GuestService::class);
		$this->auditEventService = $this->createMock(AuditEventService::class);
		$this->bookingService = $this->createMock(BookingService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->orgCalendarSyncService = $this->createMock(OrgCalendarSyncService::class);
		$this->categoryMapper = $this->createMock(CategoryMapper::class);
		$this->talkRoomService = $this->createMock(TalkRoomService::class);
		// The real policy, not a mock: the guard is the thing these tests need to
		// see fire. Appointments offer all three answers unless one opts out.
		$this->configService->method('isMaybeAllowed')->willReturn(true);
		$this->capacityService = new CapacityService(
			$this->responseMapper,
			$this->notificationService,
			$this->auditEventService,
		);
		$this->responsePolicyService = new ResponsePolicyService($this->configService, $this->capacityService, $this->visibilityService);
		$this->vacationService = $this->createMock(VacationService::class);
		$this->checkinService = $this->createMock(CheckinService::class);

		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('getValueString')->willReturn('Europe/Berlin');
		$timezoneService = new TimezoneService($userConfig, $this->createMock(IDateTimeZone::class));

		$this->service = new AppointmentService(
			$this->appointmentMapper,
			$this->responseMapper,
			$this->groupManager,
			$this->userManager,
			$this->configService,
			$this->visibilityService,
			$this->responseSummaryService,
			$this->notificationService,
			$this->attachmentService,
			$this->guestService,
			$this->auditEventService,
			$this->bookingService,
			$this->permissionService,
			$this->orgCalendarSyncService,
			$this->categoryMapper,
			$this->talkRoomService,
			$this->responsePolicyService,
			$this->capacityService,
			new AppointmentSerializer($this->responsePolicyService, $this->capacityService),
			$this->vacationService,
			$timezoneService,
			$this->checkinService,
		);
	}

	public function testCreateAppointment(): void {
		$name = 'Team Meeting';
		$description = 'Weekly sync';
		$startDatetime = '2024-01-15T10:00:00Z';
		$endDatetime = '2024-01-15T11:00:00Z';
		$createdBy = 'admin';

		$appointment = new Appointment();
		$appointment->setId(1);

		$this->appointmentMapper->expects($this->once())
			->method('insert')
			->willReturn($appointment);

		$this->auditEventService->expects($this->once())
			->method('recordAppointmentLifecycle')
			->with(Verb::APPOINTMENT_CREATED, 1, Verb::SOURCE_CLIENT);

		$result = $this->service->createAppointment(
			$name,
			$description,
			$startDatetime,
			$endDatetime,
			$createdBy
		);

		$this->assertInstanceOf(Appointment::class, $result);
		$this->assertEquals(1, $result->getId());
	}

	public function testCreateAppointmentAutoRespondsNoForInviteesOnVacation(): void {
		$appointment = new Appointment();
		$appointment->setId(7);
		$appointment->setStartDatetime('2026-06-01 10:00:00');
		$appointment->setEndDatetime('2026-06-01 12:00:00');
		$appointment->setVisibleUsers(json_encode(['alice', 'bob']));

		$this->appointmentMapper->method('insert')->willReturn($appointment);

		$this->vacationService->expects($this->once())
			->method('findUsersOnVacation')
			->with('2026-06-01 10:00:00', '2026-06-01 12:00:00')
			->willReturn(['bob']);

		$this->responseMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (AttendanceResponse $response): bool {
				return $response->getAppointmentId() === 7
					&& $response->getUserId() === 'bob'
					&& $response->getResponse() === 'no'
					&& $response->getResponseSource() === ResponseService::SOURCE_VACATION;
			}))
			->willReturnArgument(0);

		// Logged per person, as its own source, with the person as actor.
		$this->auditEventService->expects($this->once())->method('recordResponseChange')
			->with(7, 'bob', null, '', 'no', '', Verb::SOURCE_VACATION);

		$this->service->createAppointment(
			'Trip',
			'',
			'2026-06-01T10:00:00Z',
			'2026-06-01T12:00:00Z',
			'admin',
			['alice', 'bob'],
		);
	}

	/** Berlin's 1 June starts on 31 May in UTC; the vacation check must not see that day. */
	public function testCreateAllDayAppointmentMatchesVacationsByItsOwnDays(): void {
		$this->appointmentMapper->method('insert')->willReturnCallback(function (Appointment $appointment) {
			$appointment->setId(9);
			return $appointment;
		});

		$this->vacationService->expects($this->once())
			->method('findUsersOnVacation')
			->with('2026-06-01', '2026-06-01')
			->willReturn([]);

		$created = $this->service->createAppointment(
			'Trip',
			'',
			'2026-05-31T22:00:00Z',
			'2026-06-01T22:00:00Z',
			'admin',
			isAllDay: true,
		);

		$this->assertTrue($created->isAllDay());
		$this->assertSame('2026-05-31 22:00:00', $created->getStartDatetime());
	}

	public function testCreateAppointmentLeavesVacationersOutsideTheAudienceAlone(): void {
		$appointment = new Appointment();
		$appointment->setId(8);
		$appointment->setVisibleUsers(json_encode(['alice']));

		$this->appointmentMapper->method('insert')->willReturn($appointment);
		$this->vacationService->method('findUsersOnVacation')->willReturn(['carol']);
		$this->responseMapper->expects($this->never())->method('insert');
		$this->auditEventService->expects($this->never())->method('recordResponseChange');

		$this->service->createAppointment(
			'Trip',
			'',
			'2026-06-01T10:00:00Z',
			'2026-06-01T12:00:00Z',
			'admin',
			['alice'],
		);
	}

	public function testCreateAppointmentSkipsAudienceLookupWhenNobodyIsOnVacation(): void {
		$appointment = new Appointment();
		$appointment->setId(9);

		$this->appointmentMapper->method('insert')->willReturn($appointment);
		$this->vacationService->method('findUsersOnVacation')->willReturn([]);

		// The common case must not pay for enumerating the whole whitelist.
		$this->userManager->expects($this->never())->method('search');
		$this->responseMapper->expects($this->never())->method('insert');

		$this->service->createAppointment(
			'Nobody away',
			'',
			'2024-01-15T10:00:00Z',
			'2024-01-15T11:00:00Z',
			'admin',
		);
	}

	private function upcomingAppointment(int $id): Appointment {
		$appointment = new Appointment();
		$appointment->setId($id);
		$appointment->setStartDatetime('2030-06-03 10:00:00');
		$appointment->setEndDatetime('2030-06-03 12:00:00');
		return $appointment;
	}

	public function testAnswerNoDuringVacationAnswersOpenAppointmentsInTheWindow(): void {
		$appointment = $this->upcomingAppointment(11);
		$this->appointmentMapper->expects($this->once())->method('findUpcomingOverlapping')
			->with('2030-06-01 00:00:00', '2030-06-10 23:59:59')
			->willReturn([$appointment]);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);
		$this->responseMapper->method('findByAppointmentAndUser')
			->willThrowException(new DoesNotExistException('no answer yet'));

		$this->responseMapper->expects($this->once())->method('insert')
			->with($this->callback(function (AttendanceResponse $response): bool {
				return $response->getAppointmentId() === 11
					&& $response->getUserId() === 'alice'
					&& $response->getResponse() === 'no'
					&& $response->getResponseSource() === ResponseService::SOURCE_VACATION;
			}))
			->willReturnArgument(0);

		$this->auditEventService->expects($this->once())->method('recordResponseChange')
			->with(11, 'alice', null, '', 'no', '', Verb::SOURCE_VACATION, null);

		$this->assertSame(1, $this->service->answerNoDuringVacation('alice', '2030-06-01', '2030-06-10'));
	}

	public function testAnswerNoDuringVacationLeavesAnAllDayAppointmentOnTheDayAfterAlone(): void {
		// 11 June in Berlin, which reaches back into the vacation's last UTC day
		$appointment = $this->upcomingAppointment(16);
		$appointment->setStartDatetime('2030-06-10 22:00:00');
		$appointment->setEndDatetime('2030-06-11 22:00:00');
		$appointment->setAllDay(true);
		$this->appointmentMapper->method('findUpcomingOverlapping')->willReturn([$appointment]);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);

		$this->responseMapper->expects($this->never())->method('insert');

		$this->assertSame(0, $this->service->answerNoDuringVacation('alice', '2030-06-01', '2030-06-10'));
	}

	public function testAnswerNoDuringVacationKeepsAnswersAlreadyGiven(): void {
		$this->appointmentMapper->method('findUpcomingOverlapping')->willReturn([$this->upcomingAppointment(12)]);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);
		$given = new AttendanceResponse();
		$given->setResponse('yes');
		$this->responseMapper->method('findByAppointmentAndUser')->willReturn($given);

		$this->responseMapper->expects($this->never())->method('insert');
		$this->responseMapper->expects($this->never())->method('update');

		$this->assertSame(0, $this->service->answerNoDuringVacation('alice', '2030-06-01', '2030-06-10'));
	}

	public function testAnswerNoDuringVacationSkipsClosedCancelledAndForeignAppointments(): void {
		$closed = $this->upcomingAppointment(13);
		$closed->setClosedAt('2030-01-01 00:00:00');
		$cancelled = $this->upcomingAppointment(14);
		$cancelled->setCancelledAt('2030-01-01 00:00:00');
		$foreign = $this->upcomingAppointment(15);

		$this->appointmentMapper->method('findUpcomingOverlapping')->willReturn([$closed, $cancelled, $foreign]);
		// Only the third would be considered, and alice is not in its audience.
		$this->visibilityService->expects($this->once())->method('isUserTargetAttendee')->willReturn(false);
		$this->responseMapper->expects($this->never())->method('insert');

		$this->assertSame(0, $this->service->answerNoDuringVacation('alice', '2030-06-01', '2030-06-10'));
	}

	public function testPreviewAudienceResolvesADraftSelection(): void {
		$this->assertSame(['alice', 'bob'], $this->service->previewAudience(['alice', 'bob'], [], []));
	}

	public function testCloseAppointmentRecordsAuditEvent(): void {
		$appointment = new Appointment();
		$appointment->setId(5);

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with(5)
			->willReturn($appointment);

		$this->appointmentMapper->expects($this->once())
			->method('update')
			->willReturnArgument(0);

		$this->auditEventService->expects($this->once())
			->method('recordAppointmentLifecycle')
			->with(Verb::APPOINTMENT_CLOSED, 5, Verb::SOURCE_CLIENT);

		$result = $this->service->closeAppointment(5);
		$this->assertNotNull($result->getClosedAt());
	}

	public function testCloseAppointmentIsIdempotentForAlreadyClosed(): void {
		$appointment = new Appointment();
		$appointment->setId(5);
		$appointment->setClosedAt('2026-01-01 12:00:00');

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with(5)
			->willReturn($appointment);
		$this->appointmentMapper->expects($this->never())->method('update');
		$this->auditEventService->expects($this->never())->method('recordAppointmentLifecycle');

		$this->service->closeAppointment(5);
	}

	public function testUpdateAppointmentRecordsAuditWithChangedFields(): void {
		$appointment = new Appointment();
		$appointment->setId(3);
		$appointment->setName('Old name');
		$appointment->setDescription('Old description');
		$appointment->setStartDatetime('2026-01-01 10:00:00');
		$appointment->setEndDatetime('2026-01-01 11:00:00');

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with(3)
			->willReturn($appointment);
		$this->appointmentMapper->expects($this->once())
			->method('update')
			->willReturnArgument(0);

		$this->auditEventService->expects($this->once())
			->method('recordAppointmentUpdate')
			->with(3, $this->callback(function (array $fields) {
				sort($fields);
				return $fields === ['name', 'time'];
			}));

		$this->service->updateAppointment(
			3,
			'New name',
			'Old description',
			'2026-01-02T12:00:00Z',
			'2026-01-02T13:00:00Z',
			'admin',
		);
	}

	private function storedAllDayAppointment(): Appointment {
		$appointment = new Appointment();
		$appointment->setId(4);
		$appointment->setName('Trip');
		$appointment->setDescription('');
		$appointment->setStartDatetime('2026-05-31 22:00:00');
		$appointment->setEndDatetime('2026-06-01 22:00:00');
		$appointment->setAllDay(true);
		$this->appointmentMapper->method('find')->willReturn($appointment);
		$this->appointmentMapper->method('update')->willReturnArgument(0);
		return $appointment;
	}

	/** An older mobile build sends no isAllDay; renaming must not turn the day into times. */
	public function testUpdateKeepsAllDayForAClientThatLeavesTheTimesAlone(): void {
		$this->storedAllDayAppointment();
		$this->auditEventService->expects($this->once())->method('recordAppointmentUpdate')->with(4, ['name']);

		$updated = $this->service->updateAppointment(4, 'Day trip', '', '2026-05-31T22:00:00Z', '2026-06-01T22:00:00Z', 'admin');

		$this->assertTrue($updated->isAllDay());
	}

	/** Such a client can only have meant times once it moves them. */
	public function testUpdateDropsAllDayWhenAnUnawareClientMovesTheTimes(): void {
		$this->storedAllDayAppointment();

		$updated = $this->service->updateAppointment(4, 'Trip', '', '2026-06-01T08:00:00Z', '2026-06-01T16:00:00Z', 'admin');

		$this->assertFalse($updated->isAllDay());
	}

	public function testUpdateRecordsSwitchingToAllDayAsATimeChange(): void {
		$stored = $this->storedAllDayAppointment();
		$stored->setAllDay(false);
		$this->auditEventService->expects($this->once())->method('recordAppointmentUpdate')->with(4, ['time']);

		$updated = $this->service->updateAppointment(
			4, 'Trip', '', '2026-05-31T22:00:00Z', '2026-06-01T22:00:00Z', 'admin',
			isAllDay: true,
		);

		$this->assertTrue($updated->isAllDay());
	}

	public function testCreateAppointmentStoresLocation(): void {
		$this->appointmentMapper->expects($this->once())
			->method('insert')
			->with($this->callback(fn (Appointment $appointment) => $appointment->getLocation() === 'Club house'))
			->willReturnCallback(function (Appointment $appointment) {
				$appointment->setId(1);
				return $appointment;
			});

		$result = $this->service->createAppointment(
			'Team Meeting',
			'Weekly sync',
			'2024-01-15T10:00:00Z',
			'2024-01-15T11:00:00Z',
			'admin',
			[],
			[],
			[],
			false,
			null,
			null,
			null,
			null,
			null,
			null,
			'Club house',
		);

		$this->assertSame('Club house', $result->getLocation());
	}

	public function testCreateAppointmentTreatsEmptyLocationAsNull(): void {
		$this->appointmentMapper->expects($this->once())
			->method('insert')
			->with($this->callback(fn (Appointment $appointment) => $appointment->getLocation() === null))
			->willReturnCallback(function (Appointment $appointment) {
				$appointment->setId(1);
				return $appointment;
			});

		$this->service->createAppointment(
			'Team Meeting',
			'Weekly sync',
			'2024-01-15T10:00:00Z',
			'2024-01-15T11:00:00Z',
			'admin',
			[],
			[],
			[],
			false,
			null,
			null,
			null,
			null,
			null,
			null,
			'',
		);
	}

	public function testCreateAppointmentStoresCategoryWhenItExists(): void {
		$category = new Category();
		$category->setId(5);
		$category->setName('Rehearsal');
		$this->categoryMapper->method('find')->with(5)->willReturn($category);

		$this->appointmentMapper->expects($this->once())
			->method('insert')
			->with($this->callback(fn (Appointment $appointment) => $appointment->getCategoryId() === 5))
			->willReturnCallback(function (Appointment $appointment) {
				$appointment->setId(1);
				return $appointment;
			});

		$result = $this->service->createAppointment(
			'Team Meeting',
			'Weekly sync',
			'2024-01-15T10:00:00Z',
			'2024-01-15T11:00:00Z',
			'admin',
			[],
			[],
			[],
			false,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			5,
		);

		$this->assertSame(5, $result->getCategoryId());
	}

	public function testCreateAppointmentDropsCategoryIdWhenCategoryNoLongerExists(): void {
		$this->categoryMapper->method('find')->with(5)
			->willThrowException(new DoesNotExistException('not found'));

		$this->appointmentMapper->expects($this->once())
			->method('insert')
			->with($this->callback(fn (Appointment $appointment) => $appointment->getCategoryId() === null))
			->willReturnCallback(function (Appointment $appointment) {
				$appointment->setId(1);
				return $appointment;
			});

		$this->service->createAppointment(
			'Team Meeting',
			'Weekly sync',
			'2024-01-15T10:00:00Z',
			'2024-01-15T11:00:00Z',
			'admin',
			[],
			[],
			[],
			false,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			5,
		);
	}

	public function testUpdateAppointmentStoresLocationAndRecordsAudit(): void {
		$appointment = new Appointment();
		$appointment->setId(3);
		$appointment->setName('Same');
		$appointment->setDescription('Same');
		$appointment->setStartDatetime('2026-01-01 10:00:00');
		$appointment->setEndDatetime('2026-01-01 11:00:00');
		$appointment->setLocation(null);

		$this->appointmentMapper->expects($this->once())->method('find')->willReturn($appointment);
		$this->appointmentMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$this->auditEventService->expects($this->once())
			->method('recordAppointmentUpdate')
			->with(3, ['location']);

		$updated = $this->service->updateAppointment(
			3,
			'Same',
			'Same',
			'2026-01-01T10:00:00Z',
			'2026-01-01T11:00:00Z',
			'admin',
			[],
			[],
			[],
			null,
			null,
			'Club house',
		);

		$this->assertSame('Club house', $updated->getLocation());
	}

	public function testUpdateSeriesAppointmentsAppliesLocationToAllSiblings(): void {
		$reference = new Appointment();
		$reference->setId(10);
		$reference->setSeriesId('series-1');
		$reference->setSeriesPosition(0);
		$reference->setStartDatetime('2026-01-01 10:00:00');
		$reference->setEndDatetime('2026-01-01 11:00:00');

		$sibling = new Appointment();
		$sibling->setId(11);
		$sibling->setSeriesId('series-1');
		$sibling->setSeriesPosition(1);
		$sibling->setStartDatetime('2026-01-08 10:00:00');
		$sibling->setEndDatetime('2026-01-08 11:00:00');

		$this->appointmentMapper->method('find')->with(10)->willReturn($reference);
		$this->appointmentMapper->method('findBySeriesId')->with('series-1')->willReturn([$reference, $sibling]);
		$this->appointmentMapper->method('update')->willReturnArgument(0);
		$this->permissionService->method('canManageAppointments')->willReturn(true);

		$updated = $this->service->updateSeriesAppointments(
			10,
			'all',
			'Rehearsal',
			'',
			'2026-01-01T10:00:00Z',
			'2026-01-01T11:00:00Z',
			'admin',
			[],
			[],
			[],
			null,
			null,
			'Concert hall',
		);

		$this->assertCount(2, $updated);
		foreach ($updated as $appointment) {
			$this->assertSame('Concert hall', $appointment->getLocation());
		}
	}

	public function testGetLocationSuggestionsFiltersByVisibilityAndDedupes(): void {
		$visible = $this->createAppointment(1, 'A');
		$visible->setLocation('Club house');
		$alsoVisible = $this->createAppointment(2, 'B');
		$alsoVisible->setLocation('Club house');
		$notVisible = $this->createAppointment(3, 'C');
		$notVisible->setLocation('Secret room');

		$this->appointmentMapper->expects($this->once())
			->method('findWithLocation')
			->willReturn([$visible, $alsoVisible, $notVisible]);

		$this->visibilityService->method('canUserSeeAppointment')
			->willReturnCallback(fn (Appointment $appointment) => $appointment->getId() !== 3);

		$result = $this->service->getLocationSuggestions('user1');

		$this->assertSame(['Club house'], $result);
	}

	public function testUpdateAppointmentSkipsAuditWhenNothingChanged(): void {
		$appointment = new Appointment();
		$appointment->setId(3);
		$appointment->setName('Same');
		$appointment->setDescription('Same');
		$appointment->setStartDatetime('2026-01-01 10:00:00');
		$appointment->setEndDatetime('2026-01-01 11:00:00');

		$this->appointmentMapper->expects($this->once())->method('find')->willReturn($appointment);
		$this->appointmentMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$this->auditEventService->expects($this->never())->method('recordAppointmentUpdate');

		$this->service->updateAppointment(
			3,
			'Same',
			'Same',
			'2026-01-01T10:00:00Z',
			'2026-01-01T11:00:00Z',
			'admin',
		);
	}

	public function testReopenAppointmentRecordsAuditEvent(): void {
		$appointment = new Appointment();
		$appointment->setId(7);
		$appointment->setClosedAt('2026-01-01 12:00:00');

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with(7)
			->willReturn($appointment);

		$this->appointmentMapper->expects($this->once())
			->method('update')
			->willReturnArgument(0);

		$this->auditEventService->expects($this->once())
			->method('recordAppointmentLifecycle')
			->with(Verb::APPOINTMENT_REOPENED, 7, Verb::SOURCE_CLIENT);

		$result = $this->service->reopenAppointment(7);
		$this->assertNull($result->getClosedAt());
	}

	public function testSubmitResponseRejectsCancelledAppointment(): void {
		$appointment = new Appointment();
		$appointment->setId(9);
		$appointment->setCancelledAt('2030-05-28 09:00:00');

		$this->appointmentMapper->method('find')->with(9)->willReturn($appointment);
		$this->visibilityService->method('canUserSeeAppointment')->willReturn(true);
		$this->responseMapper->expects($this->never())->method('insert');
		$this->responseMapper->expects($this->never())->method('update');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('cancelled');
		$this->service->submitResponse(9, 'alice', 'yes');
	}

	public function testSubmitResponseRejectsClosedAppointment(): void {
		$appointment = new Appointment();
		$appointment->setId(9);
		$appointment->setClosedAt('2030-05-28 09:00:00');

		$this->appointmentMapper->method('find')->with(9)->willReturn($appointment);
		$this->visibilityService->method('canUserSeeAppointment')->willReturn(true);
		$this->responseMapper->expects($this->never())->method('insert');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('closed');
		$this->service->submitResponse(9, 'alice', 'yes');
	}

	public function testSubmitResponseRejectsMaybeWhereTheAppointmentDoesNotOfferIt(): void {
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);
		$appointment = new Appointment();
		$appointment->setId(9);
		$appointment->setAllowMaybe(false);

		$this->appointmentMapper->method('find')->with(9)->willReturn($appointment);
		$this->visibilityService->method('canUserSeeAppointment')->willReturn(true);
		$this->responseMapper->expects($this->never())->method('insert');
		$this->responseMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('maybe');
		$this->service->submitResponse(9, 'alice', 'maybe');
	}

	public function testSubmitResponseForUserRejectsMaybeWhereTheAppointmentDoesNotOfferIt(): void {
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);
		$appointment = new Appointment();
		$appointment->setId(9);
		$appointment->setAllowMaybe(false);

		$this->userManager->method('get')->willReturn($this->createMock(\OCP\IUser::class));
		$this->visibilityService->method('canUserSeeAppointment')->willReturn(true);
		$this->responseMapper->expects($this->never())->method('insert');
		$this->responseMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('maybe');
		$this->service->submitResponseForUser($appointment, 'bob', 'maybe', 'manager');
	}

	public function testSubmitResponseKeepsMaybeWhereTheAppointmentStillOffersIt(): void {
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);
		$appointment = new Appointment();
		$appointment->setId(9);
		$appointment->setAllowMaybe(true);

		$this->appointmentMapper->method('find')->with(9)->willReturn($appointment);
		$this->visibilityService->method('canUserSeeAppointment')->willReturn(true);
		$this->responseMapper->method('findByAppointmentAndUser')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('none'));
		$this->responseMapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$this->assertSame('maybe', $this->service->submitResponse(9, 'alice', 'maybe')->getResponse());
	}

	public function testSerializeAppointmentResolvesAllowMaybeAgainstTheInstanceDefault(): void {
		$undecided = new Appointment();
		$undecided->setId(3);

		$optedOut = new Appointment();
		$optedOut->setId(4);
		$optedOut->setAllowMaybe(false);

		// The instance default is true throughout this test class.
		$this->assertTrue($this->service->serializeAppointment($undecided)['allowMaybe']);
		$this->assertFalse($this->service->serializeAppointment($optedOut)['allowMaybe']);
	}

	public function testCancelAppointmentRecordsAuditEventAndNotifies(): void {
		$appointment = new Appointment();
		$appointment->setId(8);
		$appointment->setVisibleUsers(json_encode(['alice']));

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with(8)
			->willReturn($appointment);
		$this->appointmentMapper->expects($this->once())
			->method('update')
			->willReturnArgument(0);

		$this->auditEventService->expects($this->once())
			->method('recordAppointmentLifecycle')
			->with(Verb::APPOINTMENT_CANCELLED, 8, Verb::SOURCE_CLIENT);

		$this->notificationService->expects($this->once())
			->method('sendCancellationNotifications')
			->with($appointment, ['alice']);

		$result = $this->service->cancelAppointment(8, 'admin');
		$this->assertNotNull($result->getCancelledAt());
	}

	public function testCancelAppointmentExcludesActorFromNotification(): void {
		$appointment = new Appointment();
		$appointment->setId(8);
		$appointment->setVisibleUsers(json_encode(['admin', 'alice']));

		$this->appointmentMapper->method('find')->with(8)->willReturn($appointment);
		$this->appointmentMapper->method('update')->willReturnArgument(0);

		$this->notificationService->expects($this->once())
			->method('sendCancellationNotifications')
			->with($appointment, ['alice']);

		$this->service->cancelAppointment(8, 'admin');
	}

	public function testCancelAppointmentIsIdempotentForAlreadyCancelled(): void {
		$appointment = new Appointment();
		$appointment->setId(8);
		$appointment->setCancelledAt('2026-01-01 12:00:00');

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with(8)
			->willReturn($appointment);
		$this->appointmentMapper->expects($this->never())->method('update');
		$this->auditEventService->expects($this->never())->method('recordAppointmentLifecycle');
		$this->notificationService->expects($this->never())->method('sendCancellationNotifications');

		$this->service->cancelAppointment(8, 'admin');
	}

	public function testUncancelAppointmentRecordsAuditEvent(): void {
		$appointment = new Appointment();
		$appointment->setId(9);
		$appointment->setCancelledAt('2026-01-01 12:00:00');
		$appointment->setVisibleUsers(json_encode(['alice']));

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with(9)
			->willReturn($appointment);
		$this->appointmentMapper->expects($this->once())
			->method('update')
			->willReturnArgument(0);

		$this->auditEventService->expects($this->once())
			->method('recordAppointmentLifecycle')
			->with(Verb::APPOINTMENT_UNCANCELLED, 9, Verb::SOURCE_CLIENT);

		$result = $this->service->uncancelAppointment(9);
		$this->assertNull($result->getCancelledAt());
	}

	public function testUncancelAppointmentIsIdempotentForNotCancelled(): void {
		$appointment = new Appointment();
		$appointment->setId(9);

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with(9)
			->willReturn($appointment);
		$this->appointmentMapper->expects($this->never())->method('update');
		$this->auditEventService->expects($this->never())->method('recordAppointmentLifecycle');
		$this->notificationService->expects($this->never())->method('sendReactivationNotifications');

		$this->service->uncancelAppointment(9);
	}

	public function testUncancelAppointmentNotifiesAttendeesExceptTheActor(): void {
		$appointment = new Appointment();
		$appointment->setId(9);
		$appointment->setCancelledAt('2026-01-01 12:00:00');
		$appointment->setVisibleUsers(json_encode(['admin', 'alice']));

		$this->appointmentMapper->method('find')->with(9)->willReturn($appointment);
		$this->appointmentMapper->method('update')->willReturnArgument(0);

		$this->notificationService->expects($this->once())
			->method('sendReactivationNotifications')
			->with($appointment, ['alice']);

		$this->service->uncancelAppointment(9, 'admin');
	}

	/**
	 * @param list<string> $visibleUsers Who the appointment addresses before the edit
	 */
	private function setUpUpdateNotificationCase(array $visibleUsers, bool $sendNotification = false): Appointment {
		$appointment = new Appointment();
		$appointment->setId(3);
		$appointment->setName('Rehearsal');
		$appointment->setDescription('');
		$appointment->setStartDatetime('2030-01-01 10:00:00');
		$appointment->setEndDatetime('2030-01-01 11:00:00');
		$appointment->setVisibleUsers(json_encode($visibleUsers));
		$appointment->setSendNotification($sendNotification);

		$this->appointmentMapper->method('find')->with(3)->willReturn($appointment);
		$this->appointmentMapper->method('update')->willReturnArgument(0);

		return $appointment;
	}

	public function testUpdateNotifiesAddressedAttendeesAboutAMovedAppointment(): void {
		$appointment = $this->setUpUpdateNotificationCase(['admin', 'alice']);

		$this->notificationService->expects($this->once())
			->method('sendUpdateNotifications')
			->with($appointment, ['alice'], ['time']);
		$this->notificationService->expects($this->never())
			->method('sendNewAppointmentNotifications');

		$this->service->updateAppointment(
			3, 'Rehearsal', '', '2030-01-01T18:00:00Z', '2030-01-01T19:00:00Z',
			'admin', ['admin', 'alice'],
		);
	}

	public function testUpdateStaysSilentAboutCosmeticChanges(): void {
		$this->setUpUpdateNotificationCase(['alice']);

		$this->notificationService->expects($this->never())->method('sendUpdateNotifications');
		$this->notificationService->expects($this->never())->method('sendNewAppointmentNotifications');

		$this->service->updateAppointment(
			3, 'Rehearsal reloaded', 'Bring your scores', '2030-01-01T10:00:00Z', '2030-01-01T11:00:00Z',
			'admin', ['alice'],
		);
	}

	public function testUpdateStaysSilentAboutAMovedResponseDeadline(): void {
		$appointment = $this->setUpUpdateNotificationCase(['alice']);
		$appointment->setResponseDeadline('2029-12-01 10:00:00');

		$this->notificationService->expects($this->never())->method('sendUpdateNotifications');

		$this->service->updateAppointment(
			3, 'Rehearsal', '', '2030-01-01T10:00:00Z', '2030-01-01T11:00:00Z',
			'admin', ['alice'], [], [], DeadlineUpdate::clear(),
		);
	}

	public function testCosmeticUpdateNeverExpandsTheAudience(): void {
		$appointment = new Appointment();
		$appointment->setId(3);
		$appointment->setName('Rehearsal');
		$appointment->setDescription('');
		$appointment->setStartDatetime('2030-01-01 10:00:00');
		$appointment->setEndDatetime('2030-01-01 11:00:00');

		$this->appointmentMapper->method('find')->with(3)->willReturn($appointment);
		$this->appointmentMapper->method('update')->willReturnArgument(0);

		// Unrestricted visibility resolves through the whitelist, which hydrates
		// every account on the instance — a rename must not pay for that.
		$this->configService->expects($this->never())->method('getWhitelistedGroups');
		$this->userManager->expects($this->never())->method('search');

		$this->service->updateAppointment(
			3, 'Rehearsal reloaded', 'Bring your scores', '2030-01-01T10:00:00Z', '2030-01-01T11:00:00Z',
			'admin',
		);
	}

	/**
	 * @param list<string> $visibleUsers Who the series addresses before the edit
	 * @return list<Appointment> reference first, then the sibling
	 */
	private function setUpSeriesNotificationCase(array $visibleUsers, bool $sendNotification = false): array {
		$siblings = [];
		foreach ([[20, '2030-01-01'], [21, '2030-01-08']] as $index => [$id, $day]) {
			$sibling = new Appointment();
			$sibling->setId($id);
			$sibling->setName('Rehearsal');
			$sibling->setDescription('');
			$sibling->setSeriesId('series-1');
			$sibling->setSeriesPosition($index);
			$sibling->setStartDatetime($day . ' 10:00:00');
			$sibling->setEndDatetime($day . ' 11:00:00');
			$sibling->setVisibleUsers(json_encode($visibleUsers));
			$sibling->setSendNotification($sendNotification);
			$siblings[] = $sibling;
		}

		$this->appointmentMapper->method('find')->with(20)->willReturn($siblings[0]);
		$this->appointmentMapper->method('findBySeriesId')->with('series-1')->willReturn($siblings);
		$this->appointmentMapper->method('update')->willReturnArgument(0);
		$this->permissionService->method('canManageAppointments')->willReturn(true);

		return $siblings;
	}

	public function testMovedSeriesSendsOneWaveForTheWholeSeries(): void {
		$this->setUpSeriesNotificationCase(['admin', 'alice']);

		$this->notificationService->expects($this->once())
			->method('sendSeriesUpdateNotifications')
			->with(2, 'Rehearsal', ['time'], ['alice']);
		$this->notificationService->expects($this->never())->method('sendUpdateNotifications');

		$this->service->updateSeriesAppointments(
			20, 'all', 'Rehearsal', '', '2030-01-01T18:00:00Z', '2030-01-01T19:00:00Z',
			'admin', ['admin', 'alice'],
		);
	}

	public function testSeriesWaveCountsOnlyTheAppointmentsStillAhead(): void {
		$siblings = $this->setUpSeriesNotificationCase(['alice']);
		// The reference keeps its time, so the whole series shifts by zero and
		// this sibling stays in the past.
		$siblings[1]->setStartDatetime('2020-01-08 10:00:00');
		$siblings[1]->setEndDatetime('2020-01-08 11:00:00');

		$this->notificationService->expects($this->once())
			->method('sendSeriesUpdateNotifications')
			->with(1, 'Rehearsal', ['location'], ['alice']);

		$this->service->updateSeriesAppointments(
			20, 'all', 'Rehearsal', '', '2030-01-01T10:00:00Z', '2030-01-01T11:00:00Z',
			'admin', ['alice'], [], [], null, null, 'Concert hall',
		);
	}

	public function testSeriesStaysSilentAboutCosmeticChanges(): void {
		$this->setUpSeriesNotificationCase(['alice']);

		$this->notificationService->expects($this->never())->method('sendSeriesUpdateNotifications');
		$this->notificationService->expects($this->never())->method('sendBulkAppointmentNotifications');

		$this->service->updateSeriesAppointments(
			20, 'all', 'Rehearsal reloaded', 'Bring your scores', '2030-01-01T10:00:00Z', '2030-01-01T11:00:00Z',
			'admin', ['alice'],
		);
	}

	public function testWideningSeriesVisibilityAnnouncesTheSeriesToNewcomers(): void {
		$this->setUpSeriesNotificationCase(['alice'], true);

		$this->notificationService->expects($this->once())
			->method('sendBulkAppointmentNotifications')
			->with(2, 'Rehearsal', ['bob']);
		$this->notificationService->expects($this->never())->method('sendSeriesUpdateNotifications');

		$this->service->updateSeriesAppointments(
			20, 'all', 'Rehearsal', '', '2030-01-01T10:00:00Z', '2030-01-01T11:00:00Z',
			'admin', ['alice', 'bob'],
		);
	}

	public function testUpdateStaysSilentForAnAppointmentThatIsOver(): void {
		$this->setUpUpdateNotificationCase(['alice']);

		$this->notificationService->expects($this->never())->method('sendUpdateNotifications');

		$this->service->updateAppointment(
			3, 'Rehearsal', '', '2020-01-01T10:00:00Z', '2020-01-01T11:00:00Z',
			'admin', ['alice'], [], [], null, null, 'Club house',
		);
	}

	public function testUpdateStaysSilentForACancelledAppointment(): void {
		$appointment = $this->setUpUpdateNotificationCase(['alice']);
		$appointment->setCancelledAt('2026-01-01 12:00:00');

		$this->notificationService->expects($this->never())->method('sendUpdateNotifications');

		$this->service->updateAppointment(
			3, 'Rehearsal', '', '2030-01-01T10:00:00Z', '2030-01-01T11:00:00Z',
			'admin', ['alice'], [], [], null, null, 'Club house',
		);
	}

	public function testWideningVisibilityAnnouncesTheAppointmentToNewcomersOnly(): void {
		$appointment = $this->setUpUpdateNotificationCase(['alice'], true);

		$this->notificationService->expects($this->once())
			->method('sendNewAppointmentNotifications')
			->with($appointment, ['bob']);
		$this->notificationService->expects($this->never())
			->method('sendUpdateNotifications');

		$this->service->updateAppointment(
			3, 'Rehearsal', '', '2030-01-01T10:00:00Z', '2030-01-01T11:00:00Z',
			'admin', ['alice', 'bob'],
		);
	}

	public function testWideningVisibilityRespectsTheCreateTimeNotificationOptOut(): void {
		$this->setUpUpdateNotificationCase(['alice'], false);

		$this->notificationService->expects($this->never())
			->method('sendNewAppointmentNotifications');

		$this->service->updateAppointment(
			3, 'Rehearsal', '', '2030-01-01T10:00:00Z', '2030-01-01T11:00:00Z',
			'admin', ['alice', 'bob'],
		);
	}

	public function testMovedAppointmentNotifiesOnlyPeopleWhoAlreadyHadIt(): void {
		$appointment = $this->setUpUpdateNotificationCase(['alice'], true);

		$this->notificationService->expects($this->once())
			->method('sendNewAppointmentNotifications')
			->with($appointment, ['bob']);
		$this->notificationService->expects($this->once())
			->method('sendUpdateNotifications')
			->with($appointment, ['alice'], ['time']);

		$this->service->updateAppointment(
			3, 'Rehearsal', '', '2030-01-01T18:00:00Z', '2030-01-01T19:00:00Z',
			'admin', ['alice', 'bob'],
		);
	}

	public function testGetAppointment(): void {
		$appointmentId = 1;
		$appointment = new Appointment();
		$appointment->setId($appointmentId);

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with($appointmentId)
			->willReturn($appointment);

		$result = $this->service->getAppointment($appointmentId);

		$this->assertEquals($appointmentId, $result->getId());
	}

	public function testGetAllAppointments(): void {
		$appointments = [
			$this->createAppointment(1, 'Meeting 1'),
			$this->createAppointment(2, 'Meeting 2')
		];

		$this->appointmentMapper->expects($this->once())
			->method('findAll')
			->willReturn($appointments);

		$result = $this->service->getAllAppointments();

		$this->assertCount(2, $result);
	}

	public function testSubmitResponseCreatesNewResponse(): void {
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);
		$appointmentId = 1;
		$userId = 'testuser';
		$response = 'yes';
		$comment = 'I will attend';

		$appointment = new Appointment();
		$appointment->setId($appointmentId);

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with($appointmentId)
			->willReturn($appointment);

		$this->visibilityService->expects($this->once())
			->method('canUserSeeAppointment')
			->with($appointment, $userId)
			->willReturn(true);

		$this->responseMapper->expects($this->once())
			->method('findByAppointmentAndUser')
			->with($appointmentId, $userId)
			->willThrowException(new DoesNotExistException(''));

		$savedResponse = new AttendanceResponse();
		$savedResponse->setId(1);

		$this->responseMapper->expects($this->once())
			->method('insert')
			->willReturn($savedResponse);

		$result = $this->service->submitResponse(
			$appointmentId,
			$userId,
			$response,
			$comment
		);

		$this->assertInstanceOf(AttendanceResponse::class, $result);
	}

	public function testSubmitResponseUpdatesExistingResponse(): void {
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);
		$appointmentId = 1;
		$userId = 'testuser';
		$response = 'no';
		$comment = 'Cannot attend';

		$appointment = new Appointment();
		$appointment->setId($appointmentId);

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with($appointmentId)
			->willReturn($appointment);

		$this->visibilityService->expects($this->once())
			->method('canUserSeeAppointment')
			->with($appointment, $userId)
			->willReturn(true);

		$existingResponse = new AttendanceResponse();
		$existingResponse->setId(1);
		$existingResponse->setResponse('yes');

		$this->responseMapper->expects($this->once())
			->method('findByAppointmentAndUser')
			->with($appointmentId, $userId)
			->willReturn($existingResponse);

		$this->responseMapper->expects($this->once())
			->method('update')
			->willReturn($existingResponse);

		$result = $this->service->submitResponse(
			$appointmentId,
			$userId,
			$response,
			$comment
		);

		$this->assertInstanceOf(AttendanceResponse::class, $result);
	}

	public function testSubmitResponseThrowsExceptionForInvalidResponse(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid response. Must be yes, no, maybe, or null.');

		$this->service->submitResponse(1, 'user', 'invalid', '');
	}

	public function testSubmitResponseWithNullWithdrawsExistingResponse(): void {
		$appointmentId = 1;
		$userId = 'testuser';

		$appointment = new Appointment();
		$appointment->setId($appointmentId);

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with($appointmentId)
			->willReturn($appointment);

		$this->visibilityService->expects($this->once())
			->method('canUserSeeAppointment')
			->with($appointment, $userId)
			->willReturn(true);

		$existingResponse = new AttendanceResponse();
		$existingResponse->setId(1);
		$existingResponse->setResponse('yes');
		$existingResponse->setComment('was excited');
		$existingResponse->setCheckinState('yes');

		$this->responseMapper->expects($this->once())
			->method('findByAppointmentAndUser')
			->with($appointmentId, $userId)
			->willReturn($existingResponse);

		$this->responseMapper->expects($this->once())
			->method('update')
			->willReturnCallback(function (AttendanceResponse $r) {
				$this->assertNull($r->getResponse());
				$this->assertSame('', $r->getComment());
				// Existing check-in state must be preserved
				$this->assertSame('yes', $r->getCheckinState());
				return $r;
			});

		$result = $this->service->submitResponse($appointmentId, $userId, null);
		$this->assertInstanceOf(AttendanceResponse::class, $result);
	}

	public function testSubmitResponseWithNullThrowsOnClosedAppointment(): void {
		$appointmentId = 1;
		$userId = 'testuser';

		$appointment = new Appointment();
		$appointment->setId($appointmentId);
		$appointment->setClosedAt('2026-01-01 12:00:00');

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with($appointmentId)
			->willReturn($appointment);

		$this->visibilityService->expects($this->once())
			->method('canUserSeeAppointment')
			->willReturn(true);

		$this->responseMapper->expects($this->never())->method('update');
		$this->responseMapper->expects($this->never())->method('insert');

		$this->expectException(\RuntimeException::class);
		$this->service->submitResponse($appointmentId, $userId, null);
	}

	public function testSubmitResponseForUserCreatesNewResponseWithAdminSource(): void {
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);
		$appointmentId = 1;
		$targetUserId = 'phoneuser';
		$actorUserId = 'manager';

		$appointment = new Appointment();
		$appointment->setId($appointmentId);

		// The controller already loaded the appointment, so the service must
		// not fetch it again.
		$this->appointmentMapper->expects($this->never())
			->method('find');

		$this->userManager->method('get')->willReturn($this->createMock(\OCP\IUser::class));

		$this->visibilityService->expects($this->once())
			->method('canUserSeeAppointment')
			->with($appointment, $targetUserId)
			->willReturn(true);

		$this->responseMapper->expects($this->once())
			->method('findByAppointmentAndUser')
			->with($appointmentId, $targetUserId)
			->willThrowException(new DoesNotExistException(''));

		$this->responseMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (AttendanceResponse $r) use ($targetUserId) {
				$this->assertSame($targetUserId, $r->getUserId());
				$this->assertSame('yes', $r->getResponse());
				$this->assertSame('', $r->getComment());
				$this->assertSame('admin', $r->getResponseSource());
				return $r;
			});

		$this->auditEventService->expects($this->once())
			->method('recordResponseChange')
			->with(
				$appointmentId,
				$targetUserId,
				null,
				'',
				'yes',
				'',
				Verb::SOURCE_ADMIN_RESPONSE,
				$actorUserId,
			);

		$this->notificationService->expects($this->once())
			->method('markAppointmentNotificationsProcessed')
			->with($appointmentId, $targetUserId);

		$result = $this->service->submitResponseForUser($appointment, $targetUserId, 'yes', $actorUserId);
		$this->assertInstanceOf(AttendanceResponse::class, $result);
	}

	public function testSubmitResponseForUserPreservesExistingComment(): void {
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);
		$appointmentId = 1;
		$targetUserId = 'phoneuser';
		$actorUserId = 'manager';

		$appointment = new Appointment();
		$appointment->setId($appointmentId);

		$this->userManager->method('get')->willReturn($this->createMock(\OCP\IUser::class));
		$this->visibilityService->method('canUserSeeAppointment')->willReturn(true);

		$existingResponse = new AttendanceResponse();
		$existingResponse->setId(1);
		$existingResponse->setResponse('maybe');
		$existingResponse->setComment('depends on my shift');

		$this->responseMapper->expects($this->once())
			->method('findByAppointmentAndUser')
			->with($appointmentId, $targetUserId)
			->willReturn($existingResponse);

		$this->responseMapper->expects($this->once())
			->method('update')
			->willReturnCallback(function (AttendanceResponse $r) {
				$this->assertSame('yes', $r->getResponse());
				// The person's own comment must survive a manager override.
				$this->assertSame('depends on my shift', $r->getComment());
				$this->assertSame('admin', $r->getResponseSource());
				return $r;
			});

		$this->auditEventService->expects($this->once())
			->method('recordResponseChange')
			->with(
				$appointmentId,
				$targetUserId,
				'maybe',
				'depends on my shift',
				'yes',
				'depends on my shift',
				Verb::SOURCE_ADMIN_RESPONSE,
				$actorUserId,
			);

		$result = $this->service->submitResponseForUser($appointment, $targetUserId, 'yes', $actorUserId);
		$this->assertInstanceOf(AttendanceResponse::class, $result);
	}

	public function testSubmitResponseForUserWithNullClearsResponseAndComment(): void {
		$appointmentId = 1;
		$targetUserId = 'phoneuser';
		$actorUserId = 'manager';

		$appointment = new Appointment();
		$appointment->setId($appointmentId);

		$this->userManager->method('get')->willReturn($this->createMock(\OCP\IUser::class));
		$this->visibilityService->method('canUserSeeAppointment')->willReturn(true);

		$existingResponse = new AttendanceResponse();
		$existingResponse->setId(1);
		$existingResponse->setResponse('yes');
		$existingResponse->setComment('see you there');

		$this->responseMapper->expects($this->once())
			->method('findByAppointmentAndUser')
			->willReturn($existingResponse);

		$this->responseMapper->expects($this->once())
			->method('update')
			->willReturnCallback(function (AttendanceResponse $r) {
				$this->assertNull($r->getResponse());
				$this->assertSame('', $r->getComment());
				return $r;
			});

		$this->auditEventService->expects($this->once())
			->method('recordResponseChange')
			->with(
				$appointmentId,
				$targetUserId,
				'yes',
				'see you there',
				null,
				'',
				Verb::SOURCE_ADMIN_RESPONSE,
				$actorUserId,
			);

		$result = $this->service->submitResponseForUser($appointment, $targetUserId, null, $actorUserId);
		$this->assertInstanceOf(AttendanceResponse::class, $result);
	}

	public function testSubmitResponseForUserRejectsUnknownUser(): void {
		$appointment = new Appointment();
		$appointment->setId(1);

		// userManager->get returns null for unknown users; the guard must
		// fire before the permission-based visibility check, which treats
		// unknown users as managers when permissions are unconfigured.
		$this->visibilityService->expects($this->never())->method('canUserSeeAppointment');
		$this->responseMapper->expects($this->never())->method('insert');
		$this->responseMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('User not found');
		$this->service->submitResponseForUser($appointment, 'ghost', 'yes', 'manager');
	}

	public function testSubmitResponseForUserRejectsUserOutsideAudience(): void {
		$appointment = new Appointment();
		$appointment->setId(1);

		$this->userManager->method('get')->willReturn($this->createMock(\OCP\IUser::class));
		$this->visibilityService->expects($this->once())
			->method('canUserSeeAppointment')
			->with($appointment, 'stranger')
			->willReturn(false);

		$this->responseMapper->expects($this->never())->method('insert');
		$this->responseMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->submitResponseForUser($appointment, 'stranger', 'yes', 'manager');
	}

	/** Issue #251: an organizer outside the audience answered, and the answer showed up nowhere. */
	public function testSubmitResponseRejectsViewerOutsideTheAudience(): void {
		$appointment = new Appointment();
		$appointment->setId(1);
		$this->appointmentMapper->method('find')->willReturn($appointment);
		// Organizers and see-all holders see the appointment without being asked.
		$this->visibilityService->method('canUserSeeAppointment')->willReturn(true);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(false);

		$this->responseMapper->expects($this->never())->method('insert');
		$this->responseMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Only attendees of this appointment can respond to it.');
		$this->service->submitResponse(1, 'organizer', 'yes');
	}

	public function testSubmitResponseForUserRejectsTargetWhoOnlySeesTheAppointment(): void {
		$appointment = new Appointment();
		$appointment->setId(1);

		$this->userManager->method('get')->willReturn($this->createMock(\OCP\IUser::class));
		$this->visibilityService->method('canUserSeeAppointment')->willReturn(true);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(false);

		$this->responseMapper->expects($this->never())->method('insert');
		$this->responseMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Only attendees of this appointment can respond to it.');
		$this->service->submitResponseForUser($appointment, 'organizer', 'yes', 'manager');
	}

	public function testSubmitResponseForUserThrowsForInvalidResponse(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid response. Must be yes, no, maybe, or null.');

		$this->service->submitResponseForUser(new Appointment(), 'user', 'invalid', 'manager');
	}

	public function testSubmitResponseForUserRejectsClosedAppointment(): void {
		$appointment = new Appointment();
		$appointment->setId(1);
		$appointment->setClosedAt('2026-01-01 12:00:00');

		$this->userManager->method('get')->willReturn($this->createMock(\OCP\IUser::class));
		$this->visibilityService->method('canUserSeeAppointment')->willReturn(true);

		$this->responseMapper->expects($this->never())->method('update');
		$this->responseMapper->expects($this->never())->method('insert');

		$this->expectException(\RuntimeException::class);
		$this->service->submitResponseForUser($appointment, 'phoneuser', 'yes', 'manager');
	}

	public function testGetUserResponseReturnsNullWhenNotFound(): void {
		$appointmentId = 1;
		$userId = 'testuser';

		$this->responseMapper->expects($this->once())
			->method('findByAppointmentAndUser')
			->with($appointmentId, $userId)
			->willThrowException(new DoesNotExistException(''));

		$result = $this->service->getUserResponse($appointmentId, $userId);

		$this->assertNull($result);
	}

	public function testGetUserResponseReturnsResponse(): void {
		$appointmentId = 1;
		$userId = 'testuser';

		$response = new AttendanceResponse();
		$response->setAppointmentId($appointmentId);
		$response->setUserId($userId);

		$this->responseMapper->expects($this->once())
			->method('findByAppointmentAndUser')
			->with($appointmentId, $userId)
			->willReturn($response);

		$result = $this->service->getUserResponse($appointmentId, $userId);

		$this->assertInstanceOf(AttendanceResponse::class, $result);
		$this->assertEquals($userId, $result->getUserId());
	}

	public function testGetAppointmentResponses(): void {
		$appointmentId = 1;
		$responses = [
			$this->createResponse(1, 'user1', 'yes'),
			$this->createResponse(2, 'user2', 'no')
		];

		$this->responseMapper->expects($this->once())
			->method('findByAppointment')
			->with($appointmentId)
			->willReturn($responses);

		$result = $this->service->getAppointmentResponses($appointmentId);

		$this->assertCount(2, $result);
	}

	public function testDeleteAppointmentSetsIsActiveToFalse(): void {
		$appointmentId = 1;
		$userId = 'admin';

		$appointment = new Appointment();
		$appointment->setId($appointmentId);
		$appointment->setIsActive(true);

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with($appointmentId)
			->willReturn($appointment);

		$this->appointmentMapper->expects($this->once())
			->method('update')
			->with($this->callback(function ($app) {
				return $app->getIsActive() === 0;
			}));

		$this->service->deleteAppointment($appointmentId, $userId);
	}

	public function testGetUpcomingAppointmentsForWidgetIncludesUnrestrictedAppointmentsCreatedByOthers(): void {
		// Regression: widget previously filtered to appointments created by
		// the current user, which hid "everyone" appointments authored by
		// somebody else.
		$userId = 'alice';

		$ownRestricted = $this->createAppointment(1, 'Own restricted');
		$ownRestricted->setCreatedBy($userId);
		$ownRestricted->setVisibleUsers(json_encode([$userId]));

		$globalByOther = $this->createAppointment(2, 'Everyone meeting');
		$globalByOther->setCreatedBy('bob');

		$othersOnlyByOther = $this->createAppointment(3, 'Bob private');
		$othersOnlyByOther->setCreatedBy('bob');
		$othersOnlyByOther->setVisibleUsers(json_encode(['bob']));

		$this->appointmentMapper->expects($this->once())
			->method('findUpcoming')
			->willReturn([$ownRestricted, $globalByOther, $othersOnlyByOther]);

		$this->visibilityService->expects($this->exactly(3))
			->method('isUserTargetAttendee')
			->willReturnCallback(function (Appointment $appointment, string $uid) use ($userId): bool {
				$this->assertSame($userId, $uid);
				return match ($appointment->getId()) {
					1, 2 => true,
					3 => false,
					default => false,
				};
			});

		$this->attachmentService->method('getAttachments')->willReturn([]);
		$this->responseMapper->method('findByAppointmentAndUser')
			->willThrowException(new DoesNotExistException(''));

		$result = $this->service->getUpcomingAppointmentsForWidget($userId, 5);

		$this->assertCount(2, $result);
		$ids = array_map(static fn (array $a): int => $a['id'], $result);
		$this->assertSame([1, 2], $ids);
	}

	private function createAppointment(int $id, string $name): Appointment {
		$appointment = new Appointment();
		$appointment->setId($id);
		$appointment->setName($name);
		return $appointment;
	}

	private function createResponse(int $id, string $userId, string $response): AttendanceResponse {
		$attendanceResponse = new AttendanceResponse();
		$attendanceResponse->setId($id);
		$attendanceResponse->setUserId($userId);
		$attendanceResponse->setResponse($response);
		return $attendanceResponse;
	}

	// --- organizer handling ---

	/**
	 * Make every user in $knownUsers resolvable and non-guest, so organizer
	 * normalization keeps them.
	 */
	private function expectKnownUsers(array $knownUsers): void {
		$this->userManager->method('get')
			->willReturnCallback(function (string $uid) use ($knownUsers) {
				return in_array($uid, $knownUsers, true)
					? $this->createMock(\OCP\IUser::class)
					: null;
			});
		$this->guestService->method('isGuestUser')->willReturn(false);
	}

	public function testCreateAppointmentDefaultsOrganizersToCreator(): void {
		$this->expectKnownUsers(['admin']);
		$this->appointmentMapper->method('insert')->willReturnCallback(function (Appointment $a) {
			$a->setId(1);
			return $a;
		});

		$result = $this->service->createAppointment(
			'Meeting', '', '2024-01-15T10:00:00Z', '2024-01-15T11:00:00Z', 'admin'
		);

		$this->assertEquals(['admin'], $result->getOrganizersList());
	}

	public function testCreateAppointmentFiltersUnknownUsersAndGuestsFromOrganizers(): void {
		$this->permissionService->method('canManageAppointments')->willReturn(true);
		$this->userManager->method('get')
			->willReturnCallback(function (string $uid) {
				return $uid === 'ghost' ? null : $this->createMock(\OCP\IUser::class);
			});
		$this->guestService->method('isGuestUser')
			->willReturnCallback(fn (string $uid) => $uid === 'guestuser');
		$this->appointmentMapper->method('insert')->willReturnCallback(function (Appointment $a) {
			$a->setId(1);
			return $a;
		});

		$result = $this->service->createAppointment(
			'Meeting', '', '2024-01-15T10:00:00Z', '2024-01-15T11:00:00Z', 'admin',
			[], [], [], false, null, null, null, null, null,
			['alice', 'ghost', 'guestuser', 'alice', 'bob'],
		);

		$this->assertEquals(['alice', 'bob'], $result->getOrganizersList());
	}

	public function testCreateAppointmentAddsNonManagerCreatorToOrganizers(): void {
		$this->permissionService->method('canManageAppointments')->willReturn(false);
		$this->expectKnownUsers(['alice', 'bob']);
		$this->appointmentMapper->method('insert')->willReturnCallback(function (Appointment $a) {
			$a->setId(1);
			return $a;
		});

		$result = $this->service->createAppointment(
			'Meeting', '', '2024-01-15T10:00:00Z', '2024-01-15T11:00:00Z', 'bob',
			[], [], [], false, null, null, null, null, null,
			['alice'],
		);

		$this->assertEquals(['alice', 'bob'], $result->getOrganizersList());
	}

	public function testCreateAppointmentLeavesManagerCreatorOutOfOrganizers(): void {
		$this->permissionService->method('canManageAppointments')->willReturn(true);
		$this->expectKnownUsers(['alice', 'admin']);
		$this->appointmentMapper->method('insert')->willReturnCallback(function (Appointment $a) {
			$a->setId(1);
			return $a;
		});

		$result = $this->service->createAppointment(
			'Meeting', '', '2024-01-15T10:00:00Z', '2024-01-15T11:00:00Z', 'admin',
			[], [], [], false, null, null, null, null, null,
			['alice'],
		);

		$this->assertEquals(['alice'], $result->getOrganizersList());
	}

	private function setUpOrganizerUpdate(array $currentOrganizers, bool $actorIsManager): Appointment {
		$appointment = new Appointment();
		$appointment->setId(3);
		$appointment->setName('Meeting');
		$appointment->setStartDatetime('2026-01-01 10:00:00');
		$appointment->setEndDatetime('2026-01-01 11:00:00');
		$appointment->setOrganizers(json_encode($currentOrganizers));

		$this->appointmentMapper->method('find')->with(3)->willReturn($appointment);
		$this->appointmentMapper->method('update')->willReturnArgument(0);
		$this->permissionService->method('canManageAppointments')->willReturn($actorIsManager);

		return $appointment;
	}

	public function testOrganizerCanAddOrganizers(): void {
		$this->expectKnownUsers(['alice', 'bob']);
		$this->setUpOrganizerUpdate(['alice'], false);

		$result = $this->service->updateAppointment(
			3, 'Meeting', '', '2026-01-01T10:00:00Z', '2026-01-01T11:00:00Z',
			'alice', [], [], [], null, ['alice', 'bob'],
		);

		$this->assertEquals(['alice', 'bob'], $result->getOrganizersList());
	}

	/**
	 * A new organiser needs moderator rank in the Talk room and a former one
	 * needs it taken away again — neither happens unless the participant sync
	 * actually runs when the organizer list changes.
	 */
	public function testUpdateAppointmentSyncsTalkParticipantsWhenOrganizersChange(): void {
		$this->expectKnownUsers(['alice', 'bob']);
		$appointment = $this->setUpOrganizerUpdate(['alice'], false);

		$this->talkRoomService->expects($this->once())
			->method('syncParticipants')
			->with($appointment);

		$this->service->updateAppointment(
			3, 'Meeting', '', '2026-01-01T10:00:00Z', '2026-01-01T11:00:00Z',
			'alice', [], [], [], null, ['alice', 'bob'],
		);
	}

	public function testUpdateAppointmentDoesNotSyncTalkParticipantsWhenOrganizersAreUnchanged(): void {
		$this->expectKnownUsers(['alice']);
		$this->setUpOrganizerUpdate(['alice'], false);

		$this->talkRoomService->expects($this->never())->method('syncParticipants');

		$this->service->updateAppointment(
			3, 'New name', '', '2026-01-01T10:00:00Z', '2026-01-01T11:00:00Z',
			'alice', [], [], [], null, ['alice'],
		);
	}

	public function testOrganizerCanRemoveOnlyThemselves(): void {
		$this->expectKnownUsers(['alice', 'bob']);
		$this->setUpOrganizerUpdate(['alice', 'bob'], false);

		$result = $this->service->updateAppointment(
			3, 'Meeting', '', '2026-01-01T10:00:00Z', '2026-01-01T11:00:00Z',
			'alice', [], [], [], null, ['bob'],
		);

		$this->assertEquals(['bob'], $result->getOrganizersList());
	}

	public function testOrganizerCannotRemoveOtherOrganizers(): void {
		$this->expectKnownUsers(['alice', 'bob']);
		$this->setUpOrganizerUpdate(['alice', 'bob'], false);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Organizers can only remove themselves');

		$this->service->updateAppointment(
			3, 'Meeting', '', '2026-01-01T10:00:00Z', '2026-01-01T11:00:00Z',
			'alice', [], [], [], null, ['alice'],
		);
	}

	public function testManagerCanRemoveAnyOrganizer(): void {
		$this->expectKnownUsers(['alice', 'bob', 'admin']);
		$this->setUpOrganizerUpdate(['alice', 'bob'], true);

		$result = $this->service->updateAppointment(
			3, 'Meeting', '', '2026-01-01T10:00:00Z', '2026-01-01T11:00:00Z',
			'admin', [], [], [], null, [],
		);

		$this->assertEquals([], $result->getOrganizersList());
	}

	public function testUpdateAppointmentLeavesOrganizersUntouchedWhenNull(): void {
		$this->expectKnownUsers(['alice']);
		$this->setUpOrganizerUpdate(['alice'], false);

		$result = $this->service->updateAppointment(
			3, 'Meeting', '', '2026-01-01T10:00:00Z', '2026-01-01T11:00:00Z',
			'alice', [], [], [], null, null,
		);

		$this->assertEquals(['alice'], $result->getOrganizersList());
	}

	// --- scheduling: "not scheduled out" ---
	// The predicate itself lives in BookingService and is covered there; here we
	// only assert that the two list paths honour it.

	public function testWidgetHidesAppointmentsTheUserWasScheduledOutOf(): void {
		$appointment = $this->createAppointment(1, 'Closed meeting');

		$this->appointmentMapper->method('findUpcoming')->willReturn([$appointment]);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);
		$this->attachmentService->method('getAttachments')->willReturn([]);
		$this->bookingService->method('isScheduledOut')->willReturn(true);

		$this->assertSame([], $this->service->getUpcomingAppointmentsForWidget('alice', 5));
	}

	public function testNotScheduledOutFilterDropsTheAppointmentFromTheList(): void {
		$scheduledOut = $this->createAppointment(1, 'Closed meeting');

		$this->appointmentMapper->method('findUpcoming')->willReturn([$scheduledOut]);
		$this->visibilityService->method('canUserSeeAppointment')->willReturn(true);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);
		$this->bookingService->method('isScheduledOut')->willReturn(true);

		// Without the filter the appointment is still listed — being scheduled
		// out only ever hides it on request.
		$this->assertCount(1, $this->service->getAppointmentsWithUserResponses('alice'));
		$this->assertSame([], $this->service->getAppointmentsWithUserResponses(
			'alice',
			notScheduledOut: true,
		));
	}

	public function testNotScheduledOutFilterImpliesOnlyForMe(): void {
		// A see-all holder gets every appointment by default; the scheduling
		// filter must still cut the ones that are none of their own.
		$foreign = $this->createAppointment(1, 'Bob only');

		$this->appointmentMapper->method('findUpcoming')->willReturn([$foreign]);
		$this->permissionService->method('canSeeAppointmentViaSeeAll')->willReturn(true);
		$this->permissionService->method('isOrganizer')->willReturn(false);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(false);

		$this->assertSame([], $this->service->getAppointmentsWithUserResponses(
			'alice',
			notScheduledOut: true,
		));
	}

	// --- "My appointments" scope ---

	public function testOnlyForMeKeepsAppointmentsTheUserOrganizes(): void {
		$organized = $this->createAppointment(1, 'Bob organizes this');

		$this->appointmentMapper->method('findUpcoming')->willReturn([$organized]);
		// Not invited, but organizing it — "My appointments" is not just the
		// audience, and it needs no see-all permission either.
		$this->permissionService->method('canSeeAppointmentViaSeeAll')->willReturn(false);
		$this->permissionService->method('isOrganizer')->willReturn(true);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(false);

		$appointments = $this->service->getAppointmentsWithUserResponses('bob', onlyForMe: true);

		$this->assertCount(1, $appointments);
		// Clients hide the answer buttons on this flag (issue #251)
		$this->assertFalse($appointments[0]['myPermissions']['isAttendee']);
	}

	public function testUnansweredInboxSkipsAppointmentsTheUserOnlyOrganizes(): void {
		$organized = $this->createAppointment(1, 'Bob organizes this');

		$this->appointmentMapper->method('findUpcoming')->willReturn([$organized]);
		$this->permissionService->method('canSeeAppointmentViaSeeAll')->willReturn(false);
		$this->permissionService->method('isOrganizer')->willReturn(true);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(false);

		// Organizing an appointment is not being asked to answer it.
		$this->assertSame([], $this->service->getAppointmentsWithUserResponses('bob', unansweredOnly: true));
	}

	// --- reminder targets ---

	/**
	 * Checking somebody in writes a response row with no answer. "Non-responder"
	 * has to keep meaning "has not answered" — the summary lists such a person
	 * as one and offers a reminder bell next to their name (issue #229), so a
	 * bulk reminder must not silently skip exactly them.
	 */
	public function testNonResponderRemindersReachSomebodyWhoWasOnlyCheckedIn(): void {
		$appointment = $this->createAppointment(1, 'Rehearsal');

		$checkinOnly = new AttendanceResponse();
		$checkinOnly->setUserId('wilfried');
		$checkinOnly->setResponse(null);
		$checkinOnly->setCheckinState('yes');
		$answered = $this->createResponse(2, 'alice', 'yes');

		$this->responseMapper->method('findByAppointment')->willReturn([$checkinOnly, $answered]);
		$this->configService->method('getWhitelistedGroups')->willReturn([]);
		$this->visibilityService->method('getRelevantUsersForAppointment')->willReturn([
			'wilfried' => $this->createMock(\OCP\IUser::class),
			'alice' => $this->createMock(\OCP\IUser::class),
		]);

		$targets = $this->service->getReminderTargetUserIds($appointment, 'non_responders');

		$this->assertSame(['wilfried'], $targets);
	}

	// --- onboarding entry point ---

	public function testHasAnyAppointmentReportsAFreshInstance(): void {
		$this->appointmentMapper->expects($this->once())
			->method('hasAny')
			->willReturn(false);

		$this->assertFalse($this->service->hasAnyAppointment());
	}

	public function testHasAnyAppointmentReportsAnInstanceInUse(): void {
		$this->appointmentMapper->method('hasAny')->willReturn(true);

		$this->assertTrue($this->service->hasAnyAppointment());
	}

	// --- detailed responses with users ---

	/**
	 * Regression test for issue #213: a response recorded while the user was
	 * still a target attendee must stay listed after they leave the
	 * group/team that made the appointment visible to them.
	 */
	public function testGetAppointmentResponsesWithUsersKeepsResponseFromFormerTargetAttendee(): void {
		$appointmentId = 42;
		$appointment = new Appointment();
		$appointment->setId($appointmentId);

		$response = new AttendanceResponse();
		$response->setId(1);
		$response->setAppointmentId($appointmentId);
		$response->setUserId('member11');
		$response->setResponse('yes');

		$this->appointmentMapper->method('find')->with($appointmentId)->willReturn($appointment);
		$this->responseMapper->method('findByAppointment')->with($appointmentId)->willReturn([$response]);

		// member11 has since left the group and is no longer a target
		// attendee, but is not an admin either — their response stays.
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(false);
		$this->permissionService->method('canManageAppointments')->willReturn(false);

		$member11 = $this->createMock(\OCP\IUser::class);
		$member11->method('getDisplayName')->willReturn('Member 11');
		$this->userManager->method('get')->with('member11')->willReturn($member11);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		$result = $this->service->getAppointmentResponsesWithUsers($appointmentId, 'someManager');

		$this->assertCount(1, $result);
		$this->assertSame('Member 11', $result[0]['userName']);
	}

	/**
	 * Counterpart: a manager's response on an appointment they are not a
	 * target attendee of must still be filtered out.
	 */
	public function testGetAppointmentResponsesWithUsersFiltersNonAttendeeAdminResponse(): void {
		$appointmentId = 43;
		$appointment = new Appointment();
		$appointment->setId($appointmentId);

		$response = new AttendanceResponse();
		$response->setId(1);
		$response->setAppointmentId($appointmentId);
		$response->setUserId('admin1');
		$response->setResponse('yes');

		$this->appointmentMapper->method('find')->with($appointmentId)->willReturn($appointment);
		$this->responseMapper->method('findByAppointment')->with($appointmentId)->willReturn([$response]);

		$this->visibilityService->method('isUserTargetAttendee')->willReturn(false);
		$this->permissionService->method('canManageAppointments')->willReturn(true);

		$result = $this->service->getAppointmentResponsesWithUsers($appointmentId, 'someManager');

		$this->assertSame([], $result);
	}

	// --- paged list ---

	/**
	 * Everything listed is addressed to the user, so the scope lets it all
	 * through and the tests below only see paging and filters at work.
	 *
	 * @param list<Appointment> $upcoming
	 * @param list<Appointment> $past
	 */
	private function listAsAttendee(array $upcoming, array $past = []): void {
		$this->appointmentMapper->method('findUpcoming')->willReturn($upcoming);
		$this->appointmentMapper->method('findPast')->willReturn($past);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);
	}

	/**
	 * @param array{appointments: list<array<array-key, mixed>>} $page
	 * @return list<int>
	 */
	private function pageIds(array $page): array {
		return array_map(static fn (array $appointment): int => $appointment['id'], $page['appointments']);
	}

	private function answer(int $appointmentId, ?string $response): AttendanceResponse {
		$row = new AttendanceResponse();
		$row->setAppointmentId($appointmentId);
		$row->setUserId('alice');
		$row->setResponse($response);
		return $row;
	}

	public function testPageReturnsTheSliceAndCountsEverything(): void {
		$this->listAsAttendee(array_map(fn (int $id) => $this->createAppointment($id, "Meeting $id"), [1, 2, 3, 4, 5]));

		// The costly per-appointment work must stay with the page itself.
		$this->attachmentService->expects($this->once())
			->method('getAttachmentsForAppointments')
			->with([3, 4])
			->willReturn([]);

		$page = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire(limit: 2, offset: 2));

		$this->assertSame([3, 4], $this->pageIds($page));
		$this->assertSame(5, $page['total']);
	}

	/**
	 * The single appointment reads everybody's responses once and hands them
	 * to the summary and the check-in counts alike; the series size comes from
	 * the count query rather than from hydrating every sibling.
	 */
	public function testSingleAppointmentReadsResponsesOnceForSummaryAndCheckin(): void {
		$appointment = $this->createAppointment(7, 'Rehearsal');
		$appointment->setSeriesId('series-a');
		$this->appointmentMapper->method('find')->with(7)->willReturn($appointment);
		$this->visibilityService->method('canUserSeeAppointment')->willReturn(true);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);
		$this->responseMapper->method('findByAppointmentAndUser')->willReturn($this->answer(7, 'yes'));

		$answers = [$this->answer(7, 'yes')];
		$this->responseMapper->expects($this->once())->method('findByAppointment')->with(7)->willReturn($answers);
		$this->appointmentMapper->expects($this->once())->method('countBySeriesIds')->with(['series-a'])->willReturn(['series-a' => 3]);
		$this->appointmentMapper->expects($this->never())->method('findBySeriesId');
		$this->responseSummaryService->expects($this->once())
			->method('getResponseCounts')
			->with($appointment, $answers)
			->willReturn(['yes' => 1]);
		$this->checkinService->expects($this->once())
			->method('getCheckinSummary')
			->with($appointment, $answers)
			->willReturn(['attended' => 0, 'absent' => 0, 'notCheckedIn' => 1, 'hasCheckins' => false]);

		$data = $this->service->getAppointmentWithUserResponse(7, 'alice', includeResponseCounts: true);

		$this->assertSame(3, $data['seriesCount']);
		$this->assertSame(['yes' => 1], $data['responseSummary']);
		$this->assertSame(1, $data['checkinSummary']['notCheckedIn']);
	}

	/**
	 * What the rows of a page need — everybody's responses, attachments,
	 * series sizes — is read in bulk, and the summary gets the row's entity
	 * instead of re-reading it by id.
	 */
	public function testPageReadsPerRowDataInBulk(): void {
		$first = $this->createAppointment(1, 'First');
		$first->setSeriesId('series-a');
		$second = $this->createAppointment(2, 'Second');
		$second->setSeriesId('series-a');
		$this->listAsAttendee([$first, $second]);

		$this->responseMapper->expects($this->once())
			->method('findByAppointments')
			->with([1, 2])
			->willReturn([2 => [$this->answer(2, 'yes')]]);
		$this->attachmentService->expects($this->once())
			->method('getAttachmentsForAppointments')
			->with([1, 2])
			->willReturn([1 => [['fileId' => 7]]]);
		$this->appointmentMapper->expects($this->once())
			->method('countBySeriesIds')
			->with(['series-a'])
			->willReturn(['series-a' => 2]);
		$this->appointmentMapper->expects($this->never())->method('find');
		$this->appointmentMapper->expects($this->never())->method('findBySeriesId');
		$this->responseSummaryService->expects($this->exactly(2))
			->method('getResponseSummary')
			->willReturnCallback(static fn (Appointment $appointment, array $responses): array => ['yes' => count($responses)]);
		$this->checkinService->expects($this->exactly(2))
			->method('getCheckinSummary')
			->willReturn(['attended' => 0, 'absent' => 0, 'notCheckedIn' => 0, 'hasCheckins' => false]);

		$page = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire(limit: 20), includeResponseSummary: true);

		[$firstData, $secondData] = $page['appointments'];
		$this->assertSame(['yes' => 0], $firstData['responseSummary']);
		$this->assertSame(['yes' => 1], $secondData['responseSummary']);
		$this->assertSame([['fileId' => 7]], $firstData['attachments']);
		$this->assertSame([], $secondData['attachments']);
		$this->assertSame(2, $firstData['seriesCount']);
		$this->assertFalse($firstData['checkinSummary']['hasCheckins']);
	}

	/**
	 * Without a summary tier nobody's responses are read at all, and the
	 * check-in counts stay off the payload.
	 */
	public function testPageSkipsEverybodysResponsesWhenTheViewerGetsNoSummary(): void {
		$this->listAsAttendee([$this->createAppointment(1, 'Only one')]);

		$this->responseMapper->expects($this->never())->method('findByAppointments');
		$this->checkinService->expects($this->never())->method('getCheckinSummary');

		$page = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire(limit: 20));

		$this->assertArrayNotHasKey('responseSummary', $page['appointments'][0]);
		$this->assertArrayNotHasKey('checkinSummary', $page['appointments'][0]);
	}

	/**
	 * The scheduling filters ask once who holds a place where, instead of
	 * reading every row's responses one by one.
	 */
	public function testSchedulingFiltersReadTheBookingsOnce(): void {
		$this->listAsAttendee([$this->createAppointment(1, 'Booked'), $this->createAppointment(2, 'Not booked')]);
		$this->bookingService->expects($this->once())
			->method('bookedUserIdsFor')
			->with([1, 2])
			->willReturn([1 => ['alice']]);
		$this->bookingService->method('isScheduledIn')
			->willReturnCallback(static fn (Appointment $appointment, string $userId, ?array $booked): bool => in_array($userId, $booked ?? [], true));

		$page = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire(onlyScheduled: true, limit: 20));

		$this->assertSame([1], $this->pageIds($page));
	}

	public function testPageBeyondTheEndIsEmptyButKeepsTheTotal(): void {
		$this->listAsAttendee([$this->createAppointment(1, 'Only one')]);

		$page = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire(limit: 20, offset: 20));

		$this->assertSame([], $page['appointments']);
		$this->assertSame(1, $page['total']);
	}

	public function testUnpagedQueryReturnsEverything(): void {
		$this->listAsAttendee(array_map(fn (int $id) => $this->createAppointment($id, "Meeting $id"), [1, 2, 3]));

		$page = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire());

		$this->assertSame([1, 2, 3], $this->pageIds($page));
		$this->assertNull($page['unansweredCount']);
	}

	public function testAllTimeframeListsUpcomingBeforePast(): void {
		$this->listAsAttendee(
			[$this->createAppointment(1, 'Next week'), $this->createAppointment(2, 'Next month')],
			[$this->createAppointment(3, 'Yesterday'), $this->createAppointment(4, 'Last year')],
		);

		$page = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire(
			AppointmentListQuery::TIMEFRAME_ALL,
			limit: 20,
		));

		$this->assertSame([1, 2, 3, 4], $this->pageIds($page));
		$this->assertSame(
			[false, false, true, true],
			array_map(static fn (array $appointment): bool => $appointment['isPast'], $page['appointments']),
		);
	}

	public function testCancelledLastMovesCancelledAppointmentsBehindThePastOnes(): void {
		$cancelled = $this->createAppointment(1, 'Called off');
		$cancelled->setCancelledAt('2026-01-01 10:00:00');
		$this->listAsAttendee(
			[$cancelled, $this->createAppointment(2, 'Upcoming')],
			[$this->createAppointment(3, 'Past')],
		);

		$page = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire(
			AppointmentListQuery::TIMEFRAME_ALL,
			cancelledLast: true,
			limit: 20,
		));

		$this->assertSame([2, 3, 1], $this->pageIds($page));
	}

	public function testStatusFilterTellsOpenClosedAndCancelledApart(): void {
		$open = $this->createAppointment(1, 'Open');
		$closed = $this->createAppointment(2, 'Closed');
		$closed->setClosedAt('2026-01-01 10:00:00');
		// Cancelling closes the inquiry too; it must still only count as cancelled.
		$cancelled = $this->createAppointment(3, 'Cancelled');
		$cancelled->setClosedAt('2026-01-01 10:00:00');
		$cancelled->setCancelledAt('2026-01-01 10:00:00');
		$this->listAsAttendee([$open, $closed, $cancelled]);

		foreach (['open' => [1], 'closed' => [2], 'cancelled' => [3]] as $status => $expected) {
			$page = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire(status: $status, limit: 20));
			$this->assertSame($expected, $this->pageIds($page), $status);
		}
	}

	public function testResponseFilterMatchesTheUsersOwnAnswer(): void {
		$this->listAsAttendee(array_map(fn (int $id) => $this->createAppointment($id, "Meeting $id"), [1, 2, 3, 4]));
		$this->responseMapper->method('findByUserForAppointments')->willReturn([
			1 => $this->answer(1, 'yes'),
			2 => $this->answer(2, 'no'),
			// Checked in without ever answering: a row, but no response.
			3 => $this->answer(3, null),
		]);

		$yes = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire(response: 'yes', limit: 20));
		$none = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire(response: 'none', limit: 20));

		$this->assertSame([1], $this->pageIds($yes));
		$this->assertSame([3, 4], $this->pageIds($none));
	}

	public function testSearchMatchesNameAndDescriptionIgnoringCase(): void {
		$byName = $this->createAppointment(1, 'Sommerkonzert');
		$byDescription = $this->createAppointment(2, 'Probe');
		$byDescription->setDescription('Generalprobe für das KONZERT');
		$this->listAsAttendee([$byName, $byDescription, $this->createAppointment(3, 'Vorstandssitzung')]);

		$page = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire(search: ' konzert ', limit: 20));

		$this->assertSame([1, 2], $this->pageIds($page));
		$this->assertSame(2, $page['total']);
	}

	public function testLocationAndCategoryFiltersKeepTheOtherOptionsOnOffer(): void {
		$hall = $this->createAppointment(1, 'In the hall');
		$hall->setLocation('Hall');
		$hall->setCategoryId(7);
		$church = $this->createAppointment(2, 'In the church');
		$church->setLocation('Church');
		$church->setCategoryId(4);
		$this->listAsAttendee([$hall, $church, $this->createAppointment(3, 'Nowhere in particular')]);

		$byLocation = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire(locations: ['Hall'], limit: 20));
		$byCategory = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire(categoryIds: [4], limit: 20));

		$this->assertSame([1], $this->pageIds($byLocation));
		$this->assertSame([2], $this->pageIds($byCategory));
		// A facet that shrank with its own filter could never be widened again.
		$this->assertSame(['Church', 'Hall'], $byLocation['facets']['locations']);
		$this->assertSame([7, 4], $byLocation['facets']['categoryIds']);
	}

	/**
	 * A see-all holder looking at three appointments: one they are invited to,
	 * one they organize, one that is none of their business.
	 */
	private function listWithMixedRoles(): void {
		$this->appointmentMapper->method('findUpcoming')->willReturn([
			$this->createAppointment(1, 'Invited'),
			$this->createAppointment(2, 'Organizing'),
			$this->createAppointment(3, 'Somebody else'),
		]);
		$this->permissionService->method('canSeeAppointmentViaSeeAll')->willReturn(true);
		$this->visibilityService->method('isUserTargetAttendee')
			->willReturnCallback(static fn (Appointment $appointment): bool => $appointment->getId() === 1);
		$this->permissionService->method('isOrganizer')
			->willReturnCallback(static fn (Appointment $appointment): bool => $appointment->getId() === 2);
	}

	public function testRoleFilterKeepsTheAppointmentsOfThatRole(): void {
		$this->listWithMixedRoles();

		foreach (['attendee' => [1], 'organizer' => [2], 'uninvolved' => [3]] as $role => $expected) {
			$page = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire(role: $role, limit: 20));
			$this->assertSame($expected, $this->pageIds($page), $role);
			$this->assertSame(['attendee', 'organizer', 'uninvolved'], $page['facets']['roles']);
		}
	}

	public function testRoleThatDoesNotSplitTheListIsNeitherOfferedNorApplied(): void {
		// Invited everywhere and organizing nothing: no role tells anything apart.
		$this->listAsAttendee([$this->createAppointment(1, 'First'), $this->createAppointment(2, 'Second')]);

		$page = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire(role: 'organizer', limit: 20));

		$this->assertSame([], $page['facets']['roles']);
		// A stored filter the client no longer shows must not empty the list.
		$this->assertSame([1, 2], $this->pageIds($page));
	}

	public function testUnansweredCountIgnoresTheFiltersOfTheQuery(): void {
		$closed = $this->createAppointment(3, 'Closed, never answered');
		$closed->setClosedAt('2026-01-01 10:00:00');
		$cancelled = $this->createAppointment(4, 'Cancelled, never answered');
		$cancelled->setCancelledAt('2026-01-01 10:00:00');
		$this->listAsAttendee([
			$this->createAppointment(1, 'Open, unanswered'),
			$this->createAppointment(2, 'Open, answered'),
			$closed,
			$cancelled,
		]);
		$this->responseMapper->method('findByUserForAppointments')->willReturn([2 => $this->answer(2, 'yes')]);

		$page = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire(search: 'nothing matches this', limit: 20));

		$this->assertSame(0, $page['total']);
		$this->assertSame(1, $page['unansweredCount']);
	}

	public function testUnansweredCountIsLeftOutWhenOnlyPastAppointmentsAreListed(): void {
		$this->listAsAttendee([$this->createAppointment(1, 'Upcoming')], [$this->createAppointment(2, 'Past')]);

		$page = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire(
			AppointmentListQuery::TIMEFRAME_PAST,
			limit: 20,
		));

		$this->assertNull($page['unansweredCount']);
	}

	public function testUnansweredOnlyPageDropsWhatTheUserAnswered(): void {
		$this->listAsAttendee(array_map(fn (int $id) => $this->createAppointment($id, "Meeting $id"), [1, 2, 3]));
		$this->responseMapper->method('findByUserForAppointments')->willReturn([2 => $this->answer(2, 'maybe')]);

		$page = $this->service->getAppointmentPage('alice', AppointmentListQuery::fromWire(unansweredOnly: true, limit: 20));

		$this->assertSame([1, 3], $this->pageIds($page));
		$this->assertSame(2, $page['unansweredCount']);
	}

	public function testListReadsTheUsersResponsesInOneQuery(): void {
		$this->listAsAttendee(array_map(fn (int $id) => $this->createAppointment($id, "Meeting $id"), [1, 2, 3]));

		$this->responseMapper->expects($this->once())
			->method('findByUserForAppointments')
			->with('alice', [1, 2, 3])
			->willReturn([1 => $this->answer(1, 'yes')]);
		$this->responseMapper->expects($this->never())->method('findByAppointmentAndUser');

		$appointments = $this->service->getAppointmentsWithUserResponses('alice');

		$this->assertSame('yes', $appointments[0]['userResponse']['response']);
		$this->assertNull($appointments[1]['userResponse']);
	}

	// --- navigation ---

	public function testNavigationKeepsOnlyOwnAppointmentsAndFlagsTheAudience(): void {
		$invited = $this->createAppointment(1, 'Invited');
		$organized = $this->createAppointment(2, 'Organized');
		$organized->setOrganizers(json_encode(['alice']));
		$foreign = $this->createAppointment(3, 'Somebody else');

		$this->appointmentMapper->method('findUpcoming')->willReturn([$invited, $organized, $foreign]);
		$this->appointmentMapper->method('findPast')->willReturn([]);
		$this->visibilityService->method('isUserTargetAttendee')
			->willReturnCallback(static fn (Appointment $appointment): bool => $appointment->getId() === 1);
		$this->permissionService->method('isOrganizer')
			->willReturnCallback(static fn (Appointment $appointment): bool => $appointment->getId() === 2);
		$this->responseMapper->method('findByUserForAppointments')->willReturn([1 => $this->answer(1, 'yes')]);

		$navigation = $this->service->getAppointmentsForNavigation('alice');

		$this->assertSame([1, 2], array_column($navigation['current'], 'id'));
		$this->assertSame([true, false], array_column($navigation['current'], 'inAudience'));
		$this->assertSame(['response' => 'yes'], $navigation['current'][0]['userResponse']);
		$this->assertNull($navigation['current'][1]['userResponse']);
		$this->assertFalse($navigation['pastHasMore']);
	}

	public function testNavigationPagesThePastEntries(): void {
		$past = array_map(fn (int $id) => $this->createAppointment($id, "Past $id"), [1, 2, 3]);

		$this->appointmentMapper->method('findUpcoming')->willReturn([]);
		$this->appointmentMapper->method('findPast')->willReturn($past);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);

		$first = $this->service->getAppointmentsForNavigation('alice', 2);
		$second = $this->service->getAppointmentsForNavigation('alice', 2, 2);

		$this->assertSame([1, 2], array_column($first['past'], 'id'));
		$this->assertTrue($first['pastHasMore']);
		$this->assertSame([3], array_column($second['past'], 'id'));
		$this->assertFalse($second['pastHasMore']);
	}

	public function testNavigationStopsReadingPastAppointmentsOnceThePageIsFull(): void {
		// A full first chunk of other people's appointments, then the user's own.
		$foreign = array_map(fn (int $id) => $this->createAppointment($id, 'Foreign'), range(1, 200));
		$own = array_map(fn (int $id) => $this->createAppointment($id, 'Own'), [201, 202]);

		$this->appointmentMapper->method('findUpcoming')->willReturn([]);
		$this->appointmentMapper->expects($this->exactly(2))
			->method('findPast')
			->willReturnCallback(static fn (?int $limit, int $offset): array => match ($offset) {
				0 => $foreign,
				200 => $own,
			});
		$this->visibilityService->method('isUserTargetAttendee')
			->willReturnCallback(static fn (Appointment $appointment): bool => $appointment->getId() > 200);

		$navigation = $this->service->getAppointmentsForNavigation('alice', 20);

		$this->assertSame([201, 202], array_column($navigation['past'], 'id'));
		$this->assertFalse($navigation['pastHasMore']);
	}
}
