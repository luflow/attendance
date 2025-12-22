<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IConfig;

class AppointmentService {
	private AppointmentMapper $appointmentMapper;
	private AttendanceResponseMapper $responseMapper;
	private IGroupManager $groupManager;
	private IUserManager $userManager;
	private IConfig $config;
	private PermissionService $permissionService;

	public function __construct(AppointmentMapper $appointmentMapper, AttendanceResponseMapper $responseMapper, IGroupManager $groupManager, IUserManager $userManager, IConfig $config, PermissionService $permissionService) {
		$this->appointmentMapper = $appointmentMapper;
		$this->responseMapper = $responseMapper;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->config = $config;
		$this->permissionService = $permissionService;
	}

	/**
	 * Get whitelisted groups from app config
	 * If no groups are configured, return empty array (meaning all groups are allowed)
	 */
	private function getWhitelistedGroups(): array {
		$groupsJson = $this->config->getAppValue('attendance', 'whitelisted_groups', '[]');
		return json_decode($groupsJson, true) ?: [];
	}

	/**
	 * Check if a group is allowed based on whitelist configuration
	 * If no whitelist is configured, all groups are allowed
	 */
	private function isGroupAllowed(string $groupId): bool {
		$whitelistedGroups = $this->getWhitelistedGroups();
		
		// If no groups are whitelisted, allow all groups
		if (empty($whitelistedGroups)) {
			return true;
		}
		
		// Check if group is in whitelist (case-insensitive)
		return in_array(strtolower($groupId), array_map('strtolower', $whitelistedGroups));
	}

	/**
	 * Create a new appointment
	 */
	public function createAppointment(
		string $name,
		string $description,
		string $startDatetime,
		string $endDatetime,
		string $createdBy,
		array $visibleUsers = [],
		array $visibleGroups = []
	): Appointment {

		// Convert ISO 8601 datetime to MySQL format
		$startFormatted = $this->formatDatetime($startDatetime);
		$endFormatted = $this->formatDatetime($endDatetime);

		$appointment = new Appointment();
		$appointment->setName($name);
		$appointment->setDescription($description);
		$appointment->setStartDatetime($startFormatted);
		$appointment->setEndDatetime($endFormatted);
		$appointment->setCreatedBy($createdBy);
		$appointment->setCreatedAt(date('Y-m-d H:i:s'));
		$appointment->setUpdatedAt(date('Y-m-d H:i:s'));
		$appointment->setIsActive(1);
		
		// Set visibility - store as JSON, empty array means visible to all
		$appointment->setVisibleUsers(empty($visibleUsers) ? null : json_encode($visibleUsers));
		$appointment->setVisibleGroups(empty($visibleGroups) ? null : json_encode($visibleGroups));

		return $this->appointmentMapper->insert($appointment);
	}

	/**
	 * Update an existing appointment
	 */
	public function updateAppointment(
		int $id,
		string $name,
		string $description,
		string $startDatetime,
		string $endDatetime,
		string $userId,
		array $visibleUsers = [],
		array $visibleGroups = []
	): Appointment {
		$appointment = $this->appointmentMapper->find($id);
		

		// Convert ISO 8601 datetime to MySQL format
		$startFormatted = $this->formatDatetime($startDatetime);
		$endFormatted = $this->formatDatetime($endDatetime);

		$appointment->setName($name);
		$appointment->setDescription($description);
		$appointment->setStartDatetime($startFormatted);
		$appointment->setEndDatetime($endFormatted);
		$appointment->setUpdatedAt(date('Y-m-d H:i:s'));
		
		// Update visibility - store as JSON, empty array means visible to all
		$appointment->setVisibleUsers(empty($visibleUsers) ? null : json_encode($visibleUsers));
		$appointment->setVisibleGroups(empty($visibleGroups) ? null : json_encode($visibleGroups));

		return $this->appointmentMapper->update($appointment);
	}

