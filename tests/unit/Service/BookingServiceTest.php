<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Service\BookingService;
use OCA\Attendance\Service\ConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BookingServiceTest extends TestCase {
	/** @var AttendanceResponseMapper|MockObject */
	private $responseMapper;
	/** @var AppointmentMapper|MockObject */
	private $appointmentMapper;
	/** @var ConfigService|MockObject */
	private $configService;
	private BookingService $service;

	protected function setUp(): void {
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->service = new BookingService(
			$this->responseMapper,
			$this->appointmentMapper,
			$this->configService,
		);
	}

	private function yesResponse(): AttendanceResponse {
		$r = new AttendanceResponse();
		$r->setId(1);
		$r->setAppointmentId(5);
		$r->setUserId('alice');
		$r->setResponse('yes');
		return $r;
	}

	public function testBookMarksYesResponderAsBooked(): void {
		$response = $this->yesResponse();
		$this->responseMapper->method('findByAppointmentAndUser')
			->with(5, 'alice')->willReturn($response);
		$this->responseMapper->expects($this->once())
			->method('update')->willReturnArgument(0);

		$result = $this->service->book(5, 'alice');
		$this->assertSame(BookingService::STATUS_BOOKED, $result->getBookingStatus());
	}

	public function testBookRejectsNonYesResponder(): void {
		$response = $this->yesResponse();
		$response->setResponse('no');
		$this->responseMapper->method('findByAppointmentAndUser')->willReturn($response);
		$this->responseMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->book(5, 'alice');
	}

	public function testUnbookClearsBookingStatus(): void {
		$response = $this->yesResponse();
		$response->setBookingStatus(BookingService::STATUS_BOOKED);
		$this->responseMapper->method('findByAppointmentAndUser')->willReturn($response);
		$this->responseMapper->expects($this->once())
			->method('update')->willReturnArgument(0);

		$result = $this->service->unbook(5, 'alice');
		$this->assertNull($result->getBookingStatus());
	}

	public function testSetBookingStatusRejectsInvalidStatus(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->setBookingStatus(5, 'alice', 'planned');
	}

	public function testBookIsIdempotent(): void {
		$response = $this->yesResponse();
		$response->setBookingStatus(BookingService::STATUS_BOOKED);
		$this->responseMapper->method('findByAppointmentAndUser')->willReturn($response);
		$this->responseMapper->expects($this->never())->method('update');

		$result = $this->service->book(5, 'alice');
		$this->assertSame(BookingService::STATUS_BOOKED, $result->getBookingStatus());
	}

	public function testIsEnabledReflectsConfig(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->assertTrue($this->service->isEnabled());
	}
}
