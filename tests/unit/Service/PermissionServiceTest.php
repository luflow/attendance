<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Service\GuestService;
use OCA\Attendance\Service\PermissionService;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PermissionServiceTest extends TestCase {
	/** @var IConfig|MockObject */
	private $config;

	/** @var IGroupManager|MockObject */
	private $groupManager;

	/** @var IUserSession|MockObject */
	private $userSession;

	/** @var IUserManager|MockObject */
	private $userManager;

	/** @var GuestService|MockObject */
	private $guestService;

	private PermissionService $service;

	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->guestService = $this->createMock(GuestService::class);

		$this->service = new PermissionService(
			$this->config,
			$this->groupManager,
			$this->userSession,
			$this->userManager,
			$this->guestService,
		);
	}

	public function testGetRolesForPermission(): void {
		$this->config->expects($this->once())
			->method('getAppValue')
			->with('attendance', 'permission_manage_appointments', '[]')
			->willReturn('["admin","managers"]');

		$roles = $this->service->getRolesForPermission(PermissionService::PERMISSION_MANAGE_APPOINTMENTS);

		$this->assertEquals(['admin', 'managers'], $roles);
	}

	public function testGetRolesForPermissionEmpty(): void {
		$this->config->expects($this->once())
			->method('getAppValue')
			->with('attendance', 'permission_checkin', '[]')
			->willReturn('[]');

		$roles = $this->service->getRolesForPermission(PermissionService::PERMISSION_CHECKIN);

		$this->assertEquals([], $roles);
	}

	public function testSetRolesForPermission(): void {
		$this->config->expects($this->once())
			->method('setAppValue')
			->with('attendance', 'permission_manage_appointments', '["admin","managers"]');

		$this->service->setRolesForPermission(
			PermissionService::PERMISSION_MANAGE_APPOINTMENTS,
			['admin', 'managers']
		);
	}

	public function testHasPermissionWhenNoRolesConfigured(): void {
		// When no roles are configured, all users should have permission
		$this->config->expects($this->once())
			->method('getAppValue')
			->willReturn('[]');

		$result = $this->service->hasPermission('testuser', PermissionService::PERMISSION_MANAGE_APPOINTMENTS);

		$this->assertTrue($result);
	}

	public function testHasPermissionWhenUserInRole(): void {
		$this->config->expects($this->once())
			->method('getAppValue')
			->willReturn('["managers"]');

		$user = $this->createMock(IUser::class);
		$this->userManager->expects($this->once())
			->method('get')
			->with('testuser')
			->willReturn($user);

		$this->groupManager->expects($this->once())
			->method('getUserGroupIds')
			->with($user)
			->willReturn(['managers', 'employees']);

		$result = $this->service->hasPermission('testuser', PermissionService::PERMISSION_MANAGE_APPOINTMENTS);

		$this->assertTrue($result);
	}

	public function testHasPermissionWhenUserNotInRole(): void {
		$this->config->expects($this->once())
			->method('getAppValue')
			->willReturn('["managers"]');

		$user = $this->createMock(IUser::class);
		$this->userManager->expects($this->once())
			->method('get')
			->with('testuser')
			->willReturn($user);

		$this->groupManager->expects($this->once())
			->method('getUserGroupIds')
			->with($user)
			->willReturn(['employees']);

		$result = $this->service->hasPermission('testuser', PermissionService::PERMISSION_MANAGE_APPOINTMENTS);

		$this->assertFalse($result);
	}

	public function testHasPermissionWhenUserDoesNotExist(): void {
		$this->config->expects($this->once())
			->method('getAppValue')
			->willReturn('["managers"]');

		$this->userManager->expects($this->once())
			->method('get')
			->with('nonexistent')
			->willReturn(null);

		$result = $this->service->hasPermission('nonexistent', PermissionService::PERMISSION_MANAGE_APPOINTMENTS);

		$this->assertFalse($result);
	}

	public function testCurrentUserHasPermission(): void {
		$user = $this->createMock(IUser::class);
		$user->expects($this->once())
			->method('getUID')
			->willReturn('testuser');

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$this->config->expects($this->once())
			->method('getAppValue')
			->willReturn('[]');

		$result = $this->service->currentUserHasPermission(PermissionService::PERMISSION_MANAGE_APPOINTMENTS);

		$this->assertTrue($result);
	}

	public function testCurrentUserHasPermissionWhenNotLoggedIn(): void {
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$result = $this->service->currentUserHasPermission(PermissionService::PERMISSION_MANAGE_APPOINTMENTS);

		$this->assertFalse($result);
	}

	public function testGetAvailableGroups(): void {
		$group1 = $this->createMock(IGroup::class);
		$group1->expects($this->once())->method('getGID')->willReturn('admin');
		$group1->expects($this->once())->method('getDisplayName')->willReturn('Administrators');

		$group2 = $this->createMock(IGroup::class);
		$group2->expects($this->once())->method('getGID')->willReturn('managers');
		$group2->expects($this->once())->method('getDisplayName')->willReturn('Managers');

		$this->groupManager->expects($this->once())
			->method('search')
			->with('')
			->willReturn([$group1, $group2]);

		$result = $this->service->getAvailableGroups();

		$expected = [
			['id' => 'admin', 'displayName' => 'Administrators'],
			['id' => 'managers', 'displayName' => 'Managers']
		];

		$this->assertEquals($expected, $result);
	}

	public function testCanManageAppointments(): void {
		$this->config->expects($this->once())
			->method('getAppValue')
			->willReturn('[]');

		$result = $this->service->canManageAppointments('testuser');

		$this->assertTrue($result);
	}

	public function testCanCheckin(): void {
		$this->config->expects($this->once())
			->method('getAppValue')
			->willReturn('[]');

		$result = $this->service->canCheckin('testuser');

		$this->assertTrue($result);
	}

	public function testCanSeeResponseOverview(): void {
		$this->config->expects($this->once())
			->method('getAppValue')
			->willReturn('[]');

		$result = $this->service->canSeeResponseOverview('testuser');

		$this->assertTrue($result);
	}

	public function testCanSeeComments(): void {
		$this->config->expects($this->once())
			->method('getAppValue')
			->willReturn('[]');

		$result = $this->service->canSeeComments('testuser');

		$this->assertTrue($result);
	}

	public function testGetAllPermissionSettings(): void {
		$this->config->expects($this->exactly(5))
			->method('getAppValue')
			->willReturnMap([
				['attendance', 'permission_manage_appointments', '[]', '["admin"]'],
				['attendance', 'permission_checkin', '[]', '["admin","staff"]'],
				['attendance', 'permission_see_response_overview', '[]', '["admin"]'],
				['attendance', 'permission_see_comments', '[]', '["admin","managers"]'],
				['attendance', 'permission_self_checkin', '[]', '["users"]']
			]);

		$result = $this->service->getAllPermissionSettings();

		$expected = [
			PermissionService::PERMISSION_MANAGE_APPOINTMENTS => ['admin'],
			PermissionService::PERMISSION_CHECKIN => ['admin', 'staff'],
			PermissionService::PERMISSION_SEE_RESPONSE_OVERVIEW => ['admin'],
			PermissionService::PERMISSION_SEE_COMMENTS => ['admin', 'managers'],
			PermissionService::PERMISSION_SELF_CHECKIN => ['users']
		];

		$this->assertEquals($expected, $result);
	}

	public function testGuestUserIsBlockedFromManageAppointments(): void {
		$this->guestService->expects($this->once())
			->method('isGuestUser')
			->with('guestuser')
			->willReturn(true);

		// Guest hard-block runs before role lookup; config and userManager
		// must not be consulted at all.
		$this->config->expects($this->never())->method('getAppValue');
		$this->userManager->expects($this->never())->method('get');

		$this->assertFalse(
			$this->service->hasPermission('guestuser', PermissionService::PERMISSION_MANAGE_APPOINTMENTS),
		);
	}

	public function testGuestUserIsBlockedFromCheckin(): void {
		$this->guestService->expects($this->once())
			->method('isGuestUser')
			->with('guestuser')
			->willReturn(true);

		$this->assertFalse(
			$this->service->hasPermission('guestuser', PermissionService::PERMISSION_CHECKIN),
		);
	}

	public function testGuestUserCanStillSelfCheckin(): void {
		// SELF_CHECKIN is not in the guest hard-block list, so the regular
		// group lookup runs. With no roles configured, the user gets access.
		$this->config->expects($this->once())
			->method('getAppValue')
			->willReturn('[]');

		$this->assertTrue(
			$this->service->hasPermission('guestuser', PermissionService::PERMISSION_SELF_CHECKIN),
		);
	}

	public function testNonGuestUserNotBlocked(): void {
		$this->guestService->expects($this->once())
			->method('isGuestUser')
			->with('regularuser')
			->willReturn(false);

		$this->config->expects($this->once())
			->method('getAppValue')
			->willReturn('[]');

		$this->assertTrue(
			$this->service->hasPermission('regularuser', PermissionService::PERMISSION_MANAGE_APPOINTMENTS),
		);
	}

	public function testGuestBlockOverridesGroupWhitelist(): void {
		// Even if the admin accidentally adds the `guests` group to the
		// management whitelist, the hard-block must still kick in.
		$this->guestService->expects($this->once())
			->method('isGuestUser')
			->with('guestuser')
			->willReturn(true);

		$this->config->expects($this->never())->method('getAppValue');

		$this->assertFalse(
			$this->service->hasPermission('guestuser', PermissionService::PERMISSION_MANAGE_APPOINTMENTS),
		);
	}

	public function testSetAllPermissionSettings(): void {
		$permissions = [
			'PERMISSION_MANAGE_APPOINTMENTS' => ['admin'],
			'PERMISSION_CHECKIN' => ['admin', 'staff']
		];

		$callCount = 0;
		$this->config->expects($this->exactly(2))
			->method('setAppValue')
			->willReturnCallback(function ($app, $key, $value) use (&$callCount) {
				$callCount++;
				if ($callCount === 1) {
					$this->assertEquals('attendance', $app);
					$this->assertEquals('permission_manage_appointments', $key);
					$this->assertEquals('["admin"]', $value);
				} elseif ($callCount === 2) {
					$this->assertEquals('attendance', $app);
					$this->assertEquals('permission_checkin', $key);
					$this->assertEquals('["admin","staff"]', $value);
				}
			});

		$this->service->setAllPermissionSettings($permissions);
	}
}
