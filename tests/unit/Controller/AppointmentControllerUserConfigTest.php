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
use OCA\Attendance\Service\ExportService;
use OCA\Attendance\Service\GuestService;
use OCA\Attendance\Service\NotificationService;
use OCA\Attendance\Service\PermissionService;
use OCA\Attendance\Service\VisibilityService;
use OCP\App\IAppManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the onboarding signals the web app boots on — everything else about
 * getUserConfig is plain config passthrough.
 */
class AppointmentControllerUserConfigTest extends TestCase {
	/** @var AppointmentService|MockObject */
	private $appointmentService;
	/** @var ConfigService|MockObject */
	private $configService;
	/** @var PermissionService|MockObject */
	private $permissionService;
	/** @var IUserSession|MockObject */
	private $userSession;

	private AppointmentController $controller;

	protected function setUp(): void {
		$this->appointmentService = $this->createMock(AppointmentService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new AppointmentController(
			'attendance',
			$this->createMock(IRequest::class),
			$this->appointmentService,
			$this->createMock(AttachmentService::class),
			$this->createMock(CalendarService::class),
			$this->createMock(CheckinService::class),
			$this->configService,
			$this->permissionService,
			$this->createMock(ExportService::class),
			$this->createMock(NotificationService::class),
			$this->createMock(VisibilityService::class),
			$this->createMock(IAppManager::class),
			$this->userSession,
			$this->createMock(ISecureRandom::class),
			$this->createMock(GuestService::class),
			$this->createMock(BookingService::class),
		);

		$this->configService->method('getDisplayOrder')->willReturn('name_first');
		$this->configService->method('isMobileAppBannerEnabled')->willReturn(false);
	}

	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testAdminOnAFreshInstanceIsOfferedTheWizard(): void {
		$this->signIn('admin');
		$this->permissionService->method('isAdmin')->with('admin')->willReturn(true);
		$this->appointmentService->method('hasAnyAppointment')->willReturn(false);

		$onboarding = $this->controller->getUserConfig()->getData()['onboarding'];

		$this->assertSame('start', $onboarding['setupPrompt']);
		// The wizard comes first; the create prompt waits until it is done.
		$this->assertFalse($onboarding['firstAppointmentPrompt']);
	}

	public function testAdminWhoFinishedTheWizardIsSentToCreateSomething(): void {
		$this->signIn('admin');
		$this->permissionService->method('isAdmin')->willReturn(true);
		$this->permissionService->method('canCreateAppointments')->willReturn(true);
		$this->appointmentService->method('hasAnyAppointment')->willReturn(false);
		$this->configService->method('isOnboardingCompleted')->willReturn(true);

		$onboarding = $this->controller->getUserConfig()->getData()['onboarding'];

		$this->assertSame('review', $onboarding['setupPrompt']);
		$this->assertTrue($onboarding['firstAppointmentPrompt']);
	}

	public function testNoPromptsOnceTheInstanceIsInUse(): void {
		$this->signIn('admin');
		$this->permissionService->method('isAdmin')->willReturn(true);
		$this->appointmentService->method('hasAnyAppointment')->willReturn(true);

		$this->assertSame(
			['setupPrompt' => null, 'firstAppointmentPrompt' => false],
			$this->controller->getUserConfig()->getData()['onboarding'],
		);
	}

	public function testCreatorWithoutAdminRightsIsSentStraightToCreating(): void {
		$this->signIn('alice');
		$this->permissionService->method('isAdmin')->willReturn(false);
		$this->permissionService->method('canCreateAppointments')->with('alice')->willReturn(true);
		$this->appointmentService->method('hasAnyAppointment')->willReturn(false);

		$onboarding = $this->controller->getUserConfig()->getData()['onboarding'];

		// The wizard is admin-only, so there is nothing to wait for.
		$this->assertNull($onboarding['setupPrompt']);
		$this->assertTrue($onboarding['firstAppointmentPrompt']);
	}

	public function testPlainUserNeverPaysForTheAppointmentLookup(): void {
		$this->signIn('alice');
		$this->permissionService->method('isAdmin')->willReturn(false);
		$this->permissionService->method('canCreateAppointments')->willReturn(false);
		$this->appointmentService->expects($this->never())->method('hasAnyAppointment');

		$this->assertSame(
			['setupPrompt' => null, 'firstAppointmentPrompt' => false],
			$this->controller->getUserConfig()->getData()['onboarding'],
		);
	}

	public function testAnonymousRequestGetsNoPrompts(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->appointmentService->expects($this->never())->method('hasAnyAppointment');

		$this->assertSame(
			['setupPrompt' => null, 'firstAppointmentPrompt' => false],
			$this->controller->getUserConfig()->getData()['onboarding'],
		);
	}
}
