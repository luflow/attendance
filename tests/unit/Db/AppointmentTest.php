<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Db;

use OCA\Attendance\Db\Appointment;
use PHPUnit\Framework\TestCase;

class AppointmentTest extends TestCase {
	private Appointment $appointment;

	protected function setUp(): void {
		$this->appointment = new Appointment();
	}

	public function testSetAndGetName(): void {
		$name = 'Team Meeting';
		$this->appointment->setName($name);

		$this->assertEquals($name, $this->appointment->getName());
	}

	public function testSetAndGetDescription(): void {
		$description = 'Monthly team sync meeting';
		$this->appointment->setDescription($description);

		$this->assertEquals($description, $this->appointment->getDescription());
	}

	public function testSetAndGetStartDatetime(): void {
		$datetime = '2024-01-15 10:00:00';
		$this->appointment->setStartDatetime($datetime);

		$this->assertEquals($datetime, $this->appointment->getStartDatetime());
	}

	public function testSetAndGetEndDatetime(): void {
		$datetime = '2024-01-15 11:00:00';
		$this->appointment->setEndDatetime($datetime);

		$this->assertEquals($datetime, $this->appointment->getEndDatetime());
	}

	public function testSetAndGetCreatedBy(): void {
		$userId = 'admin';
		$this->appointment->setCreatedBy($userId);

		$this->assertEquals($userId, $this->appointment->getCreatedBy());
	}

	public function testSetAndGetIsActive(): void {
		$this->appointment->setIsActive(true);
		$this->assertEquals(1, $this->appointment->getIsActive());

		$this->appointment->setIsActive(false);
		$this->assertEquals(0, $this->appointment->getIsActive());
	}

	public function testJsonSerialize(): void {
		$this->appointment->setName('Team Meeting');
		$this->appointment->setDescription('Monthly sync');
		$this->appointment->setStartDatetime('2024-01-15 10:00:00');
		$this->appointment->setEndDatetime('2024-01-15 11:00:00');
		$this->appointment->setCreatedBy('admin');
		$this->appointment->setCreatedAt('2024-01-01 09:00:00');
		$this->appointment->setUpdatedAt('2024-01-01 09:00:00');
		$this->appointment->setIsActive(true);
		$this->appointment->setLocation('Club house');
		$this->appointment->setCategoryId(7);

		$json = $this->appointment->jsonSerialize();

		$this->assertIsArray($json);
		$this->assertEquals('Team Meeting', $json['name']);
		$this->assertEquals('Monthly sync', $json['description']);
		$this->assertEquals('admin', $json['createdBy']);
		$this->assertEquals(1, $json['isActive']);
		$this->assertEquals('Club house', $json['location']);
		$this->assertEquals(7, $json['categoryId']);

		// Check datetime formatting to UTC ISO 8601
		$this->assertStringContainsString('2024-01-15T10:00:00Z', $json['startDatetime']);
		$this->assertStringContainsString('2024-01-15T11:00:00Z', $json['endDatetime']);
	}

	/** 10 and 11 October 2026 as someone in Berlin entered them. */
	private function allDayInBerlin(): Appointment {
		$appointment = new Appointment();
		$appointment->setStartDatetime('2026-10-09 22:00:00');
		$appointment->setEndDatetime('2026-10-11 22:00:00');
		$appointment->setAllDay(true);
		return $appointment;
	}

	/**
	 * @dataProvider readerZones
	 */
	public function testAllDaySpanNamesTheSameDaysInEveryZone(string $zone): void {
		[$first, $last] = $this->allDayInBerlin()->allDaySpan(new \DateTimeZone($zone));

		$this->assertSame('2026-10-10 00:00', $first->format('Y-m-d H:i'));
		$this->assertSame('2026-10-11 00:00', $last->format('Y-m-d H:i'));
	}

	public static function readerZones(): array {
		return [['Europe/Berlin'], ['UTC'], ['America/New_York'], ['Asia/Tokyo']];
	}

	public function testAllDaySpanNeverEndsBeforeItStarts(): void {
		$appointment = $this->allDayInBerlin();
		$appointment->setEndDatetime('2026-10-09 23:00:00');

		[$first, $last] = $appointment->allDaySpan(new \DateTimeZone('Europe/Berlin'));

		$this->assertEquals($first, $last);
	}

	public function testAlignAllDayPinsToTheMidnightsOfTheZone(): void {
		$appointment = $this->allDayInBerlin();

		$appointment->alignAllDay(new \DateTimeZone('America/New_York'));

		$this->assertSame('2026-10-10 04:00:00', $appointment->getStartDatetime());
		$this->assertSame('2026-10-12 04:00:00', $appointment->getEndDatetime());
	}

	/** Saved again by an organizer in New York: other midnights, same two days. */
	public function testSameDaysFromAnotherZoneKeepTheirMidnights(): void {
		$edited = $this->allDayInBerlin();
		$edited->alignAllDay(new \DateTimeZone('America/New_York'));

		$edited->keepMidnightsOfSameDays($this->allDayInBerlin(), new \DateTimeZone('Europe/Berlin'));

		$this->assertSame('2026-10-09 22:00:00', $edited->getStartDatetime());
		$this->assertSame('2026-10-11 22:00:00', $edited->getEndDatetime());
	}

	public function testOtherDaysAreAChange(): void {
		$edited = $this->allDayInBerlin();
		$edited->setEndDatetime('2026-10-12 22:00:00');

		$edited->keepMidnightsOfSameDays($this->allDayInBerlin(), new \DateTimeZone('Europe/Berlin'));

		$this->assertSame('2026-10-12 22:00:00', $edited->getEndDatetime());
	}

	public function testJsonSerializeCarriesAllDay(): void {
		$this->assertTrue($this->allDayInBerlin()->jsonSerialize()['isAllDay']);
		$this->assertFalse($this->appointment->jsonSerialize()['isAllDay']);
	}

	public function testDefaultValues(): void {
		$appointment = new Appointment();

		$this->assertEquals('', $appointment->getName());
		$this->assertEquals('', $appointment->getDescription());
		$this->assertEquals('', $appointment->getStartDatetime());
		$this->assertEquals('', $appointment->getEndDatetime());
		$this->assertEquals('', $appointment->getCreatedBy());
		$this->assertEquals(1, $appointment->getIsActive());
		$this->assertNull($appointment->getLocation());
	}
}
