<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\IUserManager;

class PermissionService {
	private IConfig $config;
	private IGroupManager $groupManager;
	private IUserSession $userSession;
	private IUserManager $userManager;

	public const PERMISSION_MANAGE_APPOINTMENTS = 'manage_appointments';
	public const PERMISSION_CHECKIN = 'checkin';

	public function __construct(IConfig $config, IGroupManager $groupManager, IUserSession $userSession, IUserManager $userManager) {
		$this->config = $config;
		$this->groupManager = $groupManager;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
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
			self::PERMISSION_CHECKIN => $this->getRolesForPermission(self::PERMISSION_CHECKIN)
		];
	}

	/**
	 * Set all permission settings
	 */
	public function setAllPermissionSettings(array $permissions): void {
		foreach ($permissions as $permission => $roles) {
			if (in_array($permission, [self::PERMISSION_MANAGE_APPOINTMENTS, self::PERMISSION_CHECKIN])) {
				$this->setRolesForPermission($permission, $roles);
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
}
