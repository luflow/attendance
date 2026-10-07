<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Service\AppointmentSerializer;
use OCA\Attendance\Service\AuditEventService;
use OCA\Attendance\Service\CapacityService;
use OCA\Attendance\Service\CheckinService;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\GuestService;
use OCA\Attendance\Service\VisibilityService;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CheckinServiceTest extends TestCase {
	private AppointmentMapper|MockObject $appointmentMapper;
	private AttendanceResponseMapper|MockObject $responseMapper;
	private VisibilityService|MockObject $visibilityService;
	private CheckinService $service;

	protected function setUp(): void {
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);
		$this->visibilityService = $this->createMock(VisibilityService::class);

		$this->service = new CheckinService(
			$this->appointmentMapper,
			$this->responseMapper,
			$this->createMock(ConfigService::class),
			$this->visibilityService,
			$this->createMock(IGroupManager::class),
			$this->createMock(GuestService::class),
			$this->createMock(AuditEventService::class),
			$this->createMock(CapacityService::class),
			$this->createMock(AppointmentSerializer::class),
		);
	}

	private function user(string $uid): IUser|MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function checkin(string $userId, string $state): AttendanceResponse {
		$response = new AttendanceResponse();
		$response->setUserId($userId);
		$response->setCheckinState($state);
		return $response;
	}

	/**
	 * The list hands over the row's entity and its responses; the summary
	 * counts the audience by check-in state without reading anything itself.
	 */
	public function testSummaryCountsTheAudienceByCheckinState(): void {
		$appointment = new Appointment();
		$appointment->setId(7);
		$this->visibilityService->method('getTargetAttendees')->willReturn([
			'alice' => $this->user('alice'),
			'bob' => $this->user('bob'),
			'carol' => $this->user('carol'),
			'dave' => $this->user('dave'),
		]);
		$this->appointmentMapper->expects($this->never())->method('find');
		$this->responseMapper->expects($this->never())->method('findByAppointment');

		$summary = $this->service->getCheckinSummary($appointment, [
			$this->checkin('alice', 'yes'),
			$this->checkin('bob', 'no'),
			$this->checkin('carol', ''),
			// Checked in, but no longer in the audience — not counted.
			$this->checkin('mallory', 'yes'),
		]);

		$this->assertSame(['attended' => 1, 'absent' => 1, 'notCheckedIn' => 2, 'hasCheckins' => true], $summary);
	}
}
