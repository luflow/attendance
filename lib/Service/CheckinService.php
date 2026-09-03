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
	private GuestService $guestService;
	private AuditEventService $auditEventService;
	private CapacityService $capacityService;
	private AppointmentSerializer $appointmentSerializer;

	public function __construct(
		AppointmentMapper $appointmentMapper,
		AttendanceResponseMapper $responseMapper,
		ConfigService $configService,
		VisibilityService $visibilityService,
		IGroupManager $groupManager,
		GuestService $guestService,
		AuditEventService $auditEventService,
		CapacityService $capacityService,
		AppointmentSerializer $appointmentSerializer,
	) {
		$this->appointmentMapper = $appointmentMapper;
		$this->responseMapper = $responseMapper;
		$this->configService = $configService;
		$this->visibilityService = $visibilityService;
		$this->groupManager = $groupManager;
		$this->guestService = $guestService;
		$this->auditEventService = $auditEventService;
		$this->capacityService = $capacityService;
		$this->appointmentSerializer = $appointmentSerializer;
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
		if ($response !== null && !in_array($response, ['yes', 'no'])) {
			throw new \InvalidArgumentException('Invalid response. Must be yes or no.');
		}

		$beforeCheckin = null;

		// Find existing response or create new one
		try {
			$attendanceResponse = $this->responseMapper->findByAppointmentAndUser($appointmentId, $targetUserId);
			$beforeCheckin = $attendanceResponse->getCheckinState();
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
		$attendanceResponse->setCheckinAt(gmdate('Y-m-d H:i:s'));
		$attendanceResponse->setCheckinSource('manual');

		$saved = $attendanceResponse->getId()
			? $this->responseMapper->update($attendanceResponse)
			: $this->responseMapper->insert($attendanceResponse);

		$this->auditEventService->recordCheckin(
			$appointmentId,
			$adminUserId,
			$targetUserId,
			$beforeCheckin,
			$saved->getCheckinState(),
			(string)$saved->getCheckinComment(),
		);

		return $saved;
	}

	/**
	 * Reset all check-in data for an appointment.
	 *
	 * @param int $appointmentId The appointment ID
	 */
	public function resetCheckin(int $appointmentId): void {
		$this->responseMapper->resetCheckinByAppointment($appointmentId);
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

		// Get the target attendees efficiently - filters by whitelisted groups when configured
		$targetAttendees = $this->visibilityService->getTargetAttendees($appointment, $whitelistedGroups);

		// Create a map of user responses
		$userResponseMap = [];
		foreach ($responses as $response) {
			$userResponseMap[$response->getUserId()] = $response;
		}

		// Build group list for filtering UI
		$userGroups = $this->buildGroupList($whitelistedGroups);

		// A place in line is not a place at the appointment. The list still
		// carries everyone — an organizer has to be able to check in whoever
		// actually turns up — but a waiting yes must not read as a confirmed one.
		$confirmedIds = [];
		if ($this->capacityService->limitOf($appointment) !== null) {
			foreach ($this->capacityService->split($appointment)['confirmed'] as $row) {
				$confirmedIds[$row->getUserId()] = true;
			}
		}

		$users = array_map(
			fn ($user) => $this->buildUserData($user, $userResponseMap, $whitelistedGroups, $confirmedIds),
			array_values($targetAttendees),
		);

		return [
			'appointment' => $this->appointmentSerializer->serialize($appointment),
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
			// Hide the Guests app system group; guests fall under "Others"
			// unless an admin opted them in explicitly via the whitelist.
			$groups = array_values(array_filter(
				$groups,
				fn (string $g) => !GuestService::isGuestsSystemGroup($g),
			));
			$groups[] = 'Others';
		} else {
			$groups = $whitelistedGroups;
		}

		return $groups;
	}

	/**
	 * Get checkin summary counts for an appointment.
	 *
	 * @param int $appointmentId The appointment ID
	 * @return array Checkin summary with attended, absent, notCheckedIn counts and hasCheckins flag
	 */
	public function getCheckinSummary(int $appointmentId): array {
		$appointment = $this->appointmentMapper->find($appointmentId);
		$responses = $this->responseMapper->findByAppointment($appointmentId);

		// Get whitelisted groups for filtering
		$whitelistedGroups = $this->configService->getWhitelistedGroups();

		// Get the target attendees - filters by whitelisted groups when configured
		$targetAttendees = $this->visibilityService->getTargetAttendees($appointment, $whitelistedGroups);

		// Create a map of user responses
		$userResponseMap = [];
		foreach ($responses as $response) {
			$userResponseMap[$response->getUserId()] = $response;
		}

		// Count checkin states
		$attended = 0;
		$absent = 0;
		$notCheckedIn = 0;

		foreach (array_keys($targetAttendees) as $userId) {
			if (isset($userResponseMap[$userId])) {
				$checkinState = $userResponseMap[$userId]->getCheckinState();
				if ($checkinState === 'yes') {
					$attended++;
				} elseif ($checkinState === 'no') {
					$absent++;
				} else {
					$notCheckedIn++;
				}
			} else {
				$notCheckedIn++;
			}
		}

		$hasCheckins = ($attended + $absent) > 0;

		return [
			'attended' => $attended,
			'absent' => $absent,
			'notCheckedIn' => $notCheckedIn,
			'hasCheckins' => $hasCheckins,
		];
	}

	/**
	 * Build data structure for a single user.
	 */
	private function buildUserData($user, array $userResponseMap, array $whitelistedGroups, array $confirmedIds = []): array {
		$userId = $user->getUID();
		$userGroupIds = $this->groupManager->getUserGroupIds($user);

		// Hide the Guests app system group from the per-user group list
		// unless the admin explicitly whitelisted it.
		$guestAppWhitelisted = in_array(
			GuestService::GUESTS_SYSTEM_GROUP,
			array_map('strtolower', $whitelistedGroups),
			true,
		);
		if (!$guestAppWhitelisted) {
			$userGroupIds = array_values(array_filter(
				$userGroupIds,
				fn (string $g) => !GuestService::isGuestsSystemGroup($g),
			));
		}

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
		$effectiveGroups = $userInWhitelistedGroup && !empty($userGroupIds) ? $userGroupIds : ['Others'];

		// Base user data
		$userData = [
			'userId' => $userId,
			'displayName' => $user->getDisplayName(),
			'groups' => $effectiveGroups,
			'isGuest' => $this->guestService->isGuestUser($userId),
			'response' => null,
			'comment' => null,
			'isCheckedIn' => false,
			'checkinState' => null,
			'checkinComment' => null,
			'checkinBy' => null,
			'checkinAt' => null,
			'checkinSource' => null,
			'waitlisted' => false,
		];

		// Add response data if user has responded
		if (isset($userResponseMap[$userId])) {
			/** @var AttendanceResponse $response */
			$response = $userResponseMap[$userId];
			$responseData = $response->jsonSerialize();
			$userData['response'] = $responseData['response'];
			$userData['comment'] = $responseData['comment'];
			$userData['isCheckedIn'] = $responseData['isCheckedIn'];
			$userData['checkinState'] = $responseData['checkinState'];
			$userData['checkinComment'] = $responseData['checkinComment'];
			$userData['checkinBy'] = $responseData['checkinBy'];
			$userData['checkinAt'] = $responseData['checkinAt'];
			$userData['checkinSource'] = $responseData['checkinSource'];
			$userData['waitlisted'] = $responseData['response'] === 'yes'
				&& $confirmedIds !== []
				&& !isset($confirmedIds[$userId]);
		}

		return $userData;
	}
}
