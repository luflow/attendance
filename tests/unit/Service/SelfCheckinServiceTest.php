<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Audit\Verb;
use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Service\AuditEventService;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\SelfCheckinService;
use OCA\Attendance\Service\VisibilityService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SelfCheckinServiceTest extends TestCase {
	private AppointmentMapper|MockObject $appointmentMapper;
	private AttendanceResponseMapper|MockObject $responseMapper;
	private VisibilityService|MockObject $visibilityService;
	private ConfigService|MockObject $configService;
	private AuditEventService|MockObject $auditEventService;
	private LoggerInterface|MockObject $logger;
	private SelfCheckinService $service;

	protected function setUp(): void {
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);
		$this->visibilityService = $this->createMock(VisibilityService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->auditEventService = $this->createMock(AuditEventService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new SelfCheckinService(
			$this->appointmentMapper,
			$this->responseMapper,
			$this->visibilityService,
			$this->configService,
			$this->auditEventService,
			$this->logger,
		);

		$this->configService->method('getSelfCheckinWindowMinutes')->willReturn(30);
	}

	private function makeAppointment(
		int $id,
		string $start,
		string $end,
		bool $active = true,
		?string $cancelledAt = null,
	): Appointment {
		$appointment = new Appointment();
		$appointment->setId($id);
		$appointment->setName('Training');
		$appointment->setStartDatetime($start);
		$appointment->setEndDatetime($end);
		$appointment->setIsActive($active ? 1 : 0);
		$appointment->setCancelledAt($cancelledAt);
		return $appointment;
	}

	/** An appointment currently inside the default 30-minute window. */
	private function makeCurrentAppointment(int $id = 1): Appointment {
		return $this->makeAppointment(
			$id,
			gmdate('Y-m-d H:i:s', time() - 600),
			gmdate('Y-m-d H:i:s', time() + 3600),
		);
	}

	public function testSelfCheckinRejectsInvalidMethod(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid check-in method.');

		$this->service->selfCheckin(1, 'alice', 'manual');
	}

	public function testSelfCheckinViaQrSetsSourceAndRecordsAudit(): void {
		$appointment = $this->makeCurrentAppointment();
		$this->appointmentMapper->method('find')->willReturn($appointment);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);
		$this->responseMapper->method('findByAppointmentAndUser')
			->willThrowException(new DoesNotExistException('no row'));

		$inserted = null;
		$this->responseMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (AttendanceResponse $response) use (&$inserted) {
				$inserted = $response;
				return $response;
			});

		$this->auditEventService->expects($this->once())
			->method('record')
			->with(
				Verb::CHECKIN_RECORDED,
				1,
				'alice',
				'alice',
				['checkinState' => 'yes', 'method' => 'qr'],
				Verb::SOURCE_SELF_CHECKIN,
			);

		$result = $this->service->selfCheckin(1, 'alice', 'qr');

		$this->assertSame('yes', $result['checkinState']);
		$this->assertFalse($result['alreadyCheckedIn']);
		$this->assertSame('self_qr', $inserted->getCheckinSource());
		$this->assertSame('alice', $inserted->getCheckinBy());
	}

	public function testSelfCheckinViaNfcSetsNfcSource(): void {
		$appointment = $this->makeCurrentAppointment();
		$this->appointmentMapper->method('find')->willReturn($appointment);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);
		$this->responseMapper->method('findByAppointmentAndUser')
			->willThrowException(new DoesNotExistException('no row'));
		$this->responseMapper->method('insert')->willReturnArgument(0);

		$this->auditEventService->expects($this->once())
			->method('record')
			->with(
				Verb::CHECKIN_RECORDED,
				1,
				'alice',
				'alice',
				['checkinState' => 'yes', 'method' => 'nfc'],
				Verb::SOURCE_SELF_CHECKIN,
			);

		$this->service->selfCheckin(1, 'alice', 'nfc');
	}

	public function testSelfCheckinAlreadyCheckedInReturnsEarlyWithoutAudit(): void {
		$appointment = $this->makeCurrentAppointment();
		$this->appointmentMapper->method('find')->willReturn($appointment);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);

		$response = new AttendanceResponse();
		$response->setId(5);
		$response->setAppointmentId(1);
		$response->setUserId('alice');
		$response->setCheckinState('yes');
		$response->setCheckinAt(gmdate('Y-m-d H:i:s'));
		$this->responseMapper->method('findByAppointmentAndUser')->willReturn($response);

		$this->responseMapper->expects($this->never())->method('update');
		$this->responseMapper->expects($this->never())->method('insert');
		$this->auditEventService->expects($this->never())->method('record');

		$result = $this->service->selfCheckin(1, 'alice', 'qr');

		$this->assertTrue($result['alreadyCheckedIn']);
	}

	public function testSelfCheckinRespectsConfiguredWindow(): void {
		// Appointment starts in 20 minutes — inside the default 30-minute
		// window, but outside a reconfigured 10-minute window.
		$configService = $this->createMock(ConfigService::class);
		$configService->method('getSelfCheckinWindowMinutes')->willReturn(10);
		$service = new SelfCheckinService(
			$this->appointmentMapper,
			$this->responseMapper,
			$this->visibilityService,
			$configService,
			$this->auditEventService,
			$this->logger,
		);

		$appointment = $this->makeAppointment(
			1,
			gmdate('Y-m-d H:i:s', time() + 1200),
			gmdate('Y-m-d H:i:s', time() + 4800),
		);
		$this->appointmentMapper->method('find')->willReturn($appointment);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Appointment is not within the check-in time window.');

		$service->selfCheckin(1, 'alice', 'qr');
	}

	public function testSelfCheckinRejectsCancelledAppointment(): void {
		$appointment = $this->makeAppointment(
			1,
			gmdate('Y-m-d H:i:s', time() - 600),
			gmdate('Y-m-d H:i:s', time() + 3600),
			true,
			gmdate('Y-m-d H:i:s'),
		);
		$this->appointmentMapper->method('find')->willReturn($appointment);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Appointment is not active.');

		$this->service->selfCheckin(1, 'alice', 'qr');
	}

	public function testSelfCheckinRejectsNonAttendee(): void {
		$appointment = $this->makeCurrentAppointment();
		$this->appointmentMapper->method('find')->willReturn($appointment);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(false);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('You are not an attendee of this appointment.');

		$this->service->selfCheckin(1, 'alice', 'qr');
	}

	public function testGetOverviewListsInWindowAppointmentsWithoutNextUpcoming(): void {
		$current = $this->makeCurrentAppointment(1);

		$this->appointmentMapper->method('findActiveInWindow')->with(30)->willReturn([$current]);
		// nextUpcoming is only computed when nothing is in the window.
		$this->appointmentMapper->expects($this->never())->method('findUpcomingOutsideWindow');
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);
		$this->responseMapper->method('findByAppointmentAndUser')
			->willThrowException(new DoesNotExistException('no row'));

		$overview = $this->service->getOverview('alice');

		$this->assertCount(1, $overview['appointments']);
		$this->assertSame(1, $overview['appointments'][0]['id']);
		$this->assertFalse($overview['appointments'][0]['alreadyCheckedIn']);
		$this->assertNull($overview['nextUpcoming']);
	}

	public function testGetOverviewReturnsNextUpcomingWhenNothingInWindow(): void {
		$next = $this->makeAppointment(
			2,
			gmdate('Y-m-d H:i:s', time() + 7200),
			gmdate('Y-m-d H:i:s', time() + 10800),
		);

		$this->appointmentMapper->method('findActiveInWindow')->willReturn([]);
		$this->appointmentMapper->method('findUpcomingOutsideWindow')->with(30)->willReturn([$next]);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(true);

		$overview = $this->service->getOverview('alice');

		$this->assertSame([], $overview['appointments']);
		$this->assertNotNull($overview['nextUpcoming']);
		$this->assertSame(2, $overview['nextUpcoming']['id']);
		// Datetimes must carry the UTC marker like the rest of the API —
		// naive strings get misread as local time by the mobile client.
		$start = new \DateTime($next->getStartDatetime(), new \DateTimeZone('UTC'));
		$this->assertSame($start->format('Y-m-d\TH:i:s\Z'), $overview['nextUpcoming']['startDatetime']);
		$this->assertSame(
			$start->modify('-30 minutes')->format('Y-m-d\TH:i:s\Z'),
			$overview['nextUpcoming']['checkinWindowStartsAt'],
		);
	}

	public function testGetOverviewSkipsInvisibleAppointments(): void {
		$invisible = $this->makeCurrentAppointment(2);

		$this->appointmentMapper->method('findActiveInWindow')->willReturn([$invisible]);
		$this->appointmentMapper->method('findUpcomingOutsideWindow')->willReturn([]);
		$this->visibilityService->method('isUserTargetAttendee')->willReturn(false);

		$overview = $this->service->getOverview('alice');

		$this->assertSame([], $overview['appointments']);
		$this->assertNull($overview['nextUpcoming']);
	}
}
