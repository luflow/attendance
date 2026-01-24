<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;

/**
 * Service for handling check-in operations.
 * Manages attendance check-in for appointments.
 */
class CheckinService {
	private AppointmentMapper $appointmentMapper;
	private AttendanceResponseMapper $responseMapper;
	private ConfigService $configService;
	private VisibilityService $visibilityService;
	private IGroupManager $groupManager;

	public function __construct(
		AppointmentMapper $appointmentMapper,
		AttendanceResponseMapper $responseMapper,
		ConfigService $configService,
		VisibilityService $visibilityService,
		IGroupManager $groupManager,
	) {
		$this->appointmentMapper = $appointmentMapper;
		$this->responseMapper = $responseMapper;
		$this->configService = $configService;
		$this->visibilityService = $visibilityService;
		$this->groupManager = $groupManager;
	}

	/**
	 * Check-in an attendee.
	 *
	 * @param int $appointmentId The appointment ID
	 * @param string $targetUserId The user being checked in
	 * @param string|null $response The attendance response (yes/no/maybe)
	 * @param string|null $comment Optional comment
	 * @param string $adminUserId The admin performing the check-in
	 * @return AttendanceResponse The updated or created response
	 * @throws \InvalidArgumentException If response is invalid
	 */
	public function checkinResponse(
		int $appointmentId,
		string $targetUserId,
		?string $response,
		?string $comment,
		string $adminUserId,
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
	 * Get check-in data for an appointment.
	 *
	 * @param int $appointmentId The appointment ID
	 * @return array Check-in data including appointment, users, and groups
	 */
	public function getCheckinData(int $appointmentId): array {
		$appointment = $this->appointmentMapper->find($appointmentId);
		$responses = $this->responseMapper->findByAppointment($appointmentId);

		// Get whitelisted groups for filtering
		$whitelistedGroups = $this->configService->getWhitelistedGroups();

		// Get relevant users efficiently - this filters by whitelisted groups when configured
		$relevantUsers = $this->visibilityService->getRelevantUsersForAppointment($appointment, $whitelistedGroups);

		// Create a map of user responses
		$userResponseMap = [];
		foreach ($responses as $response) {
			$userResponseMap[$response->getUserId()] = $response;
		}

		// Build group list for filtering UI
		$userGroups = $this->buildGroupList($whitelistedGroups);

		// Build unified user list
		$users = $this->buildUserList($appointment, $relevantUsers, $userResponseMap, $whitelistedGroups);

		return [
			'appointment' => $appointment->jsonSerialize(),
			'users' => $users,
			'userGroups' => array_values($userGroups),
		];
	}

	/**
	 * Build the list of groups for filtering.
	 */
	private function buildGroupList(array $whitelistedGroups): array {
		if (empty($whitelistedGroups)) {
			$allGroups = $this->groupManager->search('');
			$groups = array_map(fn ($group) => $group->getGID(), $allGroups);
			// Add "Others" group only when no whitelisted groups are configured
			$groups[] = 'Others';
		} else {
			// When whitelisted groups are configured, only show those groups (no "Others")
			$groups = $whitelistedGroups;
		}

		return $groups;
	}

	/**
	 * Build the unified user list with response data.
	 *
	 * @param \OCA\Attendance\Db\Appointment $appointment The appointment
	 * @param array<string, \OCP\IUser> $relevantUsers Map of userId => IUser (pre-filtered by whitelisted groups)
	 * @param array $userResponseMap Map of userId => AttendanceResponse
	 * @param array $whitelistedGroups List of whitelisted group IDs
	 * @return array List of user data arrays
	 */
	private function buildUserList(
		$appointment,
		array $relevantUsers,
		array $userResponseMap,
		array $whitelistedGroups,
	): array {
		$users = [];

		foreach ($relevantUsers as $userId => $user) {
			// Filter: Only include users who are target attendees for this appointment
			// This excludes admins who can "see" all appointments but aren't actual attendees
			if (!$this->visibilityService->isUserTargetAttendee($appointment, $userId)) {
				continue;
			}

			$userData = $this->buildUserData($user, $userResponseMap, $whitelistedGroups);
			$users[] = $userData;
		}

		return $users;
	}

	/**
	 * Build data structure for a single user.
	 */
	private function buildUserData($user, array $userResponseMap, array $whitelistedGroups): array {
		$userId = $user->getUID();
		$userGroupIds = $this->groupManager->getUserGroupIds($user);

		// Check if user belongs to any whitelisted group
		$userInWhitelistedGroup = empty($whitelistedGroups);
		if (!empty($whitelistedGroups)) {
			foreach ($userGroupIds as $groupId) {
				if (in_array($groupId, $whitelistedGroups)) {
					$userInWhitelistedGroup = true;
					break;
				}
			}
		}

		// Determine effective groups
		$effectiveGroups = $userInWhitelistedGroup ? $userGroupIds : ['Others'];

		// Base user data
		$userData = [
			'userId' => $userId,
			'displayName' => $user->getDisplayName(),
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

		return $userData;
	}
}
