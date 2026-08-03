<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

class PermissionService {
	private IConfig $config;
	private IAppConfig $appConfig;
	private IGroupManager $groupManager;
	private IUserSession $userSession;
	private IUserManager $userManager;
	private GuestService $guestService;
	/** @var array<string, list<string>> per-request cache for getUsersWith() */
	private array $usersWithCache = [];
	/**
	 * Per-request caches: hasPermission() runs per appointment/response in
	 * list endpoints, so the JSON decode and mode lookup must not repeat.
	 *
	 * @var array<string, list<string>>
	 */
	private array $rolesCache = [];
	/** @var array<string, string> */
	private array $modeCache = [];

	public const PERMISSION_MANAGE_APPOINTMENTS = 'manage_appointments';
	public const PERMISSION_CHECKIN = 'checkin';
	public const PERMISSION_SEE_RESPONSE_OVERVIEW = 'see_response_overview';
	public const PERMISSION_SEE_RESPONSE_COUNTS = 'see_response_counts';
	public const PERMISSION_SEE_COMMENTS = 'see_comments';
	public const PERMISSION_SELF_CHECKIN = 'self_checkin';
	public const PERMISSION_CREATE_APPOINTMENTS = 'create_appointments';
	public const PERMISSION_RESPOND_FOR_OTHERS = 'respond_for_others';

	public const MODE_ALL = 'all';
	public const MODE_GROUPS = 'groups';
	public const MODE_NOBODY = 'nobody';

	public const MODES = [self::MODE_ALL, self::MODE_GROUPS, self::MODE_NOBODY];

	public const ALL_PERMISSIONS = [
		self::PERMISSION_MANAGE_APPOINTMENTS,
		self::PERMISSION_CHECKIN,
		self::PERMISSION_SEE_RESPONSE_OVERVIEW,
		self::PERMISSION_SEE_RESPONSE_COUNTS,
		self::PERMISSION_SEE_COMMENTS,
		self::PERMISSION_SELF_CHECKIN,
		self::PERMISSION_CREATE_APPOINTMENTS,
		self::PERMISSION_RESPOND_FOR_OTHERS,
	];

	private const GUEST_BLOCKED_PERMISSIONS = [
		self::PERMISSION_MANAGE_APPOINTMENTS,
		self::PERMISSION_CHECKIN,
		self::PERMISSION_CREATE_APPOINTMENTS,
		self::PERMISSION_RESPOND_FOR_OTHERS,
	];

	/**
	 * Permissions that default to "nobody" while no mode is stored — the
	 * additive ones, which must never open up on their own. Everything else
	 * defaults to "all". This is the live default policy: it also decides the
	 * starting mode of any permission added in a future release, and it is
	 * what an empty group list meant before modes were explicit.
	 */
	private const DEFAULT_NOBODY = [
		self::PERMISSION_CREATE_APPOINTMENTS,
		self::PERMISSION_RESPOND_FOR_OTHERS,
	];

	public function __construct(
		IConfig $config,
		IAppConfig $appConfig,
		IGroupManager $groupManager,
		IUserSession $userSession,
		IUserManager $userManager,
		GuestService $guestService,
	) {
		$this->config = $config;
		$this->appConfig = $appConfig;
		$this->groupManager = $groupManager;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->guestService = $guestService;
	}

	/**
	 * Get roles that have a specific permission
	 *
	 * @return list<string>
	 */
	public function getRolesForPermission(string $permission): array {
		if (isset($this->rolesCache[$permission])) {
			return $this->rolesCache[$permission];
		}

		$rolesJson = $this->config->getAppValue('attendance', 'permission_' . $permission, '[]');
		$roles = json_decode($rolesJson, true);
		if (!is_array($roles)) {
			return $this->rolesCache[$permission] = [];
		}

		return $this->rolesCache[$permission] = array_values(array_filter($roles, 'is_string'));
	}

	/**
	 * Set roles that have a specific permission
	 *
	 * @param list<string> $roles
	 */
	public function setRolesForPermission(string $permission, array $roles): void {
		$configKey = 'permission_' . $permission;
		$this->config->setAppValue('attendance', $configKey, json_encode($roles, JSON_THROW_ON_ERROR));
		$this->rolesCache[$permission] = $roles;
		unset($this->usersWithCache[$permission]);
	}

