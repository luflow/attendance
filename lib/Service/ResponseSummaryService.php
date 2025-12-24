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
 * Optimized to avoid N+1 query patterns through caching and batch operations.
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

		// Pre-fetch and cache data to avoid N+1 queries
		$cache = $this->buildCache($appointment, $responses);

		$summary = $this->initializeSummary();
		$respondedUserIds = [];

		// Process each response
		foreach ($responses as $response) {
			$this->processResponse($appointment, $response, $summary, $respondedUserIds, $cache);
		}

		// Add non-responding users to groups
		$this->addNonRespondingUsers($appointment, $summary, $respondedUserIds, $cache);

		// Calculate total non-responding users
		$this->calculateTotalNonResponding($appointment, $summary, $respondedUserIds, $cache);

		// Sort groups
		$summary['by_group'] = $this->sortGroups($summary['by_group'], $cache['whitelistedGroups']);

		return $summary;
	}

	/**
	 * Build cache of users, groups, and settings to avoid N+1 queries.
	 *
	 * Optimized to only load relevant users based on appointment visibility
	 * and whitelisted groups, avoiding loading ALL users in large instances.
	 */
	private function buildCache(Appointment $appointment, array $responses): array {
		// Cache whitelisted groups (called once instead of per-group)
		$whitelistedGroups = $this->configService->getWhitelistedGroups();
		$whitelistedGroupsLower = array_map('strtolower', $whitelistedGroups);
		$allowAllGroups = empty($whitelistedGroups);

		// Pre-fetch all users from responses
		$userIds = array_unique(array_map(fn($r) => $r->getUserId(), $responses));
		$users = [];
		$userGroups = [];

		foreach ($userIds as $userId) {
			$user = $this->userManager->get($userId);
			if ($user) {
				$users[$userId] = $user;
				$userGroups[$userId] = $this->groupManager->getUserGroups($user);
			}
		}

		// Pre-fetch whitelisted group objects and their users
		$groupUsers = [];
		if ($allowAllGroups) {
			$allGroups = $this->groupManager->search('');
			foreach ($allGroups as $group) {
				$groupUsers[$group->getGID()] = $group->getUsers();
			}
		} else {
			foreach ($whitelistedGroups as $groupId) {
				$group = $this->groupManager->get($groupId);
				if ($group) {
					$groupUsers[$groupId] = $group->getUsers();
				}
			}
		}

		// OPTIMIZATION: Only load relevant users based on appointment visibility
		// instead of loading ALL users in the system
		$relevantUsers = $this->visibilityService->getRelevantUsersForAppointment(
			$appointment,
			$whitelistedGroups
		);

		$allUsersMap = [];
		$allUserGroups = [];
		foreach ($relevantUsers as $uid => $user) {
			$allUsersMap[$uid] = $user;
			if (!isset($userGroups[$uid])) {
				$allUserGroups[$uid] = $this->groupManager->getUserGroups($user);
			} else {
				$allUserGroups[$uid] = $userGroups[$uid];
			}
		}

		return [
			'whitelistedGroups' => $whitelistedGroups,
			'whitelistedGroupsLower' => $whitelistedGroupsLower,
			'allowAllGroups' => $allowAllGroups,
			'users' => $users,
			'userGroups' => $userGroups,
			'groupUsers' => $groupUsers,
			'allUsers' => $allUsersMap,
			'allUserGroups' => $allUserGroups,
		];
	}

	/**
	 * Check if a group is allowed (using cache).
	 */
	private function isGroupAllowedCached(string $groupId, array $cache): bool {
		if ($cache['allowAllGroups']) {
			return true;
		}
		return in_array(strtolower($groupId), $cache['whitelistedGroupsLower']);
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
		array $cache
	): void {
		$userId = $response->getUserId();

		// Filter: Only include responses from actual target attendees
		// This excludes admins who can "see" all appointments but aren't actual attendees
		if (!$this->visibilityService->isUserTargetAttendee($appointment, $userId)) {
			return;
		}

		$responseValue = $response->getResponse();

		// Skip invalid or empty responses
		if (!in_array($responseValue, ['yes', 'no', 'maybe'], true)) {
			return;
		}

		$summary[$responseValue]++;
		$respondedUserIds[$userId] = true;

		// Get user from cache
		$user = $cache['users'][$userId] ?? null;
		$userInWhitelistedGroup = false;

		if ($user) {
			$userGroups = $cache['userGroups'][$userId] ?? [];
			foreach ($userGroups as $group) {
				$groupId = $group->getGID();

				// Check if group is allowed (using cache)
				if ($this->isGroupAllowedCached($groupId, $cache)) {
					$userInWhitelistedGroup = true;
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
		array $respondedUserIds,
		array $cache
	): void {
		$groupsToProcess = $cache['allowAllGroups']
			? array_keys($cache['groupUsers'])
			: $cache['whitelistedGroups'];

		foreach ($groupsToProcess as $groupId) {
			// Skip groups not in whitelist
			if (!$this->isGroupAllowedCached($groupId, $cache)) {
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

			// Get users from cache
			$groupUsers = $cache['groupUsers'][$groupId] ?? [];
			$nonRespondingUsers = [];

			foreach ($groupUsers as $user) {
				$userId = $user->getUID();

				// Skip if already responded (O(1) lookup)
				if (isset($respondedUserIds[$userId])) {
					continue;
				}

				// Filter to only actual target attendees
				if (!$this->visibilityService->isUserTargetAttendee($appointment, $userId)) {
					continue;
				}

				$nonRespondingUsers[] = [
					'userId' => $userId,
					'displayName' => $user->getDisplayName()
				];
			}

			$summary['by_group'][$groupId]['no_response'] = count($nonRespondingUsers);
			$summary['by_group'][$groupId]['non_responding_users'] = $nonRespondingUsers;
		}
	}

	/**
	 * Calculate total non-responding users.
	 */
	private function calculateTotalNonResponding(
		Appointment $appointment,
		array &$summary,
		array $respondedUserIds,
		array $cache
	): void {
		$nonRespondingUsers = [];

		foreach ($cache['allUsers'] as $userId => $user) {
			// Skip if already responded (O(1) lookup)
			if (isset($respondedUserIds[$userId])) {
				continue;
			}

			// Filter: Only include actual target attendees
			if (!$this->visibilityService->isUserTargetAttendee($appointment, $userId)) {
				continue;
			}

			// Check if user belongs to at least one whitelisted group
			$userGroups = $cache['allUserGroups'][$userId] ?? [];
			$hasRelevantGroup = false;

			foreach ($userGroups as $group) {
				if ($this->isGroupAllowedCached($group->getGID(), $cache)) {
					$hasRelevantGroup = true;
					break;
				}
			}

			// Only count users who belong to at least one relevant group
			if ($hasRelevantGroup) {
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
	private function sortGroups(array $byGroup, array $whitelistedGroups): array {
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
