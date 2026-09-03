<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\ResponsePolicyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ResponsePolicyServiceTest extends TestCase {
	/** @var ConfigService|MockObject */
	private $configService;

	private ResponsePolicyService $service;

	protected function setUp(): void {
		$this->configService = $this->createMock(ConfigService::class);
		$this->service = new ResponsePolicyService($this->configService);
	}

	private function appointment(?bool $allowMaybe): Appointment {
		$appointment = new Appointment();
		$appointment->setAllowMaybe($allowMaybe);
		return $appointment;
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
}
