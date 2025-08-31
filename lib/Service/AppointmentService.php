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

	public function __construct(AppointmentMapper $appointmentMapper, AttendanceResponseMapper $responseMapper, IGroupManager $groupManager, IUserManager $userManager, IConfig $config) {
		$this->appointmentMapper = $appointmentMapper;
		$this->responseMapper = $responseMapper;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->config = $config;
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
		string $createdBy
	): Appointment {
		// Check if user is admin
		if (!$this->groupManager->isAdmin($createdBy)) {
			throw new \Exception('Only administrators can create appointments');
		}

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
		string $userId
	): Appointment {
		$appointment = $this->appointmentMapper->find($id);
		
		// Check if user is admin or creator
		if (!$this->groupManager->isAdmin($userId) && $appointment->getCreatedBy() !== $userId) {
			throw new \Exception('Only administrators can update appointments');
		}

		// Convert ISO 8601 datetime to MySQL format
		$startFormatted = $this->formatDatetime($startDatetime);
		$endFormatted = $this->formatDatetime($endDatetime);

		$appointment->setName($name);
		$appointment->setDescription($description);
		$appointment->setStartDatetime($startFormatted);
		$appointment->setEndDatetime($endFormatted);
		$appointment->setUpdatedAt(date('Y-m-d H:i:s'));

		return $this->appointmentMapper->update($appointment);
	}

	/**
	 * Delete an appointment
	 */
	public function deleteAppointment(int $id, string $userId): void {
		$appointment = $this->appointmentMapper->find($id);
		
		// Check if user is admin or creator
		if (!$this->groupManager->isAdmin($userId) && $appointment->getCreatedBy() !== $userId) {
			throw new \Exception('Not authorized to delete this appointment');
		}

		$appointment->setIsActive(0);
		$appointment->setUpdatedAt(date('Y-m-d H:i:s'));
		$this->appointmentMapper->update($appointment);
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
		// Check if requesting user is admin
		if (!$this->groupManager->isAdmin($requestingUserId)) {
			throw new \Exception('Only administrators can view detailed responses');
		}

		$responses = $this->responseMapper->findByAppointment($appointmentId);
		$result = [];

		foreach ($responses as $response) {
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
		$responses = $this->responseMapper->findByAppointment($appointmentId);
		
		$summary = [
			'yes' => 0,
			'no' => 0,
			'maybe' => 0,
			'no_response' => 0,
			'by_group' => []
		];

		// Count responses by type and collect user groups
		$respondedUserIds = [];
		foreach ($responses as $response) {
			$summary[$response->getResponse()]++;
			$respondedUserIds[] = $response->getUserId();
			
			// Get user groups for this response
			$user = $this->userManager->get($response->getUserId());
			if ($user) {
				$userGroups = $this->groupManager->getUserGroups($user);
				foreach ($userGroups as $group) {
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
							'responses' => []
						];
					}
					$summary['by_group'][$groupId][$response->getResponse()]++;
					
					// Add the detailed response to this group
					$responseData = $response->jsonSerialize();
					$responseData['userName'] = $user->getDisplayName();
					$summary['by_group'][$groupId]['responses'][] = $responseData;
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
			$nonRespondedInGroup = array_diff($groupUserIds, $respondedUserIds);
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

		// Calculate total users who haven't responded (only users who belong to relevant groups)
		$allUsers = $this->userManager->search('');
		$usersInGroups = [];
		$nonRespondingUsers = [];
		foreach ($allUsers as $user) {
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
				$usersInGroups[] = $user->getUID();
				// Check if this user hasn't responded
				if (!in_array($user->getUID(), $respondedUserIds)) {
					$nonRespondingUsers[] = [
						'userId' => $user->getUID(),
						'displayName' => $user->getDisplayName()
					];
				}
			}
		}
		
		$totalUsersInGroups = count($usersInGroups);
		$totalResponses = count($responses);
		$summary['no_response'] = max(0, $totalUsersInGroups - $totalResponses);
		$summary['non_responding_users'] = $nonRespondingUsers;

		return $summary;
	}

	/**
	 * Get appointments with user responses
	 */
	public function getAppointmentsWithUserResponses(string $userId, bool $showPastAppointments = false): array {
		// Admins can choose to see all or only upcoming appointments, regular users see only upcoming ones
		if ($this->groupManager->isAdmin($userId) && $showPastAppointments) {
			$appointments = $this->getAllAppointments();
		} else {
			$appointments = $this->getUpcomingAppointments();
		}
		
		$result = [];

		foreach ($appointments as $appointment) {
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

			$appointmentData = $appointment->jsonSerialize();
			$appointmentData['userResponse'] = $this->getUserResponse($appointment->getId(), $userId);
			$result[] = $appointmentData;
			$count++;
		}

		return $result;
	}

	/**
	 * Checkin a user's response (admin only)
	 */
	public function checkinResponse(
		int $appointmentId,
		string $targetUserId,
		?string $response,
		string $comment,
		string $adminUserId
	): AttendanceResponse {
		// Check if requesting user is admin
		if (!$this->groupManager->isAdmin($adminUserId)) {
			throw new \Exception('Only administrators can checkin responses');
		}

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
		$attendanceResponse->setCheckinComment($comment);
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
	 * Get check-in data for an appointment (admin only)
	 */
	public function getCheckinData(int $appointmentId): array {
		// Get the appointment
		$appointment = $this->appointmentMapper->find($appointmentId);
		
		// Get all responses for this appointment
		$responses = $this->responseMapper->findByAppointment($appointmentId);
		
		// Get all users in the system
		$allUsers = $this->userManager->search('');
		
		// Organize users by response status
		$respondingUsers = [];
		$nonRespondingUsers = [];
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
		
		// Categorize users
		foreach ($allUsers as $user) {
			$userId = $user->getUID();
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
			
			// Skip users not in whitelisted groups
			if (!$userInWhitelistedGroup) {
				continue;
			}
			
			if (isset($userResponseMap[$userId])) {
				$response = $userResponseMap[$userId];
				
				$respondingUsers[] = [
					'userId' => $userId,
					'displayName' => $displayName,
					'response' => $response->getResponse(),
					'comment' => $response->getComment(),
					'isCheckedIn' => $response->isCheckedIn(),
					'checkinState' => $response->getCheckinState(),
					'checkinComment' => $response->getCheckinComment(),
					'checkinBy' => $response->getCheckinBy(),
					'checkinAt' => $response->getCheckinAt(),
					'groups' => $userGroupIds,
				];
			} else {
				$nonRespondingUsers[] = [
					'userId' => $userId,
					'displayName' => $displayName,
					'groups' => $userGroupIds,
				];
			}
		}
		
		return [
			'appointment' => $appointment->jsonSerialize(),
			'respondingUsers' => $respondingUsers,
			'nonRespondingUsers' => $nonRespondingUsers,
			'userGroups' => array_values($userGroups),
		];
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
