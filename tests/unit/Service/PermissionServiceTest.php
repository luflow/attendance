<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Service\GuestService;
use OCA\Attendance\Service\PermissionService;
use OCP\IAppConfig;
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

	/** @var IAppConfig|MockObject */
	private $appConfig;

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
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->guestService = $this->createMock(GuestService::class);

		$this->service = new PermissionService(
			$this->config,
			$this->appConfig,
			$this->groupManager,
			$this->userSession,
			$this->userManager,
			$this->guestService,
		);
	}

	/**
	 * Stub app config values by key: group lists live on IConfig, the
	 * `*_mode` keys on IAppConfig. Unknown keys return their default, so
	 * unset mode keys exercise the legacy fallback exactly like an
	 * un-migrated install.
	 */
	private function configValues(array $values): void {
		$this->config->method('getAppValue')
			->willReturnCallback(
				fn (string $app, string $key, string $default = '') => $values[$key] ?? $default,
			);
		$this->appConfig->method('getValueString')
			->willReturnCallback(
				fn (string $app, string $key, string $default = '') => $values[$key] ?? $default,
			);
	}

	private function userInGroups(string $userId, array $groups): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with($userId)->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->with($user)->willReturn($groups);
	}

	public function testGetRolesForPermission(): void {
		$this->configValues(['permission_manage_appointments' => '["admin","managers"]']);

		$roles = $this->service->getRolesForPermission(PermissionService::PERMISSION_MANAGE_APPOINTMENTS);

		$this->assertEquals(['admin', 'managers'], $roles);
	}

	public function testGetRolesForPermissionEmpty(): void {
		$this->configValues([]);

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

	// --- modes ---

	public function testModeAllGrantsWithoutGroupLookup(): void {
		$this->configValues([
			'permission_manage_appointments_mode' => 'all',
			'permission_manage_appointments' => '["managers"]',
		]);
		$this->userManager->expects($this->never())->method('get');

		$this->assertTrue($this->service->hasPermission('testuser', PermissionService::PERMISSION_MANAGE_APPOINTMENTS));
	}

	public function testModeNobodyDeniesEvenGroupMembers(): void {
		$this->configValues([
			'permission_checkin_mode' => 'nobody',
			'permission_checkin' => '["staff"]',
		]);
		$this->userManager->expects($this->never())->method('get');

		$this->assertFalse($this->service->hasPermission('testuser', PermissionService::PERMISSION_CHECKIN));
	}

	public function testModeGroupsWithEmptyListDeniesEveryone(): void {
		$this->configValues(['permission_checkin_mode' => 'groups']);

		$this->assertFalse($this->service->hasPermission('testuser', PermissionService::PERMISSION_CHECKIN));
	}

	public function testGetModeForPermissionExplicit(): void {
		$this->configValues(['permission_checkin_mode' => 'nobody']);

		$this->assertSame('nobody', $this->service->getModeForPermission(PermissionService::PERMISSION_CHECKIN));
	}

	public function testGetModeForPermissionLegacyFallbacks(): void {
		$this->configValues(['permission_checkin' => '["staff"]']);

		// Non-empty list → groups; empty + open → all; empty + additive → nobody
		$this->assertSame('groups', $this->service->getModeForPermission(PermissionService::PERMISSION_CHECKIN));
		$this->assertSame('all', $this->service->getModeForPermission(PermissionService::PERMISSION_MANAGE_APPOINTMENTS));
		$this->assertSame('nobody', $this->service->getModeForPermission(PermissionService::PERMISSION_CREATE_APPOINTMENTS));
		$this->assertSame('nobody', $this->service->getModeForPermission(PermissionService::PERMISSION_RESPOND_FOR_OTHERS));
	}

	public function testGetModeForPermissionInvalidStoredValueFallsBack(): void {
		$this->configValues(['permission_checkin_mode' => 'bogus']);

		$this->assertSame('all', $this->service->getModeForPermission(PermissionService::PERMISSION_CHECKIN));
	}

	public function testSetPermissionWritesModeAndGroups(): void {
		$written = [];
		$this->config->method('setAppValue')
			->willReturnCallback(function (string $app, string $key, string $value) use (&$written): void {
				$written[$key] = $value;
			});
		$this->appConfig->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value) use (&$written): bool {
				$written[$key] = $value;
				return true;
			});

		$this->service->setPermission(PermissionService::PERMISSION_CHECKIN, 'groups', ['staff']);

		$this->assertSame('groups', $written['permission_checkin_mode']);
		$this->assertSame('["staff"]', $written['permission_checkin']);
	}

	public function testSetPermissionRejectsInvalidMode(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service->setPermission(PermissionService::PERMISSION_CHECKIN, 'everyone', []);
	}

	// --- legacy behavior via fallback (un-migrated installs) ---

	public function testHasPermissionWhenNoRolesConfigured(): void {
		// When nothing is configured, all users should have permission
		$this->configValues([]);

		$result = $this->service->hasPermission('testuser', PermissionService::PERMISSION_MANAGE_APPOINTMENTS);

		$this->assertTrue($result);
	}

	public function testHasPermissionWhenUserInRole(): void {
		$this->configValues(['permission_manage_appointments' => '["managers"]']);
		$this->userInGroups('testuser', ['managers', 'employees']);

		$result = $this->service->hasPermission('testuser', PermissionService::PERMISSION_MANAGE_APPOINTMENTS);

		$this->assertTrue($result);
	}

	public function testHasPermissionWhenUserNotInRole(): void {
		$this->configValues(['permission_manage_appointments' => '["managers"]']);
		$this->userInGroups('testuser', ['employees']);

		$result = $this->service->hasPermission('testuser', PermissionService::PERMISSION_MANAGE_APPOINTMENTS);

		$this->assertFalse($result);
	}

	public function testHasPermissionWhenUserDoesNotExist(): void {
		$this->configValues(['permission_manage_appointments' => '["managers"]']);
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

		$this->configValues([]);

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
		$this->configValues([]);

		$result = $this->service->canManageAppointments('testuser');

		$this->assertTrue($result);
	}

	public function testCanCheckin(): void {
		$this->configValues([]);

		$result = $this->service->canCheckin('testuser');

		$this->assertTrue($result);
	}

	public function testCanSeeResponseOverview(): void {
		$this->configValues([]);

		$result = $this->service->canSeeResponseOverview('testuser');

		$this->assertTrue($result);
	}

	public function testCanSeeResponseCountsOpenWhenUnconfigured(): void {
		$this->configValues([]);

		$this->assertTrue($this->service->canSeeResponseCounts('testuser'));
	}

	public function testCanSeeResponseCountsFallsBackToOverviewPermission(): void {
		// Counts restricted to "counters", overview open to everyone — the
		// overview implies the counts, so the user still passes.
		$this->configValues(['permission_see_response_counts' => '["counters"]']);
		$this->userInGroups('testuser', ['employees']);

		$this->assertTrue($this->service->canSeeResponseCounts('testuser'));
	}

	public function testCanSeeResponseCountsDeniedWhenBothRestricted(): void {
		$this->configValues([
			'permission_see_response_counts' => '["counters"]',
			'permission_see_response_overview' => '["managers"]',
		]);
		$this->userInGroups('testuser', ['employees']);

		$this->assertFalse($this->service->canSeeResponseCounts('testuser'));
	}

	public function testCanSeeResponseCountsNobodyStillImpliedByOverview(): void {
		// Explicit "nobody" on the counts keeps the structural implication:
		// whoever sees the full summary necessarily sees the numbers in it.
		$this->configValues([
			'permission_see_response_counts_mode' => 'nobody',
			'permission_see_response_overview_mode' => 'groups',
			'permission_see_response_overview' => '["managers"]',
		]);
		$this->userInGroups('testuser', ['managers']);

		$this->assertTrue($this->service->canSeeResponseCounts('testuser'));
	}

	public function testCanSeeComments(): void {
		$this->configValues([]);

		$result = $this->service->canSeeComments('testuser');

		$this->assertTrue($result);
	}

	public function testGetAllPermissionSettings(): void {
		$this->configValues([
			'permission_manage_appointments_mode' => 'groups',
			'permission_manage_appointments' => '["admin"]',
			'permission_checkin_mode' => 'all',
			'permission_checkin' => '[]',
			'permission_see_response_overview' => '["admin"]',
			'permission_respond_for_others_mode' => 'nobody',
		]);

		$result = $this->service->getAllPermissionSettings();

		$this->assertSame(PermissionService::ALL_PERMISSIONS, array_keys($result));
		$this->assertEquals(['mode' => 'groups', 'groups' => ['admin']], $result[PermissionService::PERMISSION_MANAGE_APPOINTMENTS]);
		$this->assertEquals(['mode' => 'all', 'groups' => []], $result[PermissionService::PERMISSION_CHECKIN]);
		// No stored mode → legacy fallback derives it from the group list
		$this->assertEquals(['mode' => 'groups', 'groups' => ['admin']], $result[PermissionService::PERMISSION_SEE_RESPONSE_OVERVIEW]);
		$this->assertEquals(['mode' => 'all', 'groups' => []], $result[PermissionService::PERMISSION_SEE_COMMENTS]);
		$this->assertEquals(['mode' => 'nobody', 'groups' => []], $result[PermissionService::PERMISSION_CREATE_APPOINTMENTS]);
		$this->assertEquals(['mode' => 'nobody', 'groups' => []], $result[PermissionService::PERMISSION_RESPOND_FOR_OTHERS]);
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
		// mode lookup runs. Unconfigured → all users, including guests.
		$this->configValues([]);

		$this->assertTrue(
			$this->service->hasPermission('guestuser', PermissionService::PERMISSION_SELF_CHECKIN),
		);
	}

	public function testNonGuestUserNotBlocked(): void {
		$this->guestService->expects($this->once())
			->method('isGuestUser')
			->with('regularuser')
			->willReturn(false);

		$this->configValues([]);

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

	public function testSetAllPermissionSettingsExplicitShape(): void {
		$written = [];
		$this->config->method('setAppValue')
			->willReturnCallback(function (string $app, string $key, string $value) use (&$written): void {
				$written[$key] = $value;
			});
		$this->appConfig->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value) use (&$written): bool {
				$written[$key] = $value;
				return true;
			});

		$this->service->setAllPermissionSettings([
			'manage_appointments' => ['mode' => 'groups', 'groups' => ['admin']],
			'checkin' => ['mode' => 'nobody', 'groups' => []],
			'see_comments' => ['mode' => 'all', 'groups' => []],
		]);

		$this->assertSame('groups', $written['permission_manage_appointments_mode']);
		$this->assertSame('["admin"]', $written['permission_manage_appointments']);
		$this->assertSame('nobody', $written['permission_checkin_mode']);
		$this->assertSame('[]', $written['permission_checkin']);
		$this->assertSame('all', $written['permission_see_comments_mode']);
	}

	public function testSetAllPermissionSettingsLegacyListShape(): void {
		$written = [];
		$this->config->method('setAppValue')
			->willReturnCallback(function (string $app, string $key, string $value) use (&$written): void {
				$written[$key] = $value;
			});
		$this->appConfig->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value) use (&$written): bool {
				$written[$key] = $value;
				return true;
			});

		$this->service->setAllPermissionSettings([
			'PERMISSION_MANAGE_APPOINTMENTS' => ['admin'],
			'checkin' => [],
			'respond_for_others' => [],
		]);

		// Bare lists carry the legacy semantics: non-empty → groups,
		// empty + open → all, empty + additive → nobody.
		$this->assertSame('groups', $written['permission_manage_appointments_mode']);
		$this->assertSame('["admin"]', $written['permission_manage_appointments']);
		$this->assertSame('all', $written['permission_checkin_mode']);
		$this->assertSame('nobody', $written['permission_respond_for_others_mode']);
	}

	public function testSetAllPermissionSettingsIgnoresUnknownPermission(): void {
		$this->config->expects($this->never())->method('setAppValue');
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->service->setAllPermissionSettings([
			'made_up_permission' => ['mode' => 'all', 'groups' => []],
		]);
	}

	// --- getUsersWith ---

	public function testGetUsersWithNobodyReturnsEmpty(): void {
		$this->configValues(['permission_checkin_mode' => 'nobody']);
		$this->userManager->expects($this->never())->method('search');

		$this->assertSame([], $this->service->getUsersWith(PermissionService::PERMISSION_CHECKIN));
	}

	public function testGetUsersWithAllWalksEveryUser(): void {
		$this->configValues(['permission_checkin_mode' => 'all']);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userManager->expects($this->once())->method('search')->with('')->willReturn([$user]);

		$this->assertSame(['alice'], $this->service->getUsersWith(PermissionService::PERMISSION_CHECKIN));
	}

	public function testGetUsersWithGroupsCollectsMembers(): void {
		$this->configValues([
			'permission_checkin_mode' => 'groups',
			'permission_checkin' => '["staff"]',
		]);
		$member = $this->createMock(IUser::class);
		$member->method('getUID')->willReturn('bob');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$member]);
		$this->groupManager->method('get')->with('staff')->willReturn($group);
		$this->userManager->expects($this->never())->method('search');

		$this->assertSame(['bob'], $this->service->getUsersWith(PermissionService::PERMISSION_CHECKIN));
	}

	private function makeAppointment(?array $organizers): \OCA\Attendance\Db\Appointment {
		$appointment = new \OCA\Attendance\Db\Appointment();
		$appointment->setOrganizers($organizers === null ? null : json_encode($organizers));
		return $appointment;
	}

	// --- create_appointments: unconfigured must mean "nobody additional" ---

	public function testCreateAppointmentsEmptyConfigDeniesNonManagers(): void {
		$this->guestService->method('isGuestUser')->willReturn(false);
		// manage_appointments configured non-empty, create_appointments
		// unconfigured: user is in neither group.
		$this->configValues(['permission_manage_appointments' => '["admins"]']);
		$this->userInGroups('regularuser', ['members']);

		$this->assertFalse($this->service->canCreateAppointments('regularuser'));
	}

	public function testCreateAppointmentsGrantedViaCreateGroup(): void {
		$this->guestService->method('isGuestUser')->willReturn(false);
		$this->configValues([
			'permission_manage_appointments' => '["admins"]',
			'permission_create_appointments' => '["members"]',
		]);
		$this->userInGroups('regularuser', ['members']);

		$this->assertTrue($this->service->canCreateAppointments('regularuser'));
	}

	public function testCreateAppointmentsImpliedByManagePermission(): void {
		$this->guestService->method('isGuestUser')->willReturn(false);
		// manage_appointments unconfigured → everyone manages = everyone creates
		$this->configValues([]);

		$this->assertTrue($this->service->canCreateAppointments('regularuser'));
	}

	public function testCreateAppointmentsBlockedForGuests(): void {
		$this->guestService->method('isGuestUser')->with('guestuser')->willReturn(true);
		$this->configValues([
			'permission_manage_appointments' => '["admins"]',
			'permission_create_appointments' => '["guest_app"]',
		]);

		$this->assertFalse($this->service->canCreateAppointments('guestuser'));
	}

	// --- respond_for_others: unconfigured must mean "nobody", not even managers ---

	public function testRespondForOthersEmptyConfigDeniesEveryone(): void {
		$this->guestService->method('isGuestUser')->willReturn(false);
		// Unconfigured: manage_appointments would default to "everyone", but
		// respond_for_others defaults to nobody.
		$this->configValues([]);

		$this->assertFalse($this->service->canRespondForOthers('regularuser'));
	}

	public function testRespondForOthersNotImpliedByManagePermission(): void {
		$this->guestService->method('isGuestUser')->willReturn(false);
		// User is a manager, but respond_for_others is granted to a group
		// they are not in — manage rights must not bleed through.
		$this->configValues([
			'permission_manage_appointments' => '["managers"]',
			'permission_respond_for_others' => '["office"]',
		]);
		$this->userInGroups('manageruser', ['managers']);

		$this->assertTrue($this->service->canManageAppointments('manageruser'));
		$this->assertFalse($this->service->canRespondForOthers('manageruser'));
	}

	public function testRespondForOthersGrantedViaConfiguredGroup(): void {
		$this->guestService->method('isGuestUser')->willReturn(false);
		$this->configValues(['permission_respond_for_others' => '["office"]']);
		$this->userInGroups('officeuser', ['office']);

		$this->assertTrue($this->service->canRespondForOthers('officeuser'));
	}

	public function testRespondForOthersBlockedForGuests(): void {
		$this->guestService->method('isGuestUser')->with('guestuser')->willReturn(true);
		// Guest hard-block runs before the role lookup, even when the guest
		// group was accidentally whitelisted.
		$this->config->expects($this->never())->method('getAppValue');

		$this->assertFalse($this->service->canRespondForOthers('guestuser'));
	}

	// --- organizer checks ---

	public function testIsOrganizerTrueForListedUser(): void {
		$this->guestService->method('isGuestUser')->willReturn(false);
		$appointment = $this->makeAppointment(['alice', 'bob']);

		$this->assertTrue($this->service->isOrganizer($appointment, 'alice'));
	}

	public function testIsOrganizerFalseForUnlistedUser(): void {
		$this->guestService->method('isGuestUser')->willReturn(false);
		$appointment = $this->makeAppointment(['alice']);

		$this->assertFalse($this->service->isOrganizer($appointment, 'mallory'));
	}

	public function testIsOrganizerFalseForEmptyList(): void {
		$this->guestService->method('isGuestUser')->willReturn(false);
		$appointment = $this->makeAppointment(null);

		$this->assertFalse($this->service->isOrganizer($appointment, 'alice'));
	}

	public function testIsOrganizerFalseForGuestEvenIfListed(): void {
		$this->guestService->method('isGuestUser')->with('guestuser')->willReturn(true);
		$appointment = $this->makeAppointment(['guestuser']);

		$this->assertFalse($this->service->isOrganizer($appointment, 'guestuser'));
	}

	public function testCanManageAppointmentTrueForOrganizerWithoutGlobalPermission(): void {
		$this->guestService->method('isGuestUser')->willReturn(false);
		$this->configValues(['permission_manage_appointments' => '["admins"]']);
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn(['members']);
		$appointment = $this->makeAppointment(['alice']);

		$this->assertTrue($this->service->canManageAppointment('alice', $appointment));
		$this->assertFalse($this->service->canManageAppointment('mallory', $appointment));
	}

	public function testCanSeeResponseOverviewForOrganizer(): void {
		$this->guestService->method('isGuestUser')->willReturn(false);
		$this->configValues(['permission_see_response_overview' => '["admins"]']);
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn(['members']);
		$appointment = $this->makeAppointment(['alice']);

		$this->assertTrue($this->service->canSeeResponseOverviewFor('alice', $appointment));
		$this->assertFalse($this->service->canSeeResponseOverviewFor('mallory', $appointment));
	}
}
