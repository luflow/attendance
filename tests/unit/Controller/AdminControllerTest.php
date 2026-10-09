<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Controller;

use OCA\Attendance\Controller\AdminController;
use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Service\CalendarService;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\GuestService;
use OCA\Attendance\Service\NotificationService;
use OCA\Attendance\Service\OrgCalendarSyncService;
use OCA\Attendance\Service\PermissionService;
use OCA\Attendance\Service\VacationCalendarSyncService;
use OCA\Attendance\Service\VisibilityService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminControllerTest extends TestCase {
	/** @var AppointmentMapper|MockObject */
	private $appointmentMapper;

	/** @var NotificationService|MockObject */
	private $notificationService;

	/** @var ConfigService|MockObject */
	private $configService;

	private AdminController $controller;

	protected function setUp(): void {
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->configService = $this->createMock(ConfigService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('isAdmin')->willReturn(true);

		$this->controller = new AdminController(
			'attendance',
			$this->createMock(IRequest::class),
			$userSession,
			$permissionService,
			$this->createMock(IConfig::class),
			$this->createMock(IAppManager::class),
			$this->configService,
			$this->createMock(VisibilityService::class),
			$this->notificationService,
			$this->appointmentMapper,
			$this->createMock(IJobList::class),
			$this->createMock(GuestService::class),
			$this->createMock(CalendarService::class),
			$this->createMock(OrgCalendarSyncService::class),
			$this->createMock(VacationCalendarSyncService::class),
		);
	}

	private function upcoming(string $name): Appointment {
		$appointment = new Appointment();
		$appointment->setName($name);
		$appointment->setStartDatetime('2030-06-03 18:00:00');
		$appointment->setEndDatetime('2030-06-03 20:00:00');
		return $appointment;
	}

	/** The admin page warns when Nextcloud keeps notifications out of people's inboxes. */
	public function testSettingsTellWhetherNotificationsReachEmail(): void {
		$this->notificationService->method('isEmailForwardingEnabledByDefault')->willReturn(true);
		$this->configService->method('isNotificationEmailHintDismissed')->willReturn(true);

		$config = $this->controller->getSettings()->getData()['config'];

		$this->assertTrue($config['notificationsApp']['emailForwardingEnabled']);
		$this->assertTrue($config['notificationsApp']['emailHintDismissed']);
	}

	public function testCollapsingTheEmailHintIsStored(): void {
		$this->configService->expects($this->once())
			->method('setNotificationEmailHintDismissed')
			->with(true);

		$response = $this->controller->saveSettings(notificationEmailHintDismissed: true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/** The preview parsed a naive string as local time and showed the UTC hour. */
	public function testNextAppointmentStartCarriesTheUtcMarker(): void {
		$this->appointmentMapper->method('findUpcoming')->willReturn([$this->upcoming('Rehearsal')]);

		$response = $this->controller->getSettings();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['name' => 'Rehearsal', 'startDatetime' => '2030-06-03T18:00:00Z', 'isAllDay' => false],
			$response->getData()['status']['nextAppointment'],
		);
	}

	public function testNextAppointmentSkipsClosedInquiriesAndTellsWholeDays(): void {
		$closed = $this->upcoming('Closed');
		$closed->setClosedAt('2030-01-01 00:00:00');
		$allDay = $this->upcoming('Outing');
		$allDay->setAllDay(true);
		$this->appointmentMapper->method('findUpcoming')->willReturn([$closed, $allDay]);

		$next = $this->controller->getSettings()->getData()['status']['nextAppointment'];

		$this->assertSame('Outing', $next['name']);
		$this->assertTrue($next['isAllDay']);
	}

	public function testNoUpcomingAppointmentMeansNoPreview(): void {
		$this->appointmentMapper->method('findUpcoming')->willReturn([]);

		$this->assertNull($this->controller->getSettings()->getData()['status']['nextAppointment']);
	}
}
