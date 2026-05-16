<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

class PermissionService {
	private IConfig $config;
	private IGroupManager $groupManager;
	private IUserSession $userSession;
	private IUserManager $userManager;
	private GuestService $guestService;
	/** @var array<string, list<string>> per-request cache for getUsersWith() */
	private array $usersWithCache = [];

	public const PERMISSION_MANAGE_APPOINTMENTS = 'manage_appointments';
	public const PERMISSION_CHECKIN = 'checkin';
	public const PERMISSION_SEE_RESPONSE_OVERVIEW = 'see_response_overview';
	public const PERMISSION_SEE_COMMENTS = 'see_comments';
	public const PERMISSION_SELF_CHECKIN = 'self_checkin';

	private const GUEST_BLOCKED_PERMISSIONS = [
		self::PERMISSION_MANAGE_APPOINTMENTS,
		self::PERMISSION_CHECKIN,
	];

	public function __construct(
		IConfig $config,
		IGroupManager $groupManager,
		IUserSession $userSession,
		IUserManager $userManager,
		GuestService $guestService,
	) {
		$this->config = $config;
		$this->groupManager = $groupManager;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->guestService = $guestService;
	}

	/**
	 * Get roles that have a specific permission
	 */
	public function getRolesForPermission(string $permission): array {
		$configKey = 'permission_' . $permission;
		$rolesJson = $this->config->getAppValue('attendance', $configKey, '[]');
		$roles = json_decode($rolesJson, true) ?: [];

		return $roles;
	}

	/**
	 * Set roles that have a specific permission
	 */
	public function setRolesForPermission(string $permission, array $roles): void {
		$configKey = 'permission_' . $permission;
		$this->config->setAppValue('attendance', $configKey, json_encode($roles));
	}

	/**
	 * Check if a user has a specific permission
	 */
	public function hasPermission(string $userId, string $permission): bool {
		// Guests must never gain management permissions, regardless of how
		// groups are configured. Runs before the role lookup so accidental
		// whitelisting of the `guest_app` group cannot grant admin actions.
		if (in_array($permission, self::GUEST_BLOCKED_PERMISSIONS, true)
			&& $this->guestService->isGuestUser($userId)) {
			return false;
		}

		$allowedRoles = $this->getRolesForPermission($permission);

		// If no roles are configured, allow all users
		if (empty($allowedRoles)) {
			return true;
		}

		// Get user object and their groups
		$user = $this->userManager->get($userId);
		if (!$user) {
			return false;
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);

		return !empty(array_intersect($allowedRoles, $userGroups));
	}

	/**
	 * Check if current logged-in user has a specific permission
	 */
	public function currentUserHasPermission(string $permission): bool {
		$user = $this->userSession->getUser();
		if (!$user) {
			return false;
		}

		return $this->hasPermission($user->getUID(), $permission);
	}

	/**
	 * Get all available groups for permission configuration
	 */
	public function getAvailableGroups(): array {
		$allGroups = $this->groupManager->search('');
		$groupOptions = [];

		foreach ($allGroups as $group) {
			$groupOptions[] = [
				'id' => $group->getGID(),
				'displayName' => $group->getDisplayName()
			];
		}

		return $groupOptions;
	}

	/**
	 * Get all permission settings
	 */
	public function getAllPermissionSettings(): array {
		return [
			self::PERMISSION_MANAGE_APPOINTMENTS => $this->getRolesForPermission(self::PERMISSION_MANAGE_APPOINTMENTS),
			self::PERMISSION_CHECKIN => $this->getRolesForPermission(self::PERMISSION_CHECKIN),
			self::PERMISSION_SEE_RESPONSE_OVERVIEW => $this->getRolesForPermission(self::PERMISSION_SEE_RESPONSE_OVERVIEW),
			self::PERMISSION_SEE_COMMENTS => $this->getRolesForPermission(self::PERMISSION_SEE_COMMENTS),
			self::PERMISSION_SELF_CHECKIN => $this->getRolesForPermission(self::PERMISSION_SELF_CHECKIN),
		];
	}

