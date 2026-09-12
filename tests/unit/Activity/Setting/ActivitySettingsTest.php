<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Activity\Setting;

use OCA\Attendance\Activity\Setting\AppointmentCancelledSetting;
use OCA\Attendance\Activity\Setting\AppointmentReactivatedSetting;
use OCA\Attendance\Activity\Setting\AppointmentsSeriesUpdatedSetting;
use OCA\Attendance\Activity\Setting\BookingConfirmedSetting;
use OCA\Attendance\Activity\Setting\BookingDeclinedSetting;
use OCP\Activity\ActivitySettings;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class ActivitySettingsTest extends TestCase {
	private IL10N $l;

	protected function setUp(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params),
		);
		$this->l = $l;
	}

	/**
	 * @return list<array{0: class-string<ActivitySettings>, 1: string}>
	 */
	public static function settingsProvider(): array {
		return [
			[AppointmentCancelledSetting::class, 'appointment_cancelled'],
			[AppointmentReactivatedSetting::class, 'appointment_reactivated'],
			[BookingConfirmedSetting::class, 'booking_confirmed'],
			[BookingDeclinedSetting::class, 'booking_declined'],
			[AppointmentsSeriesUpdatedSetting::class, 'appointments_series_updated'],
		];
	}

	/**
	 * @dataProvider settingsProvider
	 * @param class-string<ActivitySettings> $class
	 */
	public function testIdentifierMatchesTheNotificationSubject(string $class, string $expectedIdentifier): void {
		$setting = new $class($this->l);
		$this->assertSame($expectedIdentifier, $setting->getIdentifier());
	}

	/**
	 * These 5 types were delivered unconditionally before this setting
	 * existed. Push must stay on by default so nobody silently stops
	 * getting them just because the "activity" app happens to be enabled.
	 *
	 * @dataProvider settingsProvider
	 * @param class-string<ActivitySettings> $class
	 */
	public function testNotificationStaysEnabledByDefault(string $class): void {
		$setting = new $class($this->l);
		$this->assertTrue($setting->isDefaultEnabledNotification());
		$this->assertTrue($setting->canChangeNotification());
	}

	/**
	 * @dataProvider settingsProvider
	 * @param class-string<ActivitySettings> $class
	 */
	public function testAllShareTheSameGroup(string $class): void {
		$setting = new $class($this->l);
		$this->assertSame('attendance', $setting->getGroupIdentifier());
	}
}
