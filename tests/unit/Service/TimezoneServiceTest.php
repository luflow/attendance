<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Service\TimezoneService;
use OCP\Config\IUserConfig;
use OCP\IDateTimeZone;
use PHPUnit\Framework\TestCase;

class TimezoneServiceTest extends TestCase {
	private function service(string $own, string $instance = 'UTC'): TimezoneService {
		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('getValueString')->willReturn($own);
		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getDefaultTimeZone')->willReturn(new \DateTimeZone($instance));

		return new TimezoneService($userConfig, $dateTimeZone);
	}

	public function testUsesTheUsersOwnZone(): void {
		$this->assertSame('Europe/Berlin', $this->service('Europe/Berlin', 'America/New_York')->ofUser('alice')->getName());
	}

	public function testFallsBackToTheInstanceZone(): void {
		$this->assertSame('America/New_York', $this->service('', 'America/New_York')->ofUser('alice')->getName());
	}

	/** PHP reads an abbreviation as a fixed offset — it would lose daylight saving. */
	public function testTurnsDownAbbreviationsAndEndsAtUtc(): void {
		$this->assertSame('UTC', $this->service('CET')->ofUser('alice')->getName());
		$this->assertNull(TimezoneService::locationZone('+02:00'));
	}

	public function testUnreadableConfigIsNoZoneOfItsOwn(): void {
		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('getValueString')->willThrowException(new \RuntimeException('no such user'));
		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getDefaultTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));

		$this->assertSame('Europe/Berlin', (new TimezoneService($userConfig, $dateTimeZone))->ofUser('ghost')->getName());
	}
}
