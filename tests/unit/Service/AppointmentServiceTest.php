<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Service\AppointmentService;
use OCA\Attendance\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class AppointmentServiceTest extends TestCase {
	/** @var AppointmentMapper|MockObject */
	private $appointmentMapper;
	
	/** @var AttendanceResponseMapper|MockObject */
	private $responseMapper;
	
	/** @var IGroupManager|MockObject */
	private $groupManager;
	
	/** @var IUserManager|MockObject */
	private $userManager;
	
	/** @var IConfig|MockObject */
	private $config;
	
	/** @var PermissionService|MockObject */
	private $permissionService;
	
	private AppointmentService $service;

	protected function setUp(): void {
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		
		$this->service = new AppointmentService(
			$this->appointmentMapper,
			$this->responseMapper,
			$this->groupManager,
			$this->userManager,
			$this->config,
			$this->permissionService
		);
	}

	public function testCreateAppointment(): void {
		$name = 'Team Meeting';
		$description = 'Weekly sync';
		$startDatetime = '2024-01-15T10:00:00Z';
		$endDatetime = '2024-01-15T11:00:00Z';
		$createdBy = 'admin';

		$appointment = new Appointment();
		$appointment->setId(1);

		$this->appointmentMapper->expects($this->once())
			->method('insert')
			->willReturn($appointment);

		$result = $this->service->createAppointment(
			$name,
			$description,
			$startDatetime,
			$endDatetime,
			$createdBy
		);

		$this->assertInstanceOf(Appointment::class, $result);
		$this->assertEquals(1, $result->getId());
	}

	public function testGetAppointment(): void {
		$appointmentId = 1;
		$appointment = new Appointment();
		$appointment->setId($appointmentId);

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with($appointmentId)
			->willReturn($appointment);

		$result = $this->service->getAppointment($appointmentId);

		$this->assertEquals($appointmentId, $result->getId());
	}

	public function testGetAllAppointments(): void {
		$appointments = [
			$this->createAppointment(1, 'Meeting 1'),
			$this->createAppointment(2, 'Meeting 2')
		];

		$this->appointmentMapper->expects($this->once())
			->method('findAll')
			->willReturn($appointments);

		$result = $this->service->getAllAppointments();

		$this->assertCount(2, $result);
	}

	public function testSubmitResponseCreatesNewResponse(): void {
		$appointmentId = 1;
		$userId = 'testuser';
		$response = 'yes';
		$comment = 'I will attend';

		$appointment = new Appointment();
		$appointment->setId($appointmentId);

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with($appointmentId)
			->willReturn($appointment);

		$this->responseMapper->expects($this->once())
			->method('findByAppointmentAndUser')
			->with($appointmentId, $userId)
			->willThrowException(new DoesNotExistException(''));

		$savedResponse = new AttendanceResponse();
		$savedResponse->setId(1);

		$this->responseMapper->expects($this->once())
			->method('insert')
			->willReturn($savedResponse);

		$result = $this->service->submitResponse(
			$appointmentId,
			$userId,
			$response,
			$comment
		);

		$this->assertInstanceOf(AttendanceResponse::class, $result);
	}

	public function testSubmitResponseUpdatesExistingResponse(): void {
		$appointmentId = 1;
		$userId = 'testuser';
		$response = 'no';
		$comment = 'Cannot attend';

		$appointment = new Appointment();
		$appointment->setId($appointmentId);

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with($appointmentId)
			->willReturn($appointment);

		$existingResponse = new AttendanceResponse();
		$existingResponse->setId(1);
		$existingResponse->setResponse('yes');

		$this->responseMapper->expects($this->once())
			->method('findByAppointmentAndUser')
			->with($appointmentId, $userId)
			->willReturn($existingResponse);

		$this->responseMapper->expects($this->once())
			->method('update')
			->willReturn($existingResponse);

		$result = $this->service->submitResponse(
			$appointmentId,
			$userId,
			$response,
			$comment
		);

		$this->assertInstanceOf(AttendanceResponse::class, $result);
	}

	public function testSubmitResponseThrowsExceptionForInvalidResponse(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid response. Must be yes, no, or maybe.');

		$this->service->submitResponse(1, 'user', 'invalid', '');
	}

	public function testGetUserResponseReturnsNullWhenNotFound(): void {
		$appointmentId = 1;
		$userId = 'testuser';

		$this->responseMapper->expects($this->once())
			->method('findByAppointmentAndUser')
			->with($appointmentId, $userId)
			->willThrowException(new DoesNotExistException(''));

		$result = $this->service->getUserResponse($appointmentId, $userId);

		$this->assertNull($result);
	}

	public function testGetUserResponseReturnsResponse(): void {
		$appointmentId = 1;
		$userId = 'testuser';
		
		$response = new AttendanceResponse();
		$response->setAppointmentId($appointmentId);
		$response->setUserId($userId);

		$this->responseMapper->expects($this->once())
			->method('findByAppointmentAndUser')
			->with($appointmentId, $userId)
			->willReturn($response);

		$result = $this->service->getUserResponse($appointmentId, $userId);

		$this->assertInstanceOf(AttendanceResponse::class, $result);
		$this->assertEquals($userId, $result->getUserId());
	}

	public function testGetAppointmentResponses(): void {
		$appointmentId = 1;
		$responses = [
			$this->createResponse(1, 'user1', 'yes'),
			$this->createResponse(2, 'user2', 'no')
		];

		$this->responseMapper->expects($this->once())
			->method('findByAppointment')
			->with($appointmentId)
			->willReturn($responses);

		$result = $this->service->getAppointmentResponses($appointmentId);

		$this->assertCount(2, $result);
	}

	public function testDeleteAppointmentSetsIsActiveToFalse(): void {
		$appointmentId = 1;
		$userId = 'admin';

		$appointment = new Appointment();
		$appointment->setId($appointmentId);
		$appointment->setIsActive(true);

		$this->appointmentMapper->expects($this->once())
			->method('find')
			->with($appointmentId)
			->willReturn($appointment);

		$this->appointmentMapper->expects($this->once())
			->method('update')
			->with($this->callback(function($app) {
				return $app->getIsActive() === 0;
			}));

		$this->service->deleteAppointment($appointmentId, $userId);
	}

	private function createAppointment(int $id, string $name): Appointment {
		$appointment = new Appointment();
		$appointment->setId($id);
		$appointment->setName($name);
		return $appointment;
	}

	private function createResponse(int $id, string $userId, string $response): AttendanceResponse {
		$attendanceResponse = new AttendanceResponse();
		$attendanceResponse->setId($id);
		$attendanceResponse->setUserId($userId);
		$attendanceResponse->setResponse($response);
		return $attendanceResponse;
	}
}
