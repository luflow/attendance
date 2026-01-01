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

		$json = $this->appointment->jsonSerialize();

		$this->assertIsArray($json);
		$this->assertEquals('Team Meeting', $json['name']);
		$this->assertEquals('Monthly sync', $json['description']);
		$this->assertEquals('admin', $json['createdBy']);
		$this->assertEquals(1, $json['isActive']);

		// Check datetime formatting to UTC ISO 8601
		$this->assertStringContainsString('2024-01-15T10:00:00Z', $json['startDatetime']);
		$this->assertStringContainsString('2024-01-15T11:00:00Z', $json['endDatetime']);
	}

	public function testDefaultValues(): void {
		$appointment = new Appointment();

		$this->assertEquals('', $appointment->getName());
		$this->assertEquals('', $appointment->getDescription());
		$this->assertEquals('', $appointment->getStartDatetime());
		$this->assertEquals('', $appointment->getEndDatetime());
		$this->assertEquals('', $appointment->getCreatedBy());
		$this->assertEquals(1, $appointment->getIsActive());
	}
}
