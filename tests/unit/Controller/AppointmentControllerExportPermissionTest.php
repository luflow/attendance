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
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Export is its own permission: managing appointments neither grants nor is
 * needed for it.
 */
class AppointmentControllerExportPermissionTest extends TestCase {
	/** @var ExportService|MockObject */
	private $exportService;
	/** @var PermissionService|MockObject */
	private $permissionService;
	/** @var IUserSession|MockObject */
	private $userSession;

	private AppointmentController $controller;

	protected function setUp(): void {
		$this->exportService = $this->createMock(ExportService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new AppointmentController(
			'attendance',
			$this->createMock(IRequest::class),
			$this->createMock(AppointmentService::class),
			$this->createMock(AttachmentService::class),
			$this->createMock(CalendarService::class),
			$this->createMock(CheckinService::class),
			$this->createMock(ConfigService::class),
			$this->permissionService,
			$this->exportService,
			$this->createMock(NotificationService::class),
			$this->createMock(VisibilityService::class),
			$this->createMock(IAppManager::class),
			$this->userSession,
			$this->createMock(ISecureRandom::class),
			$this->createMock(GuestService::class),
			$this->createMock(BookingService::class),
			$this->createMock(TalkRoomService::class),
			$this->createMock(DirectorySearchService::class),
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testManagerWithoutExportPermissionCannotExport(): void {
		$this->permissionService->method('canManageAppointments')->willReturn(true);
		$this->permissionService->method('canExport')->with('alice')->willReturn(false);
		$this->exportService->expects($this->never())->method('exportToOds');

		$response = $this->controller->export();

		$this->assertSame(403, $response->getStatus());
	}

	public function testExportHolderWithoutManageRightsCanExport(): void {
		$this->permissionService->method('canManageAppointments')->willReturn(false);
		$this->permissionService->method('canExport')->with('alice')->willReturn(true);
		$this->exportService->expects($this->once())
			->method('exportToOds')
			->willReturn(['path' => '/Attendance/x.ods', 'filename' => 'x.ods']);

		$response = $this->controller->export();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('x.ods', $response->getData()['filename']);
	}

	public function testSeriesListFollowsTheExportPermissionToo(): void {
		$this->permissionService->method('canManageAppointments')->willReturn(true);
		$this->permissionService->method('canExport')->willReturn(false);
		$this->exportService->expects($this->never())->method('getSeriesOverview');

		$this->assertSame(403, $this->controller->exportSeries()->getStatus());
	}

	public function testExportHolderSeesTheSeriesList(): void {
		$this->permissionService->method('canExport')->willReturn(true);
		$this->exportService->method('getSeriesOverview')->willReturn([]);

		$this->assertSame(200, $this->controller->exportSeries()->getStatus());
	}

	public function testPermissionsReportTheExportRight(): void {
		$this->permissionService->method('canExport')->with('alice')->willReturn(true);

		$this->assertTrue($this->controller->getPermissions()->getData()['canExport']);
	}
}
