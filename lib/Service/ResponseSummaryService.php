<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Service for generating response summaries.
 * Handles the complex logic of aggregating responses by group.
 */
class ResponseSummaryService {
	private AppointmentMapper $appointmentMapper;
	private AttendanceResponseMapper $responseMapper;
	private ConfigService $configService;
	private VisibilityService $visibilityService;
	private IGroupManager $groupManager;
	private IUserManager $userManager;

	public function __construct(
		AppointmentMapper $appointmentMapper,
		AttendanceResponseMapper $responseMapper,
		ConfigService $configService,
		VisibilityService $visibilityService,
		IGroupManager $groupManager,
		IUserManager $userManager
	) {
		$this->appointmentMapper = $appointmentMapper;
		$this->responseMapper = $responseMapper;
		$this->configService = $configService;
		$this->visibilityService = $visibilityService;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
	}

	/**
	 * Get response summary for an appointment.
	 *
	 * @param int $appointmentId The appointment ID
	 * @return array The response summary
	 */
	public function getResponseSummary(int $appointmentId): array {
		$appointment = $this->appointmentMapper->find($appointmentId);
		$responses = $this->responseMapper->findByAppointment($appointmentId);

		$summary = $this->initializeSummary();
		$respondedUserIds = [];
		$usersInWhitelistedGroups = [];

		// Process each response
		foreach ($responses as $response) {
			$this->processResponse($appointment, $response, $summary, $respondedUserIds, $usersInWhitelistedGroups);
		}

		// Add non-responding users to groups
		$this->addNonRespondingUsers($appointment, $summary, $respondedUserIds);

		// Calculate total non-responding users
		$this->calculateTotalNonResponding($appointment, $summary, $respondedUserIds);

		// Sort groups
		$summary['by_group'] = $this->sortGroups($summary['by_group']);

		return $summary;
	}

	/**
	 * Initialize the summary structure.
	 */
	private function initializeSummary(): array {
		return [
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
	}

	/**
	 * Process a single response and update the summary.
	 */
	private function processResponse(
		Appointment $appointment,
		$response,
		array &$summary,
		array &$respondedUserIds,
		array &$usersInWhitelistedGroups
	): void {
		// Filter: Only include responses from users who can see this appointment
		if (!$this->visibilityService->canUserSeeAppointment($appointment, $response->getUserId())) {
			return;
		}

		$responseValue = $response->getResponse();

		// Skip invalid or empty responses
		if (!in_array($responseValue, ['yes', 'no', 'maybe'], true)) {
			return;
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
				if ($this->configService->isGroupAllowed($groupId)) {
					$userInWhitelistedGroup = true;
					$usersInWhitelistedGroups[] = $response->getUserId();

					$this->addResponseToGroup($summary, $groupId, $responseValue, $response, $user);
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

	/**
	 * Add a response to a group's summary.
	 */
	private function addResponseToGroup(
		array &$summary,
		string $groupId,
		string $responseValue,
		$response,
		$user
	): void {
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

	/**
	 * Add non-responding users to group summaries.
	 */
	private function addNonRespondingUsers(
		Appointment $appointment,
		array &$summary,
		array $respondedUserIds
	): void {
		$whitelistedGroups = $this->configService->getWhitelistedGroups();
		$allGroups = empty($whitelistedGroups)
			? $this->groupManager->search('')
			: array_filter(
				$this->groupManager->search(''),
				fn($group) => in_array($group->getGID(), $whitelistedGroups)
			);

		foreach ($allGroups as $group) {
			$groupId = $group->getGID();

			// Skip groups not in whitelist
			if (!$this->configService->isGroupAllowed($groupId)) {
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
			$groupUserIds = array_map(fn($user) => $user->getUID(), $groupUsers);

			// Filter to only users who can see this appointment
			$visibleGroupUserIds = array_filter($groupUserIds, function ($userId) use ($appointment) {
				return $this->visibilityService->canUserSeeAppointment($appointment, $userId);
			});

			$nonRespondedInGroup = array_diff($visibleGroupUserIds, $respondedUserIds);
			$summary['by_group'][$groupId]['no_response'] = count($nonRespondedInGroup);

			// Add names and IDs of non-responding users
			$nonRespondingUsers = [];
			foreach ($nonRespondedInGroup as $userId) {
				$user = $this->userManager->get($userId);
				if ($user) {
					$nonRespondingUsers[] = [
						'userId' => $userId,
						'displayName' => $user->getDisplayName()
					];
				}
			}
			$summary['by_group'][$groupId]['non_responding_users'] = $nonRespondingUsers;
		}
	}

	/**
	 * Calculate total non-responding users.
	 */
	private function calculateTotalNonResponding(
		Appointment $appointment,
		array &$summary,
		array $respondedUserIds
	): void {
		$allUsers = $this->userManager->search('');
		$nonRespondingUsers = [];

		foreach ($allUsers as $user) {
			$userId = $user->getUID();

			// Filter: Only include users who can see this appointment
			if (!$this->visibilityService->canUserSeeAppointment($appointment, $userId)) {
				continue;
			}

			$userGroups = $this->groupManager->getUserGroups($user);
			$hasRelevantGroup = false;

			// Check if user belongs to at least one whitelisted group
			foreach ($userGroups as $group) {
				if ($this->configService->isGroupAllowed($group->getGID())) {
					$hasRelevantGroup = true;
					break;
				}
			}

			// Only count users who belong to at least one relevant group
			if ($hasRelevantGroup && !in_array($userId, $respondedUserIds)) {
				$nonRespondingUsers[] = [
					'userId' => $userId,
					'displayName' => $user->getDisplayName()
				];
			}
		}

		$summary['no_response'] = count($nonRespondingUsers);
		$summary['non_responding_users'] = $nonRespondingUsers;
	}

	/**
	 * Sort groups by whitelisted order or alphabetically.
	 */
	private function sortGroups(array $byGroup): array {
		$whitelistedGroups = $this->configService->getWhitelistedGroups();
		$sortedByGroup = [];

		if (!empty($whitelistedGroups)) {
			// First add groups in the order they appear in settings
			foreach ($whitelistedGroups as $groupId) {
				if (isset($byGroup[$groupId])) {
					$sortedByGroup[$groupId] = $byGroup[$groupId];
				}
			}
			// Then add any remaining groups alphabetically
			$remainingGroups = array_diff(array_keys($byGroup), $whitelistedGroups);
			sort($remainingGroups);
			foreach ($remainingGroups as $groupId) {
				$sortedByGroup[$groupId] = $byGroup[$groupId];
			}
		} else {
			// No whitelist configured, sort alphabetically
			$groupIds = array_keys($byGroup);
			sort($groupIds);
			foreach ($groupIds as $groupId) {
				$sortedByGroup[$groupId] = $byGroup[$groupId];
			}
		}

		return $sortedByGroup;
	}
}
