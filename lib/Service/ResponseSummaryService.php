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
 * Handles the complex logic of aggregating responses by group and team.
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
		IUserManager $userManager,
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

		// Add non-responding users to groups and teams
		$this->addNonRespondingUsers($appointment, $summary, $respondedUserIds, $cache);
		$this->addNonRespondingTeamUsers($appointment, $summary, $respondedUserIds, $cache);

		// Calculate total non-responding users
		$this->calculateTotalNonResponding($appointment, $summary, $respondedUserIds, $cache);

		// Filter out empty groups and teams (can occur with visibility restrictions)
		$summary['by_group'] = $this->filterEmptyGroups($summary['by_group']);
		$summary['by_team'] = $this->filterEmptyGroups($summary['by_team']);

		// Sort groups and teams
		$summary['by_group'] = $this->sortGroups($summary['by_group'], $cache['whitelistedGroups']);
		$summary['by_team'] = $this->sortTeams($summary['by_team'], $cache['whitelistedTeams']);

		return $summary;
	}

	/**
	 * Build cache of users, groups, teams, and settings to avoid N+1 queries.
	 *
	 * Optimized to only load relevant users based on appointment visibility
	 * and whitelisted groups/teams, avoiding loading ALL users in large instances.
	 */
	private function buildCache(Appointment $appointment, array $responses): array {
		// Cache whitelisted groups (called once instead of per-group)
		$whitelistedGroups = $this->configService->getWhitelistedGroups();
		$whitelistedGroupsLower = array_map('strtolower', $whitelistedGroups);
		$allowAllGroups = empty($whitelistedGroups);

		// Cache whitelisted teams
		$whitelistedTeams = $this->configService->getWhitelistedTeams();

		// Pre-fetch all users from responses
		$userIds = array_unique(array_map(fn ($r) => $r->getUserId(), $responses));
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

		// Pre-fetch whitelisted team members and info
		$teamMembers = [];
		$teamInfo = [];
		foreach ($whitelistedTeams as $teamId) {
			$teamMembers[$teamId] = $this->visibilityService->getTeamMembers($teamId);
			$info = $this->visibilityService->getTeamInfo($teamId);
			if ($info) {
				$teamInfo[$teamId] = $info;
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
			'whitelistedTeams' => $whitelistedTeams,
			'teamMembers' => $teamMembers,
			'teamInfo' => $teamInfo,
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
			'by_team' => [],
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
		array $cache,
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
		$userInWhitelistedTeam = false;

		if ($user) {
			// Check groups
			$userGroups = $cache['userGroups'][$userId] ?? [];
			foreach ($userGroups as $group) {
				$groupId = $group->getGID();

				// Check if group is allowed (using cache)
				if ($this->isGroupAllowedCached($groupId, $cache)) {
					$userInWhitelistedGroup = true;
					$this->addResponseToGroup($summary, $groupId, $responseValue, $response, $user);
				}
			}

			// Check teams (user can be in both groups AND teams - duplicates allowed)
			foreach ($cache['whitelistedTeams'] as $teamId) {
				$teamMemberIds = $cache['teamMembers'][$teamId] ?? [];
				if (in_array($userId, $teamMemberIds)) {
					$userInWhitelistedTeam = true;
					$this->addResponseToTeam($summary, $teamId, $responseValue, $response, $user, $cache);
				}
			}

			// If user is not in any whitelisted group or team, add to "others"
			if (!$userInWhitelistedGroup && !$userInWhitelistedTeam) {
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
		$user,
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
	 * Add a response to a team's summary.
	 */
	private function addResponseToTeam(
		array &$summary,
		string $teamId,
		string $responseValue,
		$response,
		$user,
		array $cache,
	): void {
		if (!isset($summary['by_team'][$teamId])) {
			$teamInfo = $cache['teamInfo'][$teamId] ?? null;
			$summary['by_team'][$teamId] = [
				'displayName' => $teamInfo ? $teamInfo['label'] : $teamId,
				'yes' => 0,
				'no' => 0,
				'maybe' => 0,
				'no_response' => 0,
				'responses' => []
			];
		}

		$summary['by_team'][$teamId][$responseValue]++;

		// Add the detailed response to this team
		$responseData = $response->jsonSerialize();
		$responseData['userName'] = $user->getDisplayName();
		$summary['by_team'][$teamId]['responses'][] = $responseData;
	}

	/**
	 * Add non-responding users to group summaries.
	 */
	private function addNonRespondingUsers(
		Appointment $appointment,
		array &$summary,
		array $respondedUserIds,
		array $cache,
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
	 * Add non-responding users to team summaries.
	 */
	private function addNonRespondingTeamUsers(
		Appointment $appointment,
		array &$summary,
		array $respondedUserIds,
		array $cache,
	): void {
		foreach ($cache['whitelistedTeams'] as $teamId) {
			$teamInfo = $cache['teamInfo'][$teamId] ?? null;

			if (!isset($summary['by_team'][$teamId])) {
				$summary['by_team'][$teamId] = [
					'displayName' => $teamInfo ? $teamInfo['label'] : $teamId,
					'yes' => 0,
					'no' => 0,
					'maybe' => 0,
					'no_response' => 0,
					'responses' => [],
					'non_responding_users' => []
				];
			}

			// Get team members from cache
			$teamMemberIds = $cache['teamMembers'][$teamId] ?? [];
			$nonRespondingUsers = [];

			foreach ($teamMemberIds as $userId) {
				// Skip if already responded (O(1) lookup)
				if (isset($respondedUserIds[$userId])) {
					continue;
				}

				// Filter to only actual target attendees
				if (!$this->visibilityService->isUserTargetAttendee($appointment, $userId)) {
					continue;
				}

				// Get user for display name
				$user = $this->userManager->get($userId);
				if ($user) {
					$nonRespondingUsers[] = [
						'userId' => $userId,
						'displayName' => $user->getDisplayName()
					];
				}
			}

			$summary['by_team'][$teamId]['no_response'] = count($nonRespondingUsers);
			$summary['by_team'][$teamId]['non_responding_users'] = $nonRespondingUsers;
		}
	}

	/**
	 * Calculate total non-responding users.
	 */
	private function calculateTotalNonResponding(
		Appointment $appointment,
		array &$summary,
		array $respondedUserIds,
		array $cache,
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

			// Check if user belongs to at least one whitelisted team
			$hasRelevantTeam = false;
			if (!$hasRelevantGroup) {
				foreach ($cache['whitelistedTeams'] as $teamId) {
					$teamMemberIds = $cache['teamMembers'][$teamId] ?? [];
					if (in_array($userId, $teamMemberIds)) {
						$hasRelevantTeam = true;
						break;
					}
				}
			}

			// Only count users who belong to at least one relevant group or team
			if ($hasRelevantGroup || $hasRelevantTeam) {
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
	 * Filter out empty groups from the summary.
	 *
	 * When visibility settings restrict which users can see an appointment,
	 * some whitelisted groups may end up with no target attendees.
	 * This method removes those empty groups to clean up the response.
	 */
	private function filterEmptyGroups(array $byGroup): array {
		return array_filter($byGroup, function (array $group): bool {
			// A group is considered non-empty if it has any responses or non-responding users
			$hasResponses = !empty($group['responses']);
			$hasNonResponding = !empty($group['non_responding_users']);
			return $hasResponses || $hasNonResponding;
		});
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

	/**
	 * Sort teams by whitelisted order or alphabetically by display name.
	 */
	private function sortTeams(array $byTeam, array $whitelistedTeams): array {
		$sortedByTeam = [];

		if (!empty($whitelistedTeams)) {
			// First add teams in the order they appear in settings
			foreach ($whitelistedTeams as $teamId) {
				if (isset($byTeam[$teamId])) {
					$sortedByTeam[$teamId] = $byTeam[$teamId];
				}
			}
			// Then add any remaining teams alphabetically by display name
			$remainingTeams = array_diff(array_keys($byTeam), $whitelistedTeams);
			usort($remainingTeams, function ($a, $b) use ($byTeam) {
				$nameA = $byTeam[$a]['displayName'] ?? $a;
				$nameB = $byTeam[$b]['displayName'] ?? $b;
				return strcasecmp($nameA, $nameB);
			});
			foreach ($remainingTeams as $teamId) {
				$sortedByTeam[$teamId] = $byTeam[$teamId];
			}
		} else {
			// No whitelist configured, sort alphabetically by display name
			$teamIds = array_keys($byTeam);
			usort($teamIds, function ($a, $b) use ($byTeam) {
				$nameA = $byTeam[$a]['displayName'] ?? $a;
				$nameB = $byTeam[$b]['displayName'] ?? $b;
				return strcasecmp($nameA, $nameB);
			});
			foreach ($teamIds as $teamId) {
				$sortedByTeam[$teamId] = $byTeam[$teamId];
			}
		}

		return $sortedByTeam;
	}
}
