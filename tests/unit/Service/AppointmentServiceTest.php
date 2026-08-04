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
use OCA\Attendance\Service\AppointmentService;
use OCA\Attendance\Service\AttachmentService;
use OCA\Attendance\Service\AuditEventService;
use OCA\Attendance\Service\BookingService;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\GuestService;
use OCA\Attendance\Service\NotificationService;
use OCA\Attendance\Service\OrgCalendarSyncService;
use OCA\Attendance\Service\PermissionService;
use OCA\Attendance\Service\ResponseSummaryService;
use OCA\Attendance\Service\VisibilityService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Collaboration\Collaborators\ISearch as ICollaboratorSearch;
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

	/** @var ICollaboratorSearch|MockObject */
	private $collaboratorSearch;

	/** @var IAppManager|MockObject */
	private $appManager;

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
		$this->collaboratorSearch = $this->createMock(ICollaboratorSearch::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->guestService = $this->createMock(GuestService::class);
		$this->auditEventService = $this->createMock(AuditEventService::class);
		$this->bookingService = $this->createMock(BookingService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->orgCalendarSyncService = $this->createMock(OrgCalendarSyncService::class);
		$this->categoryMapper = $this->createMock(CategoryMapper::class);

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
			$this->collaboratorSearch,
			$this->appManager,
			$this->guestService,
			$this->auditEventService,
			$this->bookingService,
			$this->permissionService,
			$this->orgCalendarSyncService,
			$this->categoryMapper,
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
			->with(Verb::APPOINTMENT_CREATED, 1, Verb::SOURCE_APP);

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
			->with(Verb::APPOINTMENT_CLOSED, 5, Verb::SOURCE_APP);

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
			->with(Verb::APPOINTMENT_REOPENED, 7, Verb::SOURCE_APP);

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
			->with(Verb::APPOINTMENT_CANCELLED, 8, Verb::SOURCE_APP);

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

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with(9)
			->willReturn($appointment);
		$this->appointmentMapper->expects($this->once())
			->method('update')
			->willReturnArgument(0);

		$this->auditEventService->expects($this->once())
			->method('recordAppointmentLifecycle')
			->with(Verb::APPOINTMENT_UNCANCELLED, 9, Verb::SOURCE_APP);

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

		$this->service->uncancelAppointment(9);
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
		$this->attachmentService->method('getAttachments')->willReturn([]);
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
		// A manager sees every appointment by default; the relevance filter must
		// still cut those the manager is not part of the audience for.
		$foreign = $this->createAppointment(1, 'Bob only');

		$this->appointmentMapper->method('findUpcoming')->willReturn([$foreign]);
		$this->visibilityService->method('canUserSeeAppointment')->willReturn(true);
		$this->visibilityService->expects($this->once())
			->method('isUserTargetAttendee')
			->willReturn(false);
		$this->attachmentService->method('getAttachments')->willReturn([]);

		$this->assertSame([], $this->service->getAppointmentsWithUserResponses(
			'alice',
			notScheduledOut: true,
		));
	}
}
