<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCP\IGroupManager;
use OCP\IUser;
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
	private CapacityService $capacityService;

	public function __construct(
		AppointmentMapper $appointmentMapper,
		AttendanceResponseMapper $responseMapper,
		ConfigService $configService,
		VisibilityService $visibilityService,
		IGroupManager $groupManager,
		IUserManager $userManager,
		GuestService $guestService,
		CapacityService $capacityService,
	) {
		$this->appointmentMapper = $appointmentMapper;
		$this->responseMapper = $responseMapper;
		$this->configService = $configService;
		$this->visibilityService = $visibilityService;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->guestService = $guestService;
		$this->capacityService = $capacityService;
	}

	/**
	 * Get response summary for an appointment.
	 *
	 * @param int $appointmentId The appointment ID
	 * @param bool $includeComments Whether to include the free-text comment /
	 *                              checkinComment fields in the serialized
	 *                              responses. Gated by the caller's
	 *                              PERMISSION_SEE_COMMENTS — never expose these
	 *                              to users who may not read comments.
	 * @return array The response summary
	 */
	public function getResponseSummary(int $appointmentId, bool $includeComments = false): array {
		$appointment = $this->appointmentMapper->find($appointmentId);
		$responses = $this->responseMapper->findByAppointment($appointmentId);

		// Pre-fetch and cache data to avoid N+1 queries
		$cache = $this->buildCache($appointment, $responses);

		$summary = $this->initializeSummary();
		$respondedUserIds = [];

		foreach ($responses as $response) {
			$this->processResponse($appointment, $response, $summary, $respondedUserIds, $cache, $includeComments);
		}

		// Add non-responding users to groups and teams
		$this->addNonRespondingUsers($summary, $respondedUserIds, $cache);
		$this->addNonRespondingTeamUsers($appointment, $summary, $respondedUserIds, $cache);

		// Single pass: populate the global non-responder list AND the Others
		// bucket (target attendees who don't surface in any visible section,
		// e.g. guests whose `guest_app` group is hidden).
		$this->collectMissingResponders($summary, $respondedUserIds, $cache);

		// Filter out empty groups and teams (can occur with visibility restrictions)
		$summary['by_group'] = $this->filterEmptyGroups($summary['by_group']);
		$summary['by_team'] = $this->filterEmptyGroups($summary['by_team']);

		$summary['by_group'] = $this->sortGroups($summary['by_group'], $cache['whitelistedGroups']);
		$summary['by_team'] = $this->sortTeams($summary['by_team'], $cache['whitelistedTeams']);

		return $summary;
	}

	/**
	 * Aggregate yes/no/maybe counts only, in the summary's shape but with the
	 * per-person sections empty. Safe for holders of the counts permission —
	 * it must never carry names, timestamps or comments.
	 *
	 * Deliberately not built by stripping the full summary: this runs per
	 * appointment on the list endpoint for a permission that is open by
	 * default, so it tallies straight over the responses and the relevant
	 * users — the same shape (and non-responder semantics) as the check-in
	 * summary.
	 */
	public function getResponseCounts(int $appointmentId): array {
		$appointment = $this->appointmentMapper->find($appointmentId);
		$responses = $this->responseMapper->findByAppointment($appointmentId);

		/** @var array{yes: int, no: int, maybe: int, no_response: int} $counts */
		$counts = $this->initializeSummary();
		/** @var array<string, true> $respondedUserIds */
		$respondedUserIds = [];
		foreach ($responses as $response) {
			$value = $response->getResponse();
			if (!in_array($value, ['yes', 'no', 'maybe'], true)
				|| !$this->visibilityService->isUserTargetAttendee($appointment, $response->getUserId())) {
				continue;
			}
			$counts[$value]++;
			$respondedUserIds[$response->getUserId()] = true;
		}

		$whitelistedGroups = $this->configService->getWhitelistedGroups();
		$targetAttendees = $this->visibilityService->getTargetAttendees($appointment, $whitelistedGroups);
		foreach ($targetAttendees as $user) {
			if (!isset($respondedUserIds[$user->getUID()])) {
				$counts['no_response']++;
			}
		}

		return $counts;
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

		$visibilitySettings = $this->visibilityService->getVisibilitySettings($appointment);
		$appointmentHasRestrictions = $this->visibilityService->hasRestrictedVisibility($appointment);
		$appointmentVisibleGroupsLower = array_map('strtolower', $visibilitySettings['groups']);

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

		// OPTIMIZATION: Only load the appointment's target attendees based on
		// visibility instead of loading ALL users in the system
		$targetAttendees = $this->visibilityService->getTargetAttendees(
			$appointment,
			$whitelistedGroups
		);

		$allUserGroups = [];
		foreach ($targetAttendees as $uid => $user) {
			$allUserGroups[$uid] = $userGroups[$uid] ?? $this->groupManager->getUserGroups($user);
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
			'allUsers' => $targetAttendees,
			'allUserGroups' => $allUserGroups,
			// Appointment-specific visibility restrictions
			'appointmentHasRestrictions' => $appointmentHasRestrictions,
			'appointmentVisibleGroupsLower' => $appointmentVisibleGroupsLower,
			// Who holds a spot rather than a place in line. Empty for an
			// appointment without a limit, which is also how serializeResponse()
			// knows there is no queue to report on.
			'confirmedIds' => $this->confirmedIds($appointment),
		];
	}

	/**
	 * Check if a group may appear as a section in the summary (using cache).
	 *
	 * A configured whitelist alone decides the sections — visibility
	 * restrictions only narrow the audience (issue #199). Without a whitelist,
	 * a group-restricted appointment groups by its restriction groups.
	 */
	private function isGroupAllowedCached(string $groupId, array $cache): bool {
		if (!$cache['allowAllGroups']) {
			return in_array(strtolower($groupId), $cache['whitelistedGroupsLower']);
		}

		if (!empty($cache['appointmentVisibleGroupsLower'])) {
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
		AttendanceResponse $response,
		array &$summary,
		array &$respondedUserIds,
		array $cache,
		bool $includeComments,
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
		/** @var ?IUser $user */
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
					$this->addResponseToGroup($summary, $groupId, $responseValue, $response, $user, $includeComments, $cache);
				}
			}

			// Check teams (user can be in both groups AND teams - duplicates allowed)
			/** @var list<string> $whitelistedTeams */
			$whitelistedTeams = $cache['whitelistedTeams'];
			foreach ($whitelistedTeams as $teamId) {
				$teamMemberIds = $cache['teamMembers'][$teamId] ?? [];
				if (in_array($userId, $teamMemberIds)) {
					$userInWhitelistedTeam = true;
					$this->addResponseToTeam($summary, $teamId, $responseValue, $response, $user, $cache, $includeComments);
				}
			}

			// If user is not in any whitelisted group or team, add to "others"
			if (!$userInWhitelistedGroup && !$userInWhitelistedTeam) {
				$summary['others'][$responseValue]++;
				$summary['others']['responses'][] = $this->serializeResponse($response, $user, $includeComments, $cache);
			}
		}
	}

	/**
	 * Serialize a response for inclusion in the summary, enriched with the
	 * responder's display name and guest flag. Strips the free-text comment /
	 * checkinComment fields unless the caller is permitted to read comments.
	 */
	private function serializeResponse(AttendanceResponse $response, IUser $user, bool $includeComments, array $cache = []): array {
		$responseData = $response->jsonSerialize();
		if (!$includeComments) {
			unset($responseData['comment'], $responseData['checkinComment']);
		}
		$responseData['userName'] = $user->getDisplayName();
		$responseData['isGuest'] = $this->guestService->isGuestUser($user->getUID());
		// Whoever may see who answered what also sees where they stand in the
		// queue — it is strictly less than the names already in this payload.
		/** @var array<string, true> $confirmedIds */
		$confirmedIds = $cache['confirmedIds'] ?? [];
		$responseData['waitlisted'] = $responseData['response'] === 'yes'
			&& $confirmedIds !== []
			&& !isset($confirmedIds[$user->getUID()]);
		return $responseData;
	}

	/**
	 * @return array<string, true> user IDs holding a spot, empty without a limit
	 */
	private function confirmedIds(Appointment $appointment): array {
		if ($this->capacityService->limitOf($appointment) === null) {
			return [];
		}
		$ids = [];
		foreach ($this->capacityService->split($appointment)['confirmed'] as $row) {
			$ids[$row->getUserId()] = true;
		}
		return $ids;
	}

	/**
	 * Add a response to a group's summary.
	 */
	private function addResponseToGroup(
		array &$summary,
		string $groupId,
		string $responseValue,
		AttendanceResponse $response,
		IUser $user,
		bool $includeComments,
		array $cache = [],
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
		$summary['by_group'][$groupId]['responses'][] = $this->serializeResponse($response, $user, $includeComments, $cache);
	}

	/**
	 * Add a response to a team's summary.
	 */
	private function addResponseToTeam(
		array &$summary,
		string $teamId,
		string $responseValue,
		AttendanceResponse $response,
		IUser $user,
		array $cache,
		bool $includeComments,
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
		$summary['by_team'][$teamId]['responses'][] = $this->serializeResponse($response, $user, $includeComments, $cache);
	}

	/**
	 * Add non-responding users to group summaries.
	 */
	private function addNonRespondingUsers(
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

			/** @var \OCP\IUser $user */
			foreach ($groupUsers as $user) {
				$userId = $user->getUID();

				// allUsers already is the audience — a hash lookup replaces the check.
				if (!isset($cache['allUsers'][$userId])) {
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
		/** @var list<string> $whitelistedTeams */
		$whitelistedTeams = $cache['whitelistedTeams'];
		foreach ($whitelistedTeams as $teamId) {
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
			/** @var list<string> $teamMemberIds */
			$teamMemberIds = $cache['teamMembers'][$teamId] ?? [];
			$nonRespondingUsers = [];
			$maybeUsers = [];

			foreach ($teamMemberIds as $userId) {
				// Restricted: allUsers is the whole audience. Open with a group
				// whitelist: team members outside those groups are absent there.
				$isAttendee = $cache['appointmentHasRestrictions']
					? isset($cache['allUsers'][$userId])
					: $this->visibilityService->isUserTargetAttendee($appointment, $userId);
				if (!$isAttendee) {
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
		array &$summary,
		array $respondedUserIds,
		array $cache,
	): void {
		$totalNonResponding = [];
		$totalMaybe = [];
		$othersNonResponding = [];
		$othersMaybe = [];
		$skipUnaffiliated = !$cache['appointmentHasRestrictions'];
		/** @var list<string> $whitelistedTeams */
		$whitelistedTeams = $cache['whitelistedTeams'];

		/** @var \OCP\IUser $user */
		foreach ($cache['allUsers'] as $user) {
			$userId = $user->getUID();

			$userResponse = $respondedUserIds[$userId] ?? null;
			if ($userResponse !== null && $userResponse !== 'maybe') {
				continue;
			}

			$userGroups = $cache['allUserGroups'][$userId] ?? [];
			$hasAllowedGroup = false;
			$hasVisibleGroup = false;
			foreach ($userGroups as $group) {
				$gid = $group->getGID();
				if ($this->isGroupVisibleAsSection($gid, $cache)) {
					$hasAllowedGroup = true;
					$hasVisibleGroup = true;
					break;
				}
				// Allowed-but-hidden groups (guest_app) still count as affiliation.
				if ($skipUnaffiliated && !$hasAllowedGroup) {
					$hasAllowedGroup = $this->isGroupAllowedCached($gid, $cache);
				}
			}

			$hasRelevantTeam = false;
			if (!$hasVisibleGroup) {
				foreach ($whitelistedTeams as $teamId) {
					$teamMemberIds = $cache['teamMembers'][$teamId] ?? [];
					if (in_array($userId, $teamMemberIds)) {
						$hasRelevantTeam = true;
						break;
					}
				}
			}

			// A restricted appointment invited every relevant user; open ones
			// skip users tied to no allowed group or team — not audience.
			if ($skipUnaffiliated && !$hasAllowedGroup && !$hasRelevantTeam) {
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
