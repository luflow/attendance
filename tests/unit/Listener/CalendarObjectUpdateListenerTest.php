<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Listener;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Listener\CalendarObjectUpdateListener;
use OCA\Attendance\Service\AppointmentService;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\TimezoneService;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\Config\IUserConfig;
use OCP\IDateTimeZone;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CalendarObjectUpdateListenerTest extends TestCase {
	private AppointmentMapper|MockObject $appointmentMapper;
	private CalendarObjectUpdateListener $listener;

	protected function setUp(): void {
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$configService = $this->createMock(ConfigService::class);
		$configService->method('isCalendarSyncEnabled')->willReturn(true);

		$this->listener = new CalendarObjectUpdateListener(
			$this->appointmentMapper,
			$configService,
			$this->createMock(AppointmentService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
			new TimezoneService($this->createMock(IUserConfig::class), $this->createMock(IDateTimeZone::class)),
		);
	}

	private function linkedAppointment(): Appointment {
		$appointment = new Appointment();
		$appointment->setId(7);
		$appointment->setIsActive(1);
		return $appointment;
	}

	public function testDeleteSoftDeletesTheAppointmentLinkedToThatCalendar(): void {
		$this->appointmentMapper->expects($this->once())
			->method('findByCalendarEventUid')
			->with('shared-uid', 12)
			->willReturn([$this->linkedAppointment()]);
		$this->appointmentMapper->expects($this->once())
			->method('update')
			->with($this->callback(fn (Appointment $a): bool => $a->getIsActive() === 0));

		$this->listener->handle(new CalendarObjectDeletedEvent(12, ['uri' => 'personal'], [], ['uid' => 'shared-uid', 'uri' => 'x.ics']));
	}

	private function updatedEvent(string $vevents): CalendarObjectUpdatedEvent {
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n" . $vevents . "END:VCALENDAR\r\n";
		return new CalendarObjectUpdatedEvent(12, ['uri' => 'personal'], [], ['calendardata' => $ics, 'uri' => 'x.ics']);
	}

	public function testUpdateCopiesTitleAndTimesFromTheEvent(): void {
		$appointment = $this->linkedAppointment();
		$appointment->setStartDatetime('2026-12-01 10:00:00');
		$this->appointmentMapper->method('findByCalendarEventUid')
			->willReturnCallback(fn (string $uid, int $calendarId): array => $uid === 'uid-1' && $calendarId === 12 ? [$appointment] : []);
		$this->appointmentMapper->expects($this->once())
			->method('update')
			->with($this->callback(fn (Appointment $a): bool => $a->getName() === 'Rehearsal'
				&& $a->getStartDatetime() === '2026-12-01 18:00:00'
				&& $a->getEndDatetime() === '2026-12-01 20:00:00'))
			->willReturnArgument(0);

		$this->listener->handle($this->updatedEvent(
			"BEGIN:VEVENT\r\nUID:uid-1\r\nSUMMARY:Rehearsal\r\nDTSTART:20261201T180000Z\r\nDTEND:20261201T200000Z\r\nEND:VEVENT\r\n"
		));
	}

	public function testUpdateOfARecurringEventSyncsTheMatchingOccurrence(): void {
		$appointment = $this->linkedAppointment();
		$appointment->setStartDatetime('2026-12-08 18:00:00');
		$this->appointmentMapper->method('findByCalendarEventUid')->willReturn([$appointment]);
		$this->appointmentMapper->expects($this->once())
			->method('update')
			->with($this->callback(fn (Appointment $a): bool => $a->getStartDatetime() === '2026-12-08 19:00:00'
				&& $a->getIsActive() === 1))
			->willReturnArgument(0);

		$this->listener->handle($this->updatedEvent(
			"BEGIN:VEVENT\r\nUID:uid-1\r\nSUMMARY:Rehearsal\r\nDTSTART:20261201T190000Z\r\nDTEND:20261201T210000Z\r\nRRULE:FREQ=WEEKLY;COUNT=4\r\nEND:VEVENT\r\n"
		));
	}

	public function testSameUidAndUriInAnotherCalendarChangesNothing(): void {
		// Every user has a "personal" calendar; only calendar 12 is linked.
		$this->appointmentMapper->method('findByCalendarEventUid')
			->willReturnCallback(fn (string $uid, int $calendarId): array => $calendarId === 12 ? [$this->linkedAppointment()] : []);
		$this->appointmentMapper->expects($this->never())->method('update');

		$this->listener->handle(new CalendarObjectDeletedEvent(99, ['uri' => 'personal'], [], ['uid' => 'shared-uid', 'uri' => 'x.ics']));
		$this->listener->handle(new CalendarObjectMovedToTrashEvent(99, ['uri' => 'personal'], [], ['uid' => 'shared-uid', 'uri' => 'x.ics']));
	}
}