	/**
	 * Delete an appointment
	 */
	public function deleteAppointment(int $id, string $userId): void {
		$appointment = $this->appointmentMapper->find($id);
		

		$appointment->setIsActive(0);
		$appointment->setUpdatedAt(date('Y-m-d H:i:s'));
		$this->appointmentMapper->update($appointment);
	}

	/**
	 * Get a single appointment by ID
	 */
	public function getAppointment(int $id): Appointment {
		return $this->appointmentMapper->find($id);
	}

	/**
	 * Get a single appointment with user response and summary
	 */
	public function getAppointmentWithUserResponse(int $id, string $userId): ?array {
		$appointment = $this->appointmentMapper->find($id);

		// Check if user can see this appointment
		if (!$this->canUserSeeAppointment($appointment, $userId)) {
			return null;
		}

		$appointmentData = $appointment->jsonSerialize();
		$appointmentData['userResponse'] = $this->getUserResponse($appointment->getId(), $userId);
		$appointmentData['responseSummary'] = $this->getResponseSummary($appointment->getId());

		return $appointmentData;
	}

	/**
	 * Get all appointments
	 */
	public function getAllAppointments(): array {
		return $this->appointmentMapper->findAll();
	}

	/**
	 * Get upcoming appointments
	 */
	public function getUpcomingAppointments(): array {
		return $this->appointmentMapper->findUpcoming();
	}

	/**
	 * Get past appointments
	 */
	public function getPastAppointments(): array {
		return $this->appointmentMapper->findPast();
	}

	/**
	 * Get appointments created by a specific user
	 */
	public function getAppointmentsByCreator(string $userId): array {
		return $this->appointmentMapper->findByCreatedBy($userId);
	}

	/**
	 * Submit attendance response
	 */
	public function submitResponse(
		int $appointmentId,
		string $userId,
		string $response,
		string $comment = ''
	): AttendanceResponse {
		// Validate response
		if (!in_array($response, ['yes', 'no', 'maybe'])) {
			throw new \InvalidArgumentException('Invalid response. Must be yes, no, or maybe.');
		}

		// Check if appointment exists
		$this->appointmentMapper->find($appointmentId);

		// Check if user already responded
		try {
			$existingResponse = $this->responseMapper->findByAppointmentAndUser($appointmentId, $userId);
			// Update existing response
			$existingResponse->setResponse($response);
			$existingResponse->setComment($comment);
			$existingResponse->setRespondedAt(date('Y-m-d H:i:s'));
			return $this->responseMapper->update($existingResponse);
		} catch (DoesNotExistException $e) {
			// Create new response
			$attendanceResponse = new AttendanceResponse();
			$attendanceResponse->setAppointmentId($appointmentId);
			$attendanceResponse->setUserId($userId);
			$attendanceResponse->setResponse($response);
			$attendanceResponse->setComment($comment);
			$attendanceResponse->setRespondedAt(date('Y-m-d H:i:s'));
			return $this->responseMapper->insert($attendanceResponse);
		}
	}

