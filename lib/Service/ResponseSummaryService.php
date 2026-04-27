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
	private GuestService $guestService;

	public function __construct(
		AppointmentMapper $appointmentMapper,
		AttendanceResponseMapper $responseMapper,
		ConfigService $configService,
		VisibilityService $visibilityService,
		IGroupManager $groupManager,
		IUserManager $userManager,
		GuestService $guestService,
	) {
		$this->appointmentMapper = $appointmentMapper;
		$this->responseMapper = $responseMapper;
		$this->configService = $configService;
		$this->visibilityService = $visibilityService;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->guestService = $guestService;
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

		// Single pass: populate the global non-responder list AND the Others
		// bucket (target attendees who don't surface in any visible section,
		// e.g. guests whose `guest_app` group is hidden).
		$this->collectMissingResponders($appointment, $summary, $respondedUserIds, $cache);

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

		// Cache appointment visibility restrictions
		$visibilitySettings = $this->visibilityService->getVisibilitySettings($appointment);
		$appointmentHasRestrictions = $this->visibilityService->hasRestrictedVisibility($appointment);
		$appointmentVisibleGroups = $visibilitySettings['groups'];
		$appointmentVisibleGroupsLower = array_map('strtolower', $appointmentVisibleGroups);
		$appointmentVisibleTeams = $visibilitySettings['teams'];

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
			// Appointment-specific visibility restrictions
			'appointmentHasRestrictions' => $appointmentHasRestrictions,
			'appointmentVisibleGroups' => $appointmentVisibleGroups,
			'appointmentVisibleGroupsLower' => $appointmentVisibleGroupsLower,
			'appointmentVisibleTeams' => $appointmentVisibleTeams,
		];
	}

	/**
	 * Check if a group is allowed (using cache).
	 * Checks both admin whitelist and appointment-specific restrictions.
	 */
	private function isGroupAllowedCached(string $groupId, array $cache): bool {
		// First check: group must be in whitelist (or all groups allowed)
		$inWhitelist = $cache['allowAllGroups'] || in_array(strtolower($groupId), $cache['whitelistedGroupsLower']);
		if (!$inWhitelist) {
			return false;
		}

		// Second check: if appointment has restrictions, group must be in visible groups
		if ($cache['appointmentHasRestrictions'] && !empty($cache['appointmentVisibleGroups'])) {
			return in_array(strtolower($groupId), $cache['appointmentVisibleGroupsLower']);
		}

		return true;
	}

	/**
	 * Check if a group should appear as its own section in the summary.
	 *
	 * Same as isGroupAllowedCached but hides the Guests app's system group
	 * unless an admin opts in via the whitelist — otherwise every guest user
	 * would be lumped under one section regardless of context.
	 */
	private function isGroupVisibleAsSection(string $groupId, array $cache): bool {
		if (GuestService::isGuestsSystemGroup($groupId)
			&& !in_array(GuestService::GUESTS_SYSTEM_GROUP, $cache['whitelistedGroupsLower'], true)) {
			return false;
		}
		return $this->isGroupAllowedCached($groupId, $cache);
	}

	/**
	 * Check if a team is allowed for the appointment (using cache).
	 * Checks both admin whitelist and appointment-specific restrictions.
	 */
	private function isTeamAllowedCached(string $teamId, array $cache): bool {
		// First check: team must be in whitelist
		if (!in_array($teamId, $cache['whitelistedTeams'])) {
			return false;
		}

		// Second check: if appointment has restrictions, team must be in visible teams
		if ($cache['appointmentHasRestrictions'] && !empty($cache['appointmentVisibleTeams'])) {
			return in_array($teamId, $cache['appointmentVisibleTeams']);
		}

		return true;
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
				'no_response' => 0,
				'responses' => [],
				'non_responding_users' => [],
				'maybe_users' => [],
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
		$respondedUserIds[$userId] = $responseValue;

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
				if ($this->isGroupVisibleAsSection($groupId, $cache)) {
					$userInWhitelistedGroup = true;
					$this->addResponseToGroup($summary, $groupId, $responseValue, $response, $user);
				}
			}

			// Check teams (user can be in both groups AND teams - duplicates allowed)
			foreach ($cache['whitelistedTeams'] as $teamId) {
				// Skip teams not allowed for this appointment
				if (!$this->isTeamAllowedCached($teamId, $cache)) {
					continue;
				}

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
				$responseData['isGuest'] = $this->guestService->isGuestUser($userId);
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
		$responseData['isGuest'] = $this->guestService->isGuestUser($user->getUID());
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
		$responseData['isGuest'] = $this->guestService->isGuestUser($user->getUID());
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
			// Numeric-string group IDs get coerced to int when used as array keys (issue #63)
			$groupId = (string)$groupId;

			// Skip groups not in whitelist or system groups (e.g. guest_app)
			if (!$this->isGroupVisibleAsSection($groupId, $cache)) {
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
			$maybeUsers = [];

			foreach ($groupUsers as $user) {
				$userId = $user->getUID();

				// Filter to only actual target attendees
				if (!$this->visibilityService->isUserTargetAttendee($appointment, $userId)) {
					continue;
				}

				$userResponse = $respondedUserIds[$userId] ?? null;
				if ($userResponse === null) {
					$nonRespondingUsers[] = [
						'userId' => $userId,
						'displayName' => $user->getDisplayName(),
						'isGuest' => $this->guestService->isGuestUser($userId),
					];
				} elseif ($userResponse === 'maybe') {
					$maybeUsers[] = [
						'userId' => $userId,
						'displayName' => $user->getDisplayName(),
						'isGuest' => $this->guestService->isGuestUser($userId),
					];
				}
			}

			$summary['by_group'][$groupId]['no_response'] = count($nonRespondingUsers);
			$summary['by_group'][$groupId]['non_responding_users'] = $nonRespondingUsers;
			$summary['by_group'][$groupId]['maybe_users'] = $maybeUsers;
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
			// Skip teams not allowed for this appointment
			if (!$this->isTeamAllowedCached($teamId, $cache)) {
				continue;
			}

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
			$maybeUsers = [];

			foreach ($teamMemberIds as $userId) {
				// Filter to only actual target attendees
				if (!$this->visibilityService->isUserTargetAttendee($appointment, $userId)) {
					continue;
				}

				$userResponse = $respondedUserIds[$userId] ?? null;
				if ($userResponse === null || $userResponse === 'maybe') {
					$user = $this->userManager->get($userId);
					if ($user) {
						$userData = [
							'userId' => $userId,
							'displayName' => $user->getDisplayName(),
							'isGuest' => $this->guestService->isGuestUser($userId),
						];
						if ($userResponse === null) {
							$nonRespondingUsers[] = $userData;
						} else {
							$maybeUsers[] = $userData;
						}
					}
				}
			}

			$summary['by_team'][$teamId]['no_response'] = count($nonRespondingUsers);
			$summary['by_team'][$teamId]['non_responding_users'] = $nonRespondingUsers;
			$summary['by_team'][$teamId]['maybe_users'] = $maybeUsers;
		}
	}

	/**
	 * Walk all target attendees once and populate both the global
	 * non-responder lists and the Others bucket. A user is in the Others
	 * bucket when they have no visible section (no allowed group/team that
	 * renders) — typically a guest with only `guest_app` membership.
	 */
	private function collectMissingResponders(
		Appointment $appointment,
		array &$summary,
		array $respondedUserIds,
		array $cache,
	): void {
		$totalNonResponding = [];
		$totalMaybe = [];
		$othersNonResponding = [];
		$othersMaybe = [];

		foreach ($cache['allUsers'] as $user) {
			$userId = $user->getUID();

			if (!$this->visibilityService->isUserTargetAttendee($appointment, $userId)) {
				continue;
			}

			$userResponse = $respondedUserIds[$userId] ?? null;
			if ($userResponse !== null && $userResponse !== 'maybe') {
				continue;
			}

			$userGroups = $cache['allUserGroups'][$userId] ?? [];
			$hasAllowedGroup = false;
			$hasVisibleGroup = false;
			foreach ($userGroups as $group) {
				$gid = $group->getGID();
				if ($this->isGroupAllowedCached($gid, $cache)) {
					$hasAllowedGroup = true;
					if ($this->isGroupVisibleAsSection($gid, $cache)) {
						$hasVisibleGroup = true;
						break;
					}
				}
			}

			$hasRelevantTeam = false;
			if (!$hasVisibleGroup) {
				foreach ($cache['whitelistedTeams'] as $teamId) {
					if (!$this->isTeamAllowedCached($teamId, $cache)) {
						continue;
					}
					$teamMemberIds = $cache['teamMembers'][$teamId] ?? [];
					if (in_array($userId, $teamMemberIds)) {
						$hasRelevantTeam = true;
						break;
					}
				}
			}

			if (!$hasAllowedGroup && !$hasRelevantTeam && !$hasVisibleGroup) {
				continue;
			}

			$userData = [
				'userId' => $userId,
				'displayName' => $user->getDisplayName(),
				'isGuest' => $this->guestService->isGuestUser($userId),
			];

			if ($userResponse === null) {
				$totalNonResponding[] = $userData;
			} else {
				$totalMaybe[] = $userData;
			}

			if (!$hasVisibleGroup && !$hasRelevantTeam) {
				if ($userResponse === null) {
					$othersNonResponding[] = $userData;
				} else {
					$othersMaybe[] = $userData;
				}
			}
		}

		$summary['no_response'] = count($totalNonResponding);
		$summary['non_responding_users'] = $totalNonResponding;
		$summary['maybe_users'] = $totalMaybe;
		$summary['others']['non_responding_users'] = $othersNonResponding;
		$summary['others']['maybe_users'] = $othersMaybe;
		$summary['others']['no_response'] = count($othersNonResponding);
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
			// A group is considered non-empty if it has any responses, non-responding, or maybe users
			$hasResponses = !empty($group['responses']);
			$hasNonResponding = !empty($group['non_responding_users']);
			$hasMaybeUsers = !empty($group['maybe_users']);
			return $hasResponses || $hasNonResponding || $hasMaybeUsers;
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
