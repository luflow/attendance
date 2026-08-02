<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
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
	public const PERMISSION_CREATE_APPOINTMENTS = 'create_appointments';
	public const PERMISSION_RESPOND_FOR_OTHERS = 'respond_for_others';

	private const GUEST_BLOCKED_PERMISSIONS = [
		self::PERMISSION_MANAGE_APPOINTMENTS,
		self::PERMISSION_CHECKIN,
		self::PERMISSION_CREATE_APPOINTMENTS,
		self::PERMISSION_RESPOND_FOR_OTHERS,
	];

	/**
	 * Permissions that grant NOBODY when no groups are configured. All other
	 * permissions default to "everyone" on empty config. Additive permissions
	 * (rights on top of manage_appointments) belong here — otherwise an
	 * upgrade would silently grant them to all users.
	 */
	private const CLOSED_WHEN_UNCONFIGURED = [
		self::PERMISSION_CREATE_APPOINTMENTS,
		self::PERMISSION_RESPOND_FOR_OTHERS,
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

		if (empty($allowedRoles)) {
			return !in_array($permission, self::CLOSED_WHEN_UNCONFIGURED, true);
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
			self::PERMISSION_CREATE_APPOINTMENTS => $this->getRolesForPermission(self::PERMISSION_CREATE_APPOINTMENTS),
			self::PERMISSION_RESPOND_FOR_OTHERS => $this->getRolesForPermission(self::PERMISSION_RESPOND_FOR_OTHERS),
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
			'PERMISSION_CREATE_APPOINTMENTS' => self::PERMISSION_CREATE_APPOINTMENTS,
			'PERMISSION_RESPOND_FOR_OTHERS' => self::PERMISSION_RESPOND_FOR_OTHERS,
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
				self::PERMISSION_CREATE_APPOINTMENTS,
				self::PERMISSION_RESPOND_FOR_OTHERS,
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
	 * Check if user can create appointments: either a full manager, or member
	 * of one of the create_appointments groups (delegated creation). Creators
	 * become organizers of their own appointments.
	 */
	public function canCreateAppointments(string $userId): bool {
		return $this->canManageAppointments($userId)
			|| $this->hasPermission($userId, self::PERMISSION_CREATE_APPOINTMENTS);
	}

	/**
	 * Check if a user is an organizer of the given appointment. Guests can
	 * never hold organizer rights, even if their ID ends up in the list.
	 */
	public function isOrganizer(Appointment $appointment, string $userId): bool {
		if ($this->guestService->isGuestUser($userId)) {
			return false;
		}
		return in_array($userId, $appointment->getOrganizersList(), true);
	}

	/**
	 * Appointment-aware manage check: global managers plus organizers of this
	 * specific appointment. Organizers hold a fixed right set on their own
	 * appointment (edit everything, delete, insights) — see issue #73.
	 */
	public function canManageAppointment(string $userId, Appointment $appointment): bool {
		return $this->canManageAppointments($userId)
			|| $this->isOrganizer($appointment, $userId);
	}

	/**
	 * Check if user can see response overview
	 */
	public function canSeeResponseOverview(string $userId): bool {
		return $this->hasPermission($userId, self::PERMISSION_SEE_RESPONSE_OVERVIEW);
	}

	/**
	 * Appointment-aware response overview check: the global permission plus
	 * organizers of this specific appointment (their "insights").
	 */
	public function canSeeResponseOverviewFor(string $userId, Appointment $appointment): bool {
		return $this->canSeeResponseOverview($userId)
			|| $this->isOrganizer($appointment, $userId);
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
	 * Check if user can set or clear responses on behalf of other users.
	 * Deliberately NOT granted to managers or organizers automatically —
	 * only members of the explicitly configured groups get it, and with no
	 * groups configured nobody does (CLOSED_WHEN_UNCONFIGURED).
	 */
	public function canRespondForOthers(string $userId): bool {
		return $this->hasPermission($userId, self::PERMISSION_RESPOND_FOR_OTHERS);
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
