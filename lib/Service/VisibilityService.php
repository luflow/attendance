<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Service for handling appointment visibility logic.
 * Determines which users can see which appointments based on visibility settings.
 */
class VisibilityService {
	private IGroupManager $groupManager;
	private IUserManager $userManager;
	private PermissionService $permissionService;

	public function __construct(
		IGroupManager $groupManager,
		IUserManager $userManager,
		PermissionService $permissionService,
	) {
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->permissionService = $permissionService;
	}

	/**
	 * Check if a user can see an appointment based on visibility settings.
	 *
	 * @param Appointment $appointment The appointment to check
	 * @param string $userId The user ID to check
	 * @return bool True if the user can see the appointment
	 */
	public function canUserSeeAppointment(Appointment $appointment, string $userId): bool {
		// Users with manage_appointments permission can always see all appointments
		if ($this->permissionService->hasPermission($userId, PermissionService::PERMISSION_MANAGE_APPOINTMENTS)) {
			return true;
		}

		return $this->isUserTargetAttendee($appointment, $userId);
	}

	/**
	 * Check if a user is a target attendee for an appointment.
	 *
	 * Unlike canUserSeeAppointment(), this does NOT include admin bypass.
	 * Use this for check-in lists where you only want actual attendees.
	 *
	 * @param Appointment $appointment The appointment to check
	 * @param string $userId The user ID to check
	 * @return bool True if the user is a target attendee
	 */
	public function isUserTargetAttendee(Appointment $appointment, string $userId): bool {
		$visibleUsers = $appointment->getVisibleUsers();
		$visibleGroups = $appointment->getVisibleGroups();

		// Decode JSON fields
		$visibleUsersList = $visibleUsers ? json_decode($visibleUsers, true) : [];
		$visibleGroupsList = $visibleGroups ? json_decode($visibleGroups, true) : [];

		// If both are empty/null, appointment is visible to all
		if (empty($visibleUsersList) && empty($visibleGroupsList)) {
			return true;
		}

		// Check if user is in visible users list
		if (!empty($visibleUsersList) && in_array($userId, $visibleUsersList)) {
			return true;
		}

		// Check if user is in any of the visible groups
		if (!empty($visibleGroupsList)) {
			$user = $this->userManager->get($userId);
			if ($user) {
				$userGroupIds = $this->groupManager->getUserGroupIds($user);
				foreach ($visibleGroupsList as $groupId) {
					if (in_array($groupId, $userGroupIds)) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Filter a list of appointments to only those visible to a user.
	 *
	 * @param array<Appointment> $appointments The appointments to filter
	 * @param string $userId The user ID to filter for
	 * @return array<Appointment> Filtered appointments
	 */
	public function filterVisibleAppointments(array $appointments, string $userId): array {
		return array_filter($appointments, function (Appointment $appointment) use ($userId) {
			return $this->canUserSeeAppointment($appointment, $userId);
		});
	}

	/**
	 * Get relevant users for an appointment efficiently.
	 *
	 * This method avoids loading ALL users in the system by:
	 * - For restricted appointments: only loading users from visible_users and visible_groups
	 * - For unrestricted appointments: loading users from specified whitelisted groups
	 *
	 * @param Appointment $appointment The appointment
	 * @param array<string> $whitelistedGroups Groups to consider (from ConfigService)
	 * @return array<string, \OCP\IUser> Map of userId => IUser for relevant users
	 */
	public function getRelevantUsersForAppointment(Appointment $appointment, array $whitelistedGroups = []): array {
		$settings = $this->getVisibilitySettings($appointment);
		$relevantUsers = [];

		// Case 1: Appointment has restricted visibility
		if ($this->hasRestrictedVisibility($appointment)) {
			// Add explicitly visible users
			foreach ($settings['users'] as $userId) {
				$user = $this->userManager->get($userId);
				if ($user) {
					$relevantUsers[$userId] = $user;
				}
			}

			// Add users from visible groups
			foreach ($settings['groups'] as $groupId) {
				$group = $this->groupManager->get($groupId);
				if ($group) {
					foreach ($group->getUsers() as $user) {
						$relevantUsers[$user->getUID()] = $user;
					}
				}
			}

			return $relevantUsers;
		}

		// Case 2: Appointment is visible to all
		// If whitelisted groups are configured, only load users from those groups
		if (!empty($whitelistedGroups)) {
			foreach ($whitelistedGroups as $groupId) {
				$group = $this->groupManager->get($groupId);
				if ($group) {
					foreach ($group->getUsers() as $user) {
						$relevantUsers[$user->getUID()] = $user;
					}
				}
			}
			return $relevantUsers;
		}

		// Case 3: No restrictions at all - must load all users
		// This is unavoidable but should be rare in production (admins usually configure whitelisted groups)
		$allUsers = $this->userManager->search('');
		foreach ($allUsers as $user) {
			$relevantUsers[$user->getUID()] = $user;
		}

		return $relevantUsers;
	}

	/**
	 * Parse visibility JSON fields from an appointment.
	 *
	 * @param Appointment $appointment The appointment
	 * @return array{users: array<string>, groups: array<string>}
	 */
	public function getVisibilitySettings(Appointment $appointment): array {
		$visibleUsers = $appointment->getVisibleUsers();
		$visibleGroups = $appointment->getVisibleGroups();

		return [
			'users' => $visibleUsers ? (json_decode($visibleUsers, true) ?: []) : [],
			'groups' => $visibleGroups ? (json_decode($visibleGroups, true) ?: []) : [],
		];
	}

	/**
	 * Check if an appointment has restricted visibility.
	 *
	 * @param Appointment $appointment The appointment
	 * @return bool True if visibility is restricted (not visible to all)
	 */
	public function hasRestrictedVisibility(Appointment $appointment): bool {
		$settings = $this->getVisibilitySettings($appointment);
		return !empty($settings['users']) || !empty($settings['groups']);
	}
}
