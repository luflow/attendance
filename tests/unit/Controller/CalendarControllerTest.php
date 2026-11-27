<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Controller;

use OCA\Attendance\Controller\CalendarController;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Service\CalendarService;
use OCA\Attendance\Service\PermissionService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CalendarControllerTest extends TestCase {
	private CalendarService|MockObject $calendarService;
	private AppointmentMapper|MockObject $appointmentMapper;
	private CalendarController $controller;

	protected function setUp(): void {
		$this->calendarService = $this->createMock(CalendarService::class);
		$this->calendarService->method('isCalendarAvailable')->willReturn(true);
		$this->calendarService->method('getEventsFromCalendar')->willReturn([['uid' => 'e1']]);
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('canCreateAppointments')->willReturn(true);

		$this->controller = new CalendarController(
			'attendance',
			$this->createMock(IRequest::class),
			$this->calendarService,
			$permissionService,
			$userSession,
			$this->appointmentMapper,
		);
	}

	public function testImportedIdsComeFromTheUsersOwnCalendar(): void {
		$this->calendarService->method('findCalendarId')->with('alice', 'personal')->willReturn(12);
		$this->appointmentMapper->expects($this->once())
			->method('findImportedByCalendarId')
			->with(12)
			->willReturn([['calendar_event_uid' => 'e1', 'start_datetime' => '2026-12-01 18:00:00']]);

		$data = $this->controller->getEvents('personal')->getData();

		$this->assertCount(1, $data['importedIds']);
	}

	public function testUnknownCalendarMarksNothingAsImported(): void {
		$this->calendarService->method('findCalendarId')->willReturn(null);
		$this->appointmentMapper->expects($this->never())->method('findImportedByCalendarId');

		$data = $this->controller->getEvents('personal')->getData();

		$this->assertSame([], $data['importedIds']);
	}
}