	/**
	 * Get user's response for an appointment
	 */
	public function getUserResponse(int $appointmentId, string $userId): ?AttendanceResponse {
		try {
			return $this->responseMapper->findByAppointmentAndUser($appointmentId, $userId);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Get all responses for an appointment
	 */
	public function getAppointmentResponses(int $appointmentId): array {
		return $this->responseMapper->findByAppointment($appointmentId);
	}

	/**
	 * Get all responses for an appointment with user details (admin only)
	 */
	public function getAppointmentResponsesWithUsers(int $appointmentId, string $requestingUserId): array {
		$appointment = $this->appointmentMapper->find($appointmentId);
		$responses = $this->responseMapper->findByAppointment($appointmentId);
		$result = [];

		foreach ($responses as $response) {
			// Filter: Only include responses from users who can see this appointment
			if (!$this->canUserSeeAppointment($appointment, $response->getUserId())) {
				continue;
			}

			$user = $this->userManager->get($response->getUserId());
			$responseData = $response->jsonSerialize();
			$responseData['userName'] = $user ? $user->getDisplayName() : $response->getUserId();

			// Add user groups
			if ($user) {
				$userGroups = $this->groupManager->getUserGroups($user);
				$responseData['userGroups'] = array_map(function($group) {
					return $group->getGID();
				}, $userGroups);
			} else {
				$responseData['userGroups'] = [];
			}

			$result[] = $responseData;
		}

		return $result;
	}

	/**
	 * Get response summary for an appointment
	 */
	public function getResponseSummary(int $appointmentId): array {
		$appointment = $this->appointmentMapper->find($appointmentId);
		$responses = $this->responseMapper->findByAppointment($appointmentId);
		
		$summary = [
			'yes' => 0,
			'no' => 0,
			'maybe' => 0,
			'no_response' => 0,
			'by_group' => [],
			'others' => [
				'yes' => 0,
				'no' => 0,
				'maybe' => 0,
				'responses' => []
			]
		];

		// Count responses by type and collect user groups
		$respondedUserIds = [];
		$usersInWhitelistedGroups = [];
		
		foreach ($responses as $response) {
			// Filter: Only include responses from users who can see this appointment
			if (!$this->canUserSeeAppointment($appointment, $response->getUserId())) {
				continue;
			}
			
			$responseValue = $response->getResponse();
			
			// Skip invalid or empty responses
			if (!in_array($responseValue, ['yes', 'no', 'maybe'], true)) {
				continue;
			}
			
			$summary[$responseValue]++;
			$respondedUserIds[] = $response->getUserId();
			
			// Get user groups for this response
			$user = $this->userManager->get($response->getUserId());
			$userInWhitelistedGroup = false;
			
			if ($user) {
				$userGroups = $this->groupManager->getUserGroups($user);
				foreach ($userGroups as $group) {
					$groupId = $group->getGID();
					
					// Check if group is in whitelist
					if ($this->isGroupAllowed($groupId)) {
						$userInWhitelistedGroup = true;
						$usersInWhitelistedGroups[] = $response->getUserId();
						
						if (!isset($summary['by_group'][$groupId])) {
							$summary['by_group'][$groupId] = [
								'yes' => 0,
								'no' => 0,
								'maybe' => 0,
								'no_response' => 0,
								'responses' => []
							];
						}
						$summary['by_group'][$groupId][$responseValue]++;
						
						// Add the detailed response to this group
						$responseData = $response->jsonSerialize();
						$responseData['userName'] = $user->getDisplayName();
						$summary['by_group'][$groupId]['responses'][] = $responseData;
					}
				}
				
				// If user is not in any whitelisted group, add to "others"
				if (!$userInWhitelistedGroup) {
					$summary['others'][$responseValue]++;
					$responseData = $response->jsonSerialize();
					$responseData['userName'] = $user->getDisplayName();
					$summary['others']['responses'][] = $responseData;
				}
			}
		}

		// Calculate users who haven't responded and group them by groups
		$allGroups = $this->groupManager->search('');
		foreach ($allGroups as $group) {
			$groupId = $group->getGID();
			
			// Skip groups not in whitelist
			if (!$this->isGroupAllowed($groupId)) {
				continue;
			}
			
			if (!isset($summary['by_group'][$groupId])) {
				$summary['by_group'][$groupId] = [
					'yes' => 0,
					'no' => 0,
					'maybe' => 0,
					'no_response' => 0,
					'responses' => [],
					'non_responding_users' => []
				];
			}
			
			// Get users in this group who haven't responded
			$groupUsers = $this->groupManager->get($groupId)->getUsers();
			$groupUserIds = array_map(function($user) { return $user->getUID(); }, $groupUsers);

			// Filter to only users who can see this appointment
			$visibleGroupUserIds = array_filter($groupUserIds, function ($userId) use ($appointment) {
				return $this->canUserSeeAppointment($appointment, $userId);
			});

			$nonRespondedInGroup = array_diff($visibleGroupUserIds, $respondedUserIds);
			$summary['by_group'][$groupId]['no_response'] += count($nonRespondedInGroup);

			// Add names and IDs of non-responding users
			foreach ($nonRespondedInGroup as $userId) {
				$user = $this->userManager->get($userId);
				if ($user) {
					$summary['by_group'][$groupId]['non_responding_users'][] = [
						'userId' => $userId,
						'displayName' => $user->getDisplayName()
					];
				}
			}
		}

		// Calculate total users who haven't responded (only users who belong to relevant groups AND can see this appointment)
		$allUsers = $this->userManager->search('');
		$usersInGroups = [];
		$nonRespondingUsers = [];
		foreach ($allUsers as $user) {
			$userId = $user->getUID();
			
			// Filter: Only include users who can see this appointment
			if (!$this->canUserSeeAppointment($appointment, $userId)) {
				continue;
			}
			
			$userGroups = $this->groupManager->getUserGroups($user);
			$relevantGroups = [];
			
			// Filter to only include whitelisted groups
			foreach ($userGroups as $group) {
				$groupId = $group->getGID();
				if ($this->isGroupAllowed($groupId)) {
					$relevantGroups[] = $groupId;
				}
			}
			
			// Only count users who belong to at least one relevant group
			if (count($relevantGroups) > 0) {
				$usersInGroups[] = $userId;
				// Check if this user hasn't responded
				if (!in_array($userId, $respondedUserIds)) {
					$nonRespondingUsers[] = [
						'userId' => $userId,
						'displayName' => $user->getDisplayName()
					];
				}
			}
		}
		
		$summary['no_response'] = count($nonRespondingUsers);
		$summary['non_responding_users'] = $nonRespondingUsers;

		// Sort by_group based on whitelisted groups order or alphabetically
		$whitelistedGroups = $this->getWhitelistedGroups();
		$sortedByGroup = [];
		
		if (!empty($whitelistedGroups)) {
			// First add groups in the order they appear in settings
			foreach ($whitelistedGroups as $groupId) {
				if (isset($summary['by_group'][$groupId])) {
					$sortedByGroup[$groupId] = $summary['by_group'][$groupId];
				}
			}
			// Then add any remaining groups alphabetically
			$remainingGroups = array_diff(array_keys($summary['by_group']), $whitelistedGroups);
			sort($remainingGroups);
			foreach ($remainingGroups as $groupId) {
				$sortedByGroup[$groupId] = $summary['by_group'][$groupId];
			}
		} else {
			// No whitelist configured, sort alphabetically
			$groupIds = array_keys($summary['by_group']);
			sort($groupIds);
			foreach ($groupIds as $groupId) {
				$sortedByGroup[$groupId] = $summary['by_group'][$groupId];
			}
		}
		
		$summary['by_group'] = $sortedByGroup;

		return $summary;
	}

	/**
	 * Get minimal appointment data for navigation menu
	 * Returns only fields needed for sidebar: id, name, startDatetime, userResponse
	 */
	public function getAppointmentsForNavigation(string $userId): array {
		$currentAppointments = $this->getUpcomingAppointments();
		$pastAppointments = $this->getPastAppointments();

		$result = [
			'current' => [],
			'past' => [],
		];

		// Process current appointments
		foreach ($currentAppointments as $appointment) {
			if (!$this->canUserSeeAppointment($appointment, $userId)) {
				continue;
			}

			$userResponse = $this->getUserResponse($appointment->getId(), $userId);

			$result['current'][] = [
				'id' => $appointment->getId(),
				'name' => $appointment->getName(),
				'startDatetime' => $this->formatDatetimeToUtc($appointment->getStartDatetime()),
				'userResponse' => $userResponse ? ['response' => $userResponse->getResponse()] : null,
			];
		}

		// Process past appointments
		foreach ($pastAppointments as $appointment) {
			if (!$this->canUserSeeAppointment($appointment, $userId)) {
				continue;
			}

			$userResponse = $this->getUserResponse($appointment->getId(), $userId);

			$result['past'][] = [
				'id' => $appointment->getId(),
				'name' => $appointment->getName(),
				'startDatetime' => $this->formatDatetimeToUtc($appointment->getStartDatetime()),
				'userResponse' => $userResponse ? ['response' => $userResponse->getResponse()] : null,
			];
		}

		return $result;
	}

	/**
	 * Format datetime to UTC ISO 8601 format (for service-level use)
	 */
	private function formatDatetimeToUtc(string $datetime): string {
		try {
			$utcTimezone = new \DateTimeZone('UTC');
			$date = new \DateTime($datetime, $utcTimezone);
			return $date->format('Y-m-d\TH:i:s\Z');
		} catch (\Exception $e) {
			return $datetime;
		}
	}

	/**
	 * Get appointments with user responses
	 */
	public function getAppointmentsWithUserResponses(string $userId, bool $showPastAppointments = false): array {
		// When showPastAppointments is true, return ONLY past appointments
		// When false, return ONLY upcoming appointments
		if ($showPastAppointments) {
			$appointments = $this->getPastAppointments();
		} else {
			$appointments = $this->getUpcomingAppointments();
		}
		
		$result = [];

		foreach ($appointments as $appointment) {
			// Filter based on visibility settings
			if (!$this->canUserSeeAppointment($appointment, $userId)) {
				continue;
			}
			
			$appointmentData = $appointment->jsonSerialize();
			$appointmentData['userResponse'] = $this->getUserResponse($appointment->getId(), $userId);
			$appointmentData['responseSummary'] = $this->getResponseSummary($appointment->getId());
			$result[] = $appointmentData;
		}

		return $result;
	}

	/**
	 * Get upcoming appointments for dashboard widget
	 */
	public function getUpcomingAppointmentsForWidget(string $userId, int $limit = 5): array {
		$appointments = $this->getUpcomingAppointments();
		$result = [];

		$count = 0;
		foreach ($appointments as $appointment) {
			if ($count >= $limit) {
				break;
			}

			// Filter based on visibility settings
			if (!$this->canUserSeeAppointment($appointment, $userId)) {
				continue;
			}

			$appointmentData = $appointment->jsonSerialize();
			$appointmentData['userResponse'] = $this->getUserResponse($appointment->getId(), $userId);
			$result[] = $appointmentData;
			$count++;
		}

		return $result;
	}

	/**
	 * Check-in an attendee
	 */
	public function checkinResponse(
		int $appointmentId,
		string $targetUserId,
		?string $response,
		?string $comment,
		string $adminUserId
	): AttendanceResponse {

		// Validate response if provided
		if ($response !== null && !in_array($response, ['yes', 'no', 'maybe'])) {
			throw new \InvalidArgumentException('Invalid response. Must be yes, no, or maybe.');
		}

		// Find existing response or create new one
		try {
			$attendanceResponse = $this->responseMapper->findByAppointmentAndUser($appointmentId, $targetUserId);
		} catch (DoesNotExistException $e) {
			// Create new response if none exists
			$attendanceResponse = new AttendanceResponse();
			$attendanceResponse->setAppointmentId($appointmentId);
			$attendanceResponse->setUserId($targetUserId);
		}

		// Set checkin values
		if ($response !== null) {
			$attendanceResponse->setCheckinState($response);
		}
		if ($comment !== null) {
			$attendanceResponse->setCheckinComment($comment);
		}
		$attendanceResponse->setCheckinBy($adminUserId);
		$attendanceResponse->setCheckinAt(date('Y-m-d H:i:s'));

		// Save or update
		if ($attendanceResponse->getId()) {
			return $this->responseMapper->update($attendanceResponse);
		} else {
			return $this->responseMapper->insert($attendanceResponse);
		}
	}


	/**
	 * Get check-in data for an appointment
	 */
	public function getCheckinData(int $appointmentId): array {
		// Get the appointment
		$appointment = $this->appointmentMapper->find($appointmentId);
		
		// Get all responses for this appointment
		$responses = $this->responseMapper->findByAppointment($appointmentId);
		
		// Get all users in the system
		$allUsers = $this->userManager->search('');
		
		// Create unified user list
		$users = [];
		$userResponseMap = [];
		
		// Create a map of user responses
		foreach ($responses as $response) {
			$userResponseMap[$response->getUserId()] = $response;
		}
		
		// Get whitelisted groups for filtering
		$whitelistedGroups = $this->getWhitelistedGroups();
		
		// If no whitelist is configured, get all groups; otherwise use whitelisted groups
		if (empty($whitelistedGroups)) {
			$allGroups = $this->groupManager->search('');
			$userGroups = array_map(function($group) { return $group->getGID(); }, $allGroups);
		} else {
			$userGroups = $whitelistedGroups;
		}
		
		// Add "Others" group to the list for filtering
		$userGroups[] = 'Others';
		
		// Build unified user list
		foreach ($allUsers as $user) {
			$userId = $user->getUID();
			
			// Filter: Only include users who can see this appointment
			if (!$this->canUserSeeAppointment($appointment, $userId)) {
				continue;
			}
			
			$displayName = $user->getDisplayName();
			
			// Get user's groups
			$userGroupIds = $this->groupManager->getUserGroupIds($user);
			
			// Check if user belongs to any whitelisted group (if whitelist is configured)
			$userInWhitelistedGroup = empty($whitelistedGroups);
			if (!empty($whitelistedGroups)) {
				foreach ($userGroupIds as $groupId) {
					if (in_array($groupId, $whitelistedGroups)) {
						$userInWhitelistedGroup = true;
						break;
					}
				}
			}
			
			// Add "Others" group for users not in whitelisted groups
			$effectiveGroups = $userGroupIds;
			if (!$userInWhitelistedGroup) {
				$effectiveGroups = ['Others'];
			}
			
			// Create user data structure with response info if available
			$userData = [
				'userId' => $userId,
				'displayName' => $displayName,
				'groups' => $effectiveGroups,
				'response' => null,
				'comment' => null,
				'isCheckedIn' => false,
				'checkinState' => null,
				'checkinComment' => null,
				'checkinBy' => null,
				'checkinAt' => null,
			];
			
			// Add response data if user has responded
			if (isset($userResponseMap[$userId])) {
				$response = $userResponseMap[$userId];
				$userData['response'] = $response->getResponse();
				$userData['comment'] = $response->getComment();
				$userData['isCheckedIn'] = $response->isCheckedIn();
				$userData['checkinState'] = $response->getCheckinState();
				$userData['checkinComment'] = $response->getCheckinComment();
				$userData['checkinBy'] = $response->getCheckinBy();
				$userData['checkinAt'] = $response->getCheckinAt();
			}
			
			$users[] = $userData;
		}
		
		return [
			'appointment' => $appointment->jsonSerialize(),
			'users' => $users,
			'userGroups' => array_values($userGroups),
		];
	}

	/**
	 * Search for users and groups
	 */
	public function searchUsersAndGroups(string $search = ''): array {
		$results = [];
		
		// Search users
		$users = $this->userManager->search($search, 20);
		foreach ($users as $user) {
			$results[] = [
				'id' => $user->getUID(),
				'label' => $user->getDisplayName(),
				'type' => 'user',
				'icon' => 'icon-user',
			];
		}
		
		// Search groups
		$groups = $this->groupManager->search($search);
		foreach ($groups as $group) {
			$results[] = [
				'id' => $group->getGID(),
				'label' => $group->getDisplayName(),
				'type' => 'group',
				'icon' => 'icon-group',
			];
		}
		
		return $results;
	}

	/**
	 * Check if a user can see an appointment based on visibility settings
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
	 * Convert ISO 8601 datetime to MySQL format
	 */
	private function formatDatetime(string $datetime): string {
		try {
			$date = new \DateTime($datetime);
			return $date->format('Y-m-d H:i:s');
		} catch (\Exception $e) {
			// If parsing fails, assume it's already in MySQL format
			return $datetime;
		}
	}
}
