<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Service\AuditEventService;
use OCA\Attendance\Service\CapacityService;
use OCA\Attendance\Service\CommentRequiredException;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\GuestService;
use OCA\Attendance\Service\NotificationService;
use OCA\Attendance\Service\OrgCalendarSyncService;
use OCA\Attendance\Service\ResponsePolicyService;
use OCA\Attendance\Service\ResponseService;
use OCA\Attendance\Service\TalkRoomService;
use OCA\Attendance\Service\VisibilityService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The quick-response links are the second writer of a response, next to
 * AppointmentService::applyResponse(). These cover the rules that have to hold
 * on both paths — a gate that only guards the app would be a hole here.
 */
class ResponseServiceTest extends TestCase {
	/** @var AppointmentMapper|MockObject */
	private $appointmentMapper;

	/** @var AttendanceResponseMapper|MockObject */
	private $responseMapper;

	/** @var ConfigService|MockObject */
	private $configService;

	private ResponseService $service;

	protected function setUp(): void {
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->configService->method('isMaybeAllowed')->willReturn(true);
		$capacityService = new CapacityService(
			$this->responseMapper,
			$this->createMock(NotificationService::class),
			$this->createMock(AuditEventService::class),
		);
		// Quick-response links only ever reach people in the audience.
		$visibilityService = $this->createMock(VisibilityService::class);
		$visibilityService->method('isUserTargetAttendee')->willReturn(true);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$this->service = new ResponseService(
			$this->appointmentMapper,
			$this->responseMapper,
			$visibilityService,
			$this->createMock(NotificationService::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(GuestService::class),
			$this->createMock(AuditEventService::class),
			$this->createMock(OrgCalendarSyncService::class),
			$this->createMock(TalkRoomService::class),
			new ResponsePolicyService($this->configService, $capacityService, $visibilityService, $l),
			$capacityService,
		);
	}

	public function testQuickLinkCannotAnswerMaybeWhereTheAppointmentDoesNotOfferIt(): void {
		$appointment = new Appointment();
		$appointment->setId(7);
		$appointment->setAllowMaybe(false);

		$this->appointmentMapper->method('find')->with(7)->willReturn($appointment);
		$this->responseMapper->expects($this->never())->method('insert');
		$this->responseMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('maybe');
		$this->service->submitResponse(7, 'alice', 'maybe');
	}

	public function testQuickLinkStillAnswersYesOnAnAppointmentWithoutMaybe(): void {
		$appointment = new Appointment();
		$appointment->setId(7);
		$appointment->setAllowMaybe(false);

		$this->appointmentMapper->method('find')->with(7)->willReturn($appointment);
		$this->responseMapper->method('findByAppointmentAndUser')
			->willThrowException(new DoesNotExistException('none'));
		$this->responseMapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$this->assertSame('yes', $this->service->submitResponse(7, 'alice', 'yes')->getResponse());
	}

	public function testQuickLinkAnswersMaybeWhereTheAppointmentOffersIt(): void {
		$appointment = new Appointment();
		$appointment->setId(7);
		$appointment->setAllowMaybe(true);

		$this->appointmentMapper->method('find')->with(7)->willReturn($appointment);
		$this->responseMapper->method('findByAppointmentAndUser')
			->willThrowException(new DoesNotExistException('none'));
		$this->responseMapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$this->assertSame('maybe', $this->service->submitResponse(7, 'alice', 'maybe')->getResponse());
	}

	public function testQuickLinkCannotAnswerNoWithoutTheReasonTheInstanceAsksFor(): void {
		$this->configService->method('isCommentRequiredForDecline')->willReturn(true);
		$appointment = new Appointment();
		$appointment->setId(7);

		$this->appointmentMapper->method('find')->with(7)->willReturn($appointment);
		$this->responseMapper->method('findByAppointmentAndUser')
			->willThrowException(new DoesNotExistException('none'));
		$this->responseMapper->expects($this->never())->method('insert');

		$this->expectException(CommentRequiredException::class);
		$this->service->submitResponse(7, 'alice', 'no', '', ResponseService::SOURCE_QUICK_LINK);
	}

	public function testQuickLinkStoresTheReasonGivenOnTheConfirmationPage(): void {
		$this->configService->method('isCommentRequiredForDecline')->willReturn(true);
		$appointment = new Appointment();
		$appointment->setId(7);

		$this->appointmentMapper->method('find')->with(7)->willReturn($appointment);
		$this->responseMapper->method('findByAppointmentAndUser')
			->willThrowException(new DoesNotExistException('none'));
		$this->responseMapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$saved = $this->service->submitResponse(7, 'alice', 'no', 'Away on business', ResponseService::SOURCE_QUICK_LINK);
		$this->assertSame('Away on business', $saved->getComment());
	}
}
