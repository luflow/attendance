<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Service\AuditEventService;
use OCA\Attendance\Service\CapacityService;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\NotificationService;
use OCA\Attendance\Service\ResponsePolicyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ResponsePolicyServiceTest extends TestCase {
	/** @var ConfigService|MockObject */
	private $configService;

	/** @var AttendanceResponseMapper|MockObject */
	private $responseMapper;

	private ResponsePolicyService $service;

	protected function setUp(): void {
		$this->configService = $this->createMock(ConfigService::class);
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);
		// The real capacity rules, on a mocked queue.
		$this->service = new ResponsePolicyService(
			$this->configService,
			new CapacityService(
				$this->responseMapper,
				$this->createMock(NotificationService::class),
				$this->createMock(AuditEventService::class),
			),
		);
	}

	private function appointment(?bool $allowMaybe, ?int $maxAttendees = null, bool $waitlist = true): Appointment {
		$appointment = new Appointment();
		$appointment->setId(1);
		$appointment->setAllowMaybe($allowMaybe);
		$appointment->setMaxAttendees($maxAttendees);
		$appointment->setWaitlistEnabled($waitlist);
		return $appointment;
	}

	/** Fill the queue with $count yes-responses from distinct people. */
	private function queueOf(int $count): void {
		$rows = [];
		for ($i = 0; $i < $count; $i++) {
			$response = new AttendanceResponse();
			$response->setUserId('user' . $i);
			$response->setResponse('yes');
			$rows[] = $response;
		}
		$this->responseMapper->method('findClaimedSpots')->willReturn($rows);
	}

	public function testUndecidedAppointmentFollowsTheInstanceDefault(): void {
		$this->configService->method('isMaybeAllowed')->willReturn(false);

		$this->assertFalse($this->service->isMaybeAllowed($this->appointment(null)));
	}

	public function testAppointmentOverridesTheInstanceDefaultBothWays(): void {
		$this->configService->method('isMaybeAllowed')->willReturn(false);

		$this->assertTrue($this->service->isMaybeAllowed($this->appointment(true)));
		$this->assertFalse($this->service->isMaybeAllowed($this->appointment(false)));
	}

	public function testMaybeIsRejectedWhereTheAppointmentDoesNotOfferIt(): void {
		$this->configService->method('isMaybeAllowed')->willReturn(true);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->assertResponseAllowed($this->appointment(false), 'maybe');
	}

	public function testYesAndNoSurviveWithoutMaybe(): void {
		$this->configService->method('isMaybeAllowed')->willReturn(true);
		$appointment = $this->appointment(false);

		$this->service->assertResponseAllowed($appointment, 'yes');
		$this->service->assertResponseAllowed($appointment, 'no');
		$this->addToAssertionCount(2);
	}

	public function testWithdrawingIsAlwaysAllowed(): void {
		$this->configService->method('isMaybeAllowed')->willReturn(false);

		$this->service->assertResponseAllowed($this->appointment(false), null);
		$this->addToAssertionCount(1);
	}

	public function testUnknownAnswerIsRejected(): void {
		$this->configService->method('isMaybeAllowed')->willReturn(true);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->assertResponseAllowed($this->appointment(true), 'perhaps');
	}

	public function testALimitTakesMaybeAwayWhateverTheAppointmentSays(): void {
		$this->configService->method('isMaybeAllowed')->willReturn(true);

		$this->assertFalse($this->service->isMaybeAllowed($this->appointment(true, 10)));
	}

	public function testAFreshYesIsTurnedAwayFromAFullAppointment(): void {
		$this->configService->method('isMaybeAllowed')->willReturn(true);
		$this->queueOf(4);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('full');
		$this->service->assertResponseAllowed($this->appointment(null, 4), 'yes', null);
	}

	public function testAFreshYesIsAcceptedWhenTheWaitlistIsAccepted(): void {
		$this->configService->method('isMaybeAllowed')->willReturn(true);
		$this->queueOf(4);

		$this->service->assertResponseAllowed($this->appointment(null, 4), 'yes', null, true);
		$this->addToAssertionCount(1);
	}

	public function testAFullAppointmentWithoutAWaitlistTurnsAwayEvenAWillingWaiter(): void {
		$this->configService->method('isMaybeAllowed')->willReturn(true);
		$this->queueOf(4);

		$this->expectException(\RuntimeException::class);
		$this->service->assertResponseAllowed($this->appointment(null, 4, false), 'yes', null, true);
	}

	public function testResavingAYesIsNeverTurnedAway(): void {
		$this->configService->method('isMaybeAllowed')->willReturn(true);
		$this->queueOf(4);

		// Somebody already in the queue editing their comment must not be
		// rejected just because the appointment filled up meanwhile.
		$this->service->assertResponseAllowed($this->appointment(null, 4), 'yes', 'yes');
		$this->addToAssertionCount(1);
	}

	public function testAnOrganizerMayOverbook(): void {
		$this->configService->method('isMaybeAllowed')->willReturn(true);
		$this->queueOf(4);

		$this->service->assertResponseAllowed($this->appointment(null, 4), 'yes', null, false, true);
		$this->addToAssertionCount(1);
	}

	public function testNoAndWithdrawAreNeverBlockedByAFullAppointment(): void {
		$this->configService->method('isMaybeAllowed')->willReturn(true);
		$this->queueOf(4);
		$appointment = $this->appointment(null, 4);

		$this->service->assertResponseAllowed($appointment, 'no', null);
		$this->service->assertResponseAllowed($appointment, null, 'yes');
		$this->addToAssertionCount(2);
	}
}
