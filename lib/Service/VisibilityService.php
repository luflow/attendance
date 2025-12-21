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
		PermissionService $permissionService
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
	 * Get all users who can see a specific appointment.
	 *
	 * @param Appointment $appointment The appointment
	 * @return array<\OCP\IUser> List of users who can see the appointment
	 */
	public function getUsersWhoCanSeeAppointment(Appointment $appointment): array {
		$allUsers = $this->userManager->search('');
		$visibleUsers = [];

		foreach ($allUsers as $user) {
			if ($this->canUserSeeAppointment($appointment, $user->getUID())) {
				$visibleUsers[] = $user;
			}
		}

		return $visibleUsers;
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
