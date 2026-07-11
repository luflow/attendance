<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Service\BookingService;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\NotificationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BookingServiceTest extends TestCase {
	/** @var AttendanceResponseMapper|MockObject */
	private $responseMapper;
	/** @var AppointmentMapper|MockObject */
	private $appointmentMapper;
	/** @var ConfigService|MockObject */
	private $configService;
	/** @var NotificationService|MockObject */
	private $notificationService;
	private BookingService $service;

	protected function setUp(): void {
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->service = new BookingService(
			$this->responseMapper,
			$this->appointmentMapper,
			$this->configService,
			$this->notificationService,
		);
	}

	private function response(string $userId, string $answer, ?string $booking = null, ?string $notified = null): AttendanceResponse {
		$r = new AttendanceResponse();
		$r->setUserId($userId);
		$r->setResponse($answer);
		$r->setBookingStatus($booking);
		$r->setBookingNotifiedStatus($notified);
		return $r;
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

	public function testNotifyOnCloseSkippedWhenFeatureDisabled(): void {
		$this->configService->method('isBookingEnabled')->willReturn(false);
		$this->responseMapper->expects($this->never())->method('findByAppointment');
		$this->notificationService->expects($this->never())->method('sendBookingNotification');

		$sent = $this->service->notifyOnClose($this->appointment());
		$this->assertSame(['booked' => 0, 'declined' => 0], $sent);
	}

	public function testNotifyOnCloseSkippedWhenNobodyBooked(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		// Two yes-responders, none booked → no wave, closing stays silent.
		$this->responseMapper->method('findByAppointment')->willReturn([
			$this->response('alice', 'yes'),
			$this->response('bob', 'yes'),
		]);
		$this->notificationService->expects($this->never())->method('sendBookingNotification');
		$this->responseMapper->expects($this->never())->method('update');

		$sent = $this->service->notifyOnClose($this->appointment());
		$this->assertSame(['booked' => 0, 'declined' => 0], $sent);
	}

	public function testNotifyOnCloseNotifiesBookedAndDeclined(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->responseMapper->method('findByAppointment')->willReturn([
			$this->response('alice', 'yes', BookingService::STATUS_BOOKED),
			$this->response('bob', 'yes'), // yes but not booked → declined
			$this->response('carol', 'no'), // ignored (not yes)
		]);

		$calls = [];
		$this->notificationService->method('sendBookingNotification')
			->willReturnCallback(function ($appt, $userId, $status) use (&$calls): void {
				$calls[$userId] = $status;
			});
		$this->responseMapper->expects($this->exactly(2))->method('update')->willReturnArgument(0);

		$sent = $this->service->notifyOnClose($this->appointment());
		$this->assertSame(['booked' => 1, 'declined' => 1], $sent);
		$this->assertSame(BookingService::STATUS_BOOKED, $calls['alice']);
		$this->assertSame(BookingService::STATUS_DECLINED, $calls['bob']);
		$this->assertArrayNotHasKey('carol', $calls);
	}

	public function testNotifyOnCloseIsReopenSafeNoDuplicate(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		// Everyone already got their last-communicated status → re-close is silent.
		$this->responseMapper->method('findByAppointment')->willReturn([
			$this->response('alice', 'yes', BookingService::STATUS_BOOKED, BookingService::STATUS_BOOKED),
			$this->response('bob', 'yes', null, BookingService::STATUS_DECLINED),
		]);
		$this->notificationService->expects($this->never())->method('sendBookingNotification');
		$this->responseMapper->expects($this->never())->method('update');

		$sent = $this->service->notifyOnClose($this->appointment());
		$this->assertSame(['booked' => 0, 'declined' => 0], $sent);
	}

	private function appointment(): Appointment {
		$a = new Appointment();
		$a->setId(5);
		$a->setName('Team sync');
		$a->setStartDatetime('2026-07-20 10:00:00');
		return $a;
	}
}
