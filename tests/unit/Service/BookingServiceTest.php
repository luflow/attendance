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
use OCA\Attendance\Service\TalkRoomService;
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
			$this->createMock(TalkRoomService::class),
		);
		// Default: an open appointment, so the happy-path booking tests aren't
		// tripped by the closed-inquiry guard.
		$this->appointmentMapper->method('find')->willReturn(new Appointment());
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

	public function testBookRejectedOnClosedInquiry(): void {
		// A separate mapper so the closed appointment isn't shadowed by setUp's
		// default open one.
		$closedAppointment = new Appointment();
		$closedAppointment->setClosedAt('2026-01-01 10:00:00');
		$appointmentMapper = $this->createMock(AppointmentMapper::class);
		$appointmentMapper->method('find')->with(5)->willReturn($closedAppointment);
		$service = new BookingService(
			$this->responseMapper,
			$appointmentMapper,
			$this->configService,
			$this->notificationService,
			$this->createMock(TalkRoomService::class),
		);
		// Reject before touching the response, and never persist.
		$this->responseMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$service->book(5, 'alice');
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
		$this->responseMapper->expects($this->never())->method('findBookedUserIds');
		$this->notificationService->expects($this->never())->method('sendBookingNotification');

		$sent = $this->service->notifyOnClose($this->appointment());
		$this->assertSame(['booked' => 0, 'declined' => 0], $sent);
	}

	public function testNotifyOnCloseStaysSilentWhenPlanningWasNeverUsed(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		// Two yes-responders, none booked, none ever told anything → the manager
		// does not use the feature, so closing stays silent.
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

	/**
	 * Regression for issue #250: close → reopen → un-schedule the only booked
	 * person → close again. The "nobody booked" guard used to skip the wave, so
	 * the earlier "you are scheduled" stayed the last word and the appointment
	 * kept saying "Scheduled".
	 */
	public function testNotifyOnCloseTakesBackAVerdictAfterEverybodyWasUnscheduled(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$alice = $this->response('alice', 'yes', null, BookingService::STATUS_BOOKED);
		$this->responseMapper->method('findByAppointment')->willReturn([$alice]);

		$this->notificationService->expects($this->once())
			->method('sendBookingNotification')
			->with($this->anything(), 'alice', BookingService::STATUS_DECLINED);
		$this->responseMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$sent = $this->service->notifyOnClose($this->appointment());

		$this->assertSame(['booked' => 0, 'declined' => 1], $sent);
		// And the appointment stops claiming she has a place.
		$this->assertSame(BookingService::STATUS_DECLINED, $this->service->effectiveBookingStatus($alice));
	}

	/**
	 * The retraction is per person: it takes back what somebody was told, it
	 * does not turn "nobody is scheduled" into a verdict against people who
	 * were never in the plan to begin with.
	 */
	public function testNotifyOnCloseRetractionSparesSomebodyWhoWasNeverTold(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$alice = $this->response('alice', 'yes', null, BookingService::STATUS_BOOKED);
		// Answered after the reopen, so no earlier close ever reached her.
		$bob = $this->response('bob', 'yes');
		$this->responseMapper->method('findByAppointment')->willReturn([$alice, $bob]);

		$this->notificationService->expects($this->once())
			->method('sendBookingNotification')
			->with($this->anything(), 'alice', BookingService::STATUS_DECLINED);
		$this->responseMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$sent = $this->service->notifyOnClose($this->appointment());

		$this->assertSame(['booked' => 0, 'declined' => 1], $sent);
		// Nobody holds a place here, so Bob's appointment stays unmarked.
		$this->assertNull($this->service->effectiveBookingStatus($bob));
	}

	private function appointment(): Appointment {
		$a = new Appointment();
		$a->setId(5);
		$a->setName('Team sync');
		$a->setStartDatetime('2026-07-20 10:00:00');
		return $a;
	}

	private function closedAppointment(): Appointment {
		$a = $this->appointment();
		$a->setClosedAt('2026-07-20 09:00:00');
		return $a;
	}

	// --- isScheduledOut: the read side of the close-time wave ---

	public function testIsScheduledOutIsFalseWhenPlanningIsDisabled(): void {
		$this->configService->method('isBookingEnabled')->willReturn(false);
		$this->responseMapper->expects($this->never())->method('findBookedUserIds');

		$this->assertFalse($this->service->isScheduledOut($this->closedAppointment(), 'alice'));
	}

	public function testIsScheduledOutIsFalseWhileInquiryIsStillOpen(): void {
		// Nothing has been decided yet — hiding here would take away the very
		// appointment the user still needs to answer.
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->responseMapper->expects($this->never())->method('findBookedUserIds');

		$this->assertFalse($this->service->isScheduledOut($this->appointment(), 'alice'));
	}

	public function testIsScheduledOutIsFalseWhenNobodyWasScheduled(): void {
		// The manager closed without using the feature — everyone keeps seeing it.
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->responseMapper->method('findBookedUserIds')->with([5])->willReturn([]);

		$this->assertFalse($this->service->isScheduledOut($this->closedAppointment(), 'alice'));
	}

	public function testIsScheduledOutIsFalseForTheScheduledUser(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->responseMapper->method('findBookedUserIds')->with([5])->willReturn([5 => ['bob', 'alice']]);

		$this->assertFalse($this->service->isScheduledOut($this->closedAppointment(), 'alice'));
	}

	public function testIsScheduledOutIsTrueWhenSomebodyElseGotThePlace(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->responseMapper->method('findBookedUserIds')->with([5])->willReturn([5 => ['bob']]);

		$this->assertTrue($this->service->isScheduledOut($this->closedAppointment(), 'alice'));
	}

	public function testScheduledFiltersUsePreloadedBookingsWithoutAQuery(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->responseMapper->expects($this->never())->method('findBookedUserIds');

		$this->assertTrue($this->service->isScheduledOut($this->closedAppointment(), 'alice', ['bob']));
		$this->assertFalse($this->service->isScheduledOut($this->closedAppointment(), 'alice', ['bob', 'alice']));
		$this->assertFalse($this->service->isScheduledOut($this->closedAppointment(), 'alice', []));
		$this->assertTrue($this->service->isScheduledIn($this->closedAppointment(), 'alice', ['alice']));
		$this->assertFalse($this->service->isScheduledIn($this->closedAppointment(), 'alice', ['bob']));
	}

	public function testBookedUserIdsForSkipsTheQueryWhilePlanningIsOff(): void {
		$this->configService->method('isBookingEnabled')->willReturn(false);
		$this->responseMapper->expects($this->never())->method('findBookedUserIds');

		$this->assertSame([], $this->service->bookedUserIdsFor([1, 2]));
	}

	public function testBookedUserIdsForReadsAllAppointmentsAtOnce(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->responseMapper->expects($this->once())
			->method('findBookedUserIds')
			->with([1, 2])
			->willReturn([1 => ['alice']]);

		$this->assertSame([1 => ['alice']], $this->service->bookedUserIdsFor([1, 2]));
	}

	// --- isScheduledIn: the "only my scheduled" filter ---

	public function testIsScheduledInIsFalseWhenPlanningIsOff(): void {
		$this->configService->method('isBookingEnabled')->willReturn(false);
		$this->responseMapper->method('findBookedUserIds')->with([5])->willReturn([5 => ['alice']]);

		$this->assertFalse($this->service->isScheduledIn($this->closedAppointment(), 'alice'));
	}

	public function testIsScheduledInIsTrueForTheBookedUser(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->responseMapper->method('findBookedUserIds')->with([5])->willReturn([5 => ['alice']]);

		$this->assertTrue($this->service->isScheduledIn($this->closedAppointment(), 'alice'));
	}

	public function testIsScheduledInCountsAnOpenInquiry(): void {
		// Being booked is an explicit act by a manager, so it means something
		// before closing — unlike not being booked. The date is one the user
		// has to turn up for either way.
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->responseMapper->method('findBookedUserIds')->with([5])->willReturn([5 => ['alice']]);

		$this->assertTrue($this->service->isScheduledIn($this->appointment(), 'alice'));
	}

	public function testIsScheduledInIsFalseForSomebodyElsesPlace(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->responseMapper->method('findBookedUserIds')->with([5])->willReturn([5 => ['bob']]);

		$this->assertFalse($this->service->isScheduledIn($this->closedAppointment(), 'alice'));
	}

	public function testIsScheduledInIsFalseWithoutAnyResponse(): void {
		$this->configService->method('isBookingEnabled')->willReturn(true);
		$this->responseMapper->method('findBookedUserIds')->with([5])->willReturn([5 => ['bob']]);

		$this->assertFalse($this->service->isScheduledIn($this->closedAppointment(), 'alice'));
	}

	// --- effectiveBookingStatus: what the user is actually told ---

	public function testEffectiveBookingStatusKeepsAStoredBooking(): void {
		$this->assertSame(
			BookingService::STATUS_BOOKED,
			$this->service->effectiveBookingStatus(
				$this->response('alice', 'yes', BookingService::STATUS_BOOKED),
			),
		);
	}

	public function testEffectiveBookingStatusReportsWhatTheCloseWaveTold(): void {
		// 'declined' never reaches booking_status — only book()/unbook() write
		// there. Without reading the wave's own column the card would tell a
		// passed-over user "you answered yes" and nothing more.
		$this->assertSame(
			BookingService::STATUS_DECLINED,
			$this->service->effectiveBookingStatus(
				$this->response('alice', 'yes', null, BookingService::STATUS_DECLINED),
			),
		);
	}

	public function testEffectiveBookingStatusIsOpenWhenNoWaveHasRun(): void {
		// Planning never used, or the inquiry still running: nothing to say.
		$this->assertNull(
			$this->service->effectiveBookingStatus($this->response('alice', 'yes')),
		);
	}

	public function testEffectiveBookingStatusCostsNoQuery(): void {
		// It reads two columns of a row the caller already holds; a list
		// endpoint calls this once per appointment.
		$this->responseMapper->expects($this->never())->method('findBookedUserIds');
		$this->configService->expects($this->never())->method('isBookingEnabled');

		$this->service->effectiveBookingStatus($this->response('alice', 'no'));
	}
}
