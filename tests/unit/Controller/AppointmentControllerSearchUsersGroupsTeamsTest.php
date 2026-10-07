<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Controller;

use OCA\Attendance\Controller\AppointmentController;
use OCA\Attendance\Service\AppointmentService;
use OCA\Attendance\Service\AttachmentService;
use OCA\Attendance\Service\BookingService;
use OCA\Attendance\Service\CalendarService;
use OCA\Attendance\Service\CheckinService;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\DirectorySearchService;
use OCA\Attendance\Service\ExportService;
use OCA\Attendance\Service\GuestService;
use OCA\Attendance\Service\NotificationService;
use OCA\Attendance\Service\PermissionService;
use OCA\Attendance\Service\TalkRoomService;
use OCA\Attendance\Service\VisibilityService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The users-groups-teams picker backs the admin-only "response summary
 * teams" setting as well as the appointment form's visibility/organizer
 * fields, so all three ways in are expected to reach it.
 */
class AppointmentControllerSearchUsersGroupsTeamsTest extends TestCase {
	/** @var AppointmentService|MockObject */
	private $appointmentService;
	/** @var PermissionService|MockObject */
	private $permissionService;
	/** @var IUserSession|MockObject */
	private $userSession;
	/** @var DirectorySearchService|MockObject */
	private $directorySearchService;

	private AppointmentController $controller;

	protected function setUp(): void {
		$this->appointmentService = $this->createMock(AppointmentService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->directorySearchService = $this->createMock(DirectorySearchService::class);

		$this->controller = new AppointmentController(
			'attendance',
			$this->createMock(IRequest::class),
			$this->appointmentService,
			$this->createMock(AttachmentService::class),
			$this->createMock(CalendarService::class),
			$this->createMock(CheckinService::class),
			$this->createMock(ConfigService::class),
			$this->permissionService,
			$this->createMock(ExportService::class),
			$this->createMock(NotificationService::class),
			$this->createMock(VisibilityService::class),
			$this->createMock(IAppManager::class),
			$this->userSession,
			$this->createMock(ISecureRandom::class),
			$this->createMock(GuestService::class),
			$this->createMock(BookingService::class),
			$this->createMock(TalkRoomService::class),
			$this->directorySearchService,
		);
	}

	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testAdminWithNoAppointmentRoleCanStillSearch(): void {
		$this->signIn('admin');
		$this->permissionService->method('isAdmin')->with('admin')->willReturn(true);
		$this->permissionService->method('canCreateAppointments')->willReturn(false);
		$this->appointmentService->method('isOrganizerAnywhere')->willReturn(false);
		$this->directorySearchService->method('search')->with('acme', 'admin')->willReturn([]);

		$response = $this->controller->searchUsersGroupsTeams('acme');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUserWithNoRoleAtAllIsRejected(): void {
		$this->signIn('alice');
		$this->permissionService->method('isAdmin')->willReturn(false);
		$this->permissionService->method('canCreateAppointments')->willReturn(false);
		$this->appointmentService->method('isOrganizerAnywhere')->willReturn(false);
		$this->directorySearchService->expects($this->never())->method('search');

		$response = $this->controller->searchUsersGroupsTeams('acme');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}
}
