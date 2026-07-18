<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentAttachmentMapper;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Db\IcalTokenMapper;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\IcalService;
use OCA\Attendance\Service\VisibilityService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

class IcalServiceTest extends TestCase {
	private ConfigService $configService;
	private AppointmentAttachmentMapper $attachmentMapper;
	private IcalService $service;

	protected function setUp(): void {
		$this->configService = $this->createMock(ConfigService::class);
		$this->attachmentMapper = $this->createMock(AppointmentAttachmentMapper::class);
		$this->attachmentMapper->method('findByAppointment')->willReturn([]);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturn('https://example.test/');
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.test/app');

		$this->service = new IcalService(
			$this->createMock(IcalTokenMapper::class),
			$this->createMock(AppointmentMapper::class),
			$this->attachmentMapper,
			$this->createMock(AttendanceResponseMapper::class),
			$this->createMock(VisibilityService::class),
			$this->configService,
			$this->createMock(ISecureRandom::class),
			$urlGenerator,
			$this->createMock(IL10NFactory::class),
			$this->createMock(IConfig::class),
		);
	}

	private function appointment(): Appointment {
		$a = new Appointment();
		$a->setId(5);
		$a->setName('Team sync');
		$a->setDescription('');
		$a->setStartDatetime('2026-07-20 10:00:00');
		$a->setEndDatetime('2026-07-20 11:00:00');
		$a->setCreatedAt('2026-07-01 09:00:00');
		$a->setUpdatedAt('2026-07-01 09:00:00');
		return $a;
	}

	private function generate(Appointment $appointment, ?AttendanceResponse $response): string {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$ref = new \ReflectionMethod(IcalService::class, 'generateVEvent');
		return $ref->invoke($this->service, $appointment, $response, 'alice', $l, 'example.test', []);
	}

	private function yes(?string $booking): AttendanceResponse {
		$r = new AttendanceResponse();
		$r->setId(1);
		$r->setAppointmentId(5);
		$r->setUserId('alice');
		$r->setResponse('yes');
		$r->setBookingStatus($booking);
		$r->setRespondedAt('2026-07-02 09:00:00');
		return $r;
	}

	public function testPlannedInIsBusyWithTitleMarker(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$out = $this->generate($this->appointment(), $this->yes('booked'));
		$this->assertStringContainsString('Scheduled', $out);
		$this->assertStringContainsString('TRANSP:OPAQUE', $out);
	}

	public function testNotPlannedInIsFreeWithTitleMarker(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$out = $this->generate($this->appointment(), $this->yes(null));
		$this->assertStringContainsString('Not scheduled', $out);
		$this->assertStringContainsString('TRANSP:TRANSPARENT', $out);
	}

	public function testBookingIgnoredWhenFeatureDisabled(): void {
		$this->configService->method('isBookingEnabled')->willReturn(false);
		$out = $this->generate($this->appointment(), $this->yes('booked'));
		// Falls back to the plain response suffix and the yes=busy default.
		$this->assertStringContainsString('Yes', $out);
		$this->assertStringNotContainsString('Scheduled', $out);
		$this->assertStringContainsString('TRANSP:OPAQUE', $out);
	}

	public function testNoColorPropertyEmitted(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$out = $this->generate($this->appointment(), $this->yes('booked'));
		// COLOR is deliberately not used (Google ignores it in subscribed feeds).
		$this->assertStringNotContainsString('COLOR', $out);
	}
}