	/**
	 * Get the access mode for a permission. With no mode stored, derive it
	 * from the group list and the default policy — the same semantics the
	 * pre-mode storage had, and what a brand-new permission starts with.
	 *
	 * The mode keys are new and live on the typed IAppConfig API; the group
	 * lists predate it and stay on IConfig so older app versions keep
	 * reading them after a downgrade.
	 */
	public function getModeForPermission(string $permission): string {
		if (isset($this->modeCache[$permission])) {
			return $this->modeCache[$permission];
		}

		$mode = $this->appConfig->getValueString('attendance', 'permission_' . $permission . '_mode');
		if (!in_array($mode, self::MODES, true)) {
			$mode = $this->impliedModeFor($permission, $this->getRolesForPermission($permission));
		}

		return $this->modeCache[$permission] = $mode;
	}

	/**
	 * The mode a bare group list implies: configured groups, otherwise the
	 * default policy for the permission.
	 *
	 * @param list<string> $roles
	 */
	private function impliedModeFor(string $permission, array $roles): string {
		if (!empty($roles)) {
			return self::MODE_GROUPS;
		}
		return in_array($permission, self::DEFAULT_NOBODY, true)
			? self::MODE_NOBODY
			: self::MODE_ALL;
	}

	/**
	 * Set mode and groups for a permission in one go.
	 *
	 * @param list<string> $groups
	 */
	public function setPermission(string $permission, string $mode, array $groups): void {
		if (!in_array($mode, self::MODES, true)) {
			throw new \InvalidArgumentException('Invalid permission mode: ' . $mode);
		}
		$this->appConfig->setValueString('attendance', 'permission_' . $permission . '_mode', $mode);
		$this->modeCache[$permission] = $mode;
		$this->setRolesForPermission($permission, $groups);
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

		$mode = $this->getModeForPermission($permission);
		if ($mode === self::MODE_ALL) {
			return true;
		}
		if ($mode === self::MODE_NOBODY) {
			return false;
		}

		// Get user object and their groups
		$user = $this->userManager->get($userId);
		if (!$user) {
			return false;
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);

		return !empty(array_intersect($this->getRolesForPermission($permission), $userGroups));
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
	 * Get all permission settings as explicit mode + groups pairs
	 *
	 * @return array<string, array{mode: string, groups: list<string>}>
	 */
	public function getAllPermissionSettings(): array {
		$settings = [];
		foreach (self::ALL_PERMISSIONS as $permission) {
			$settings[$permission] = [
				'mode' => $this->getModeForPermission($permission),
				'groups' => $this->getRolesForPermission($permission),
			];
		}
		return $settings;
	}

	/**
	 * Set all permission settings. Accepts the explicit shape
	 * `{mode: string, groups: list<string>}` per permission, and — for
	 * older clients — a bare group list, interpreted with the legacy
	 * empty-list semantics.
	 *
	 * @param array<string, array{mode: string, groups?: list<string>}|list<string>> $permissions
	 */
	public function setAllPermissionSettings(array $permissions): void {
		foreach ($permissions as $permission => $value) {
			// Older clients send uppercase constant names ("PERMISSION_CHECKIN")
			$permissionValue = str_starts_with($permission, 'PERMISSION_')
				? strtolower(substr($permission, strlen('PERMISSION_')))
				: $permission;

			if (!in_array($permissionValue, self::ALL_PERMISSIONS, true)) {
				continue;
			}

			if (isset($value['mode'])) {
				$this->setPermission($permissionValue, $value['mode'], $value['groups'] ?? []);
			} else {
				$groups = array_values(array_filter($value, 'is_string'));
				$this->setPermission(
					$permissionValue,
					$this->impliedModeFor($permissionValue, $groups),
					$groups,
				);
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
	 * Check if user can see the aggregate yes/no/maybe counts. Holders of the
	 * full overview see them implicitly — the counts are a strict subset.
	 */
	public function canSeeResponseCounts(string $userId): bool {
		return $this->hasPermission($userId, self::PERMISSION_SEE_RESPONSE_COUNTS)
			|| $this->canSeeResponseOverview($userId);
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
	 * only the configured mode/groups decide, defaulting to nobody.
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
	 * Get all user IDs that have the given permission, following the
	 * permission's mode (all users, configured groups, or nobody).
	 *
	 * Cached per request — on a large instance the "all" mode walks every
	 * user via userManager->search(''), so the per-event audit-notification
	 * listener must not pay that cost N times.
	 *
	 * @return list<string>
	 */
	public function getUsersWith(string $permission): array {
		if (isset($this->usersWithCache[$permission])) {
			return $this->usersWithCache[$permission];
		}

		$mode = $this->getModeForPermission($permission);
		if ($mode === self::MODE_NOBODY) {
			return $this->usersWithCache[$permission] = [];
		}

		$guestBlocked = in_array($permission, self::GUEST_BLOCKED_PERMISSIONS, true);
		$userIds = [];

		$candidates = $mode === self::MODE_ALL
			? $this->userManager->search('')
			: $this->collectGroupMembers($this->getRolesForPermission($permission));

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
