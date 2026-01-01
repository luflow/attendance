<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Db;

use OCA\Attendance\Db\AttendanceResponse;
use PHPUnit\Framework\TestCase;

class AttendanceResponseTest extends TestCase {
	private AttendanceResponse $response;

	protected function setUp(): void {
		$this->response = new AttendanceResponse();
	}

	public function testSetAndGetAppointmentId(): void {
		$this->response->setAppointmentId(123);

		$this->assertEquals(123, $this->response->getAppointmentId());
	}

	public function testSetAndGetUserId(): void {
		$userId = 'testuser';
		$this->response->setUserId($userId);

		$this->assertEquals($userId, $this->response->getUserId());
	}

	public function testSetAndGetResponse(): void {
		$response = 'yes';
		$this->response->setResponse($response);

		$this->assertEquals($response, $this->response->getResponse());
	}

	public function testSetAndGetComment(): void {
		$comment = 'Looking forward to it!';
		$this->response->setComment($comment);

		$this->assertEquals($comment, $this->response->getComment());
	}

	public function testSetAndGetRespondedAt(): void {
		$datetime = '2024-01-15 10:00:00';
		$this->response->setRespondedAt($datetime);

		$this->assertEquals($datetime, $this->response->getRespondedAt());
	}

	public function testSetAndGetCheckinState(): void {
		$state = 'present';
		$this->response->setCheckinState($state);

		$this->assertEquals($state, $this->response->getCheckinState());
	}

	public function testSetAndGetCheckinComment(): void {
		$comment = 'Arrived on time';
		$this->response->setCheckinComment($comment);

		$this->assertEquals($comment, $this->response->getCheckinComment());
	}

	public function testSetAndGetCheckinBy(): void {
		$userId = 'admin';
		$this->response->setCheckinBy($userId);

		$this->assertEquals($userId, $this->response->getCheckinBy());
	}

	public function testSetAndGetCheckinAt(): void {
		$datetime = '2024-01-15 10:05:00';
		$this->response->setCheckinAt($datetime);

		$this->assertEquals($datetime, $this->response->getCheckinAt());
	}

	public function testIsCheckedInReturnsTrueWhenCheckinStateSet(): void {
		$this->response->setCheckinState('present');

		$this->assertTrue($this->response->isCheckedIn());
	}

	public function testIsCheckedInReturnsFalseWhenCheckinStateEmpty(): void {
		$this->response->setCheckinState('');

		$this->assertFalse($this->response->isCheckedIn());
	}

	public function testIsCheckedInReturnsFalseByDefault(): void {
		$this->assertFalse($this->response->isCheckedIn());
	}

	public function testJsonSerialize(): void {
		$this->response->setAppointmentId(123);
		$this->response->setUserId('testuser');
		$this->response->setResponse('yes');
		$this->response->setComment('Looking forward!');
		$this->response->setRespondedAt('2024-01-15 10:00:00');
		$this->response->setCheckinState('present');
		$this->response->setCheckinComment('On time');
		$this->response->setCheckinBy('admin');
		$this->response->setCheckinAt('2024-01-15 10:05:00');

		$json = $this->response->jsonSerialize();

		$this->assertIsArray($json);
		$this->assertEquals(123, $json['appointmentId']);
		$this->assertEquals('testuser', $json['userId']);
		$this->assertEquals('yes', $json['response']);
		$this->assertEquals('Looking forward!', $json['comment']);
		$this->assertEquals('2024-01-15 10:00:00', $json['respondedAt']);
		$this->assertEquals('present', $json['checkinState']);
		$this->assertEquals('On time', $json['checkinComment']);
		$this->assertEquals('admin', $json['checkinBy']);
		$this->assertEquals('2024-01-15 10:05:00', $json['checkinAt']);
		$this->assertTrue($json['isCheckedIn']);
	}

	public function testDefaultValues(): void {
		$response = new AttendanceResponse();

		$this->assertEquals(0, $response->getAppointmentId());
		$this->assertEquals('', $response->getUserId());
		$this->assertEquals('', $response->getResponse());
		$this->assertEquals('', $response->getComment());
		$this->assertEquals('', $response->getRespondedAt());
		$this->assertEquals('', $response->getCheckinState());
		$this->assertEquals('', $response->getCheckinComment());
		$this->assertEquals('', $response->getCheckinBy());
		$this->assertEquals('', $response->getCheckinAt());
	}
}