	/**
	 * Set all permission settings
	 */
	public function setAllPermissionSettings(array $permissions): void {
		// Map of uppercase constant names to actual permission values
		$permissionMap = [
			'PERMISSION_MANAGE_APPOINTMENTS' => self::PERMISSION_MANAGE_APPOINTMENTS,
			'PERMISSION_CHECKIN' => self::PERMISSION_CHECKIN,
			'PERMISSION_SEE_RESPONSE_OVERVIEW' => self::PERMISSION_SEE_RESPONSE_OVERVIEW,
			'PERMISSION_SEE_COMMENTS' => self::PERMISSION_SEE_COMMENTS,
			'PERMISSION_SELF_CHECKIN' => self::PERMISSION_SELF_CHECKIN,
		];

		foreach ($permissions as $permission => $roles) {
			// Convert uppercase constant name to actual value if needed
			$permissionValue = $permissionMap[$permission] ?? $permission;

			if (in_array($permissionValue, [
				self::PERMISSION_MANAGE_APPOINTMENTS,
				self::PERMISSION_CHECKIN,
				self::PERMISSION_SEE_RESPONSE_OVERVIEW,
				self::PERMISSION_SEE_COMMENTS,
				self::PERMISSION_SELF_CHECKIN,
			])) {
				$this->setRolesForPermission($permissionValue, $roles);
			}
		}
	}

	/**
	 * Check if user can manage appointments (create/update/delete)
	 */
	public function canManageAppointments(string $userId): bool {
		return $this->hasPermission($userId, self::PERMISSION_MANAGE_APPOINTMENTS);
	}

	/**
	 * Check if user can do checkins
	 */
	public function canCheckin(string $userId): bool {
		return $this->hasPermission($userId, self::PERMISSION_CHECKIN);
	}

	/**
	 * Check if current user can manage appointments
	 */
	public function currentUserCanManageAppointments(): bool {
		return $this->currentUserHasPermission(self::PERMISSION_MANAGE_APPOINTMENTS);
	}

	/**
	 * Check if current user can do checkins
	 */
	public function currentUserCanCheckin(): bool {
		return $this->currentUserHasPermission(self::PERMISSION_CHECKIN);
	}

	/**
	 * Check if user can see response overview
	 */
	public function canSeeResponseOverview(string $userId): bool {
		return $this->hasPermission($userId, self::PERMISSION_SEE_RESPONSE_OVERVIEW);
	}

	/**
	 * Check if user can see comments
	 */
	public function canSeeComments(string $userId): bool {
		return $this->hasPermission($userId, self::PERMISSION_SEE_COMMENTS);
	}

	/**
	 * Check if user can self-check-in via NFC / deep link
	 */
	public function canSelfCheckin(string $userId): bool {
		return $this->hasPermission($userId, self::PERMISSION_SELF_CHECKIN);
	}

	/**
	 * Check if a user is a Nextcloud admin
	 */
	public function isAdmin(string $userId): bool {
		return $this->groupManager->isAdmin($userId);
	}

	/**
	 * Get all user IDs that have the given permission. When no roles are
	 * configured for a permission, every existing user is considered to have
	 * it (matches hasPermission()'s "empty roles = allow all" semantics).
	 *
	 * Cached per request — on a large instance the unconfigured-permission
	 * branch walks every user via userManager->search(''), so the per-event
	 * audit-notification listener must not pay that cost N times.
	 *
	 * @return list<string>
	 */
	public function getUsersWith(string $permission): array {
		if (isset($this->usersWithCache[$permission])) {
			return $this->usersWithCache[$permission];
		}

		$allowedRoles = $this->getRolesForPermission($permission);
		$guestBlocked = in_array($permission, self::GUEST_BLOCKED_PERMISSIONS, true);
		$userIds = [];

		$candidates = empty($allowedRoles)
			? $this->userManager->search('')
			: $this->collectGroupMembers($allowedRoles);

		foreach ($candidates as $user) {
			$uid = $user->getUID();
			if ($guestBlocked && $this->guestService->isGuestUser($uid)) {
				continue;
			}
			$userIds[$uid] = true;
		}

		return $this->usersWithCache[$permission] = array_keys($userIds);
	}

	/**
	 * @param list<string> $groupIds
	 * @return iterable<\OCP\IUser>
	 */
	private function collectGroupMembers(array $groupIds): iterable {
		foreach ($groupIds as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				continue;
			}
			yield from $group->getUsers();
		}
	}
}
