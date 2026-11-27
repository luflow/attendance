<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AttendanceResponse;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Service for generating response summaries.
 * Handles the complex logic of aggregating responses by group and team.
 * Optimized to avoid N+1 query patterns through caching and batch operations.
 *
 * @psalm-type Answer = 'yes'|'no'|'maybe'
 * @psalm-type PendingUser = array{userId: string, displayName: string, isGuest: bool, checkinState: ?string}
 * @psalm-type Section = array{displayName?: string, yes: int, no: int, maybe: int, no_response: int, responses: list<array<array-key, mixed>>, non_responding_users?: list<PendingUser>, maybe_users?: list<PendingUser>}
 * @psalm-type Summary = array{yes: int, no: int, maybe: int, no_response: int, by_group: array<array-key, Section>, by_team: array<array-key, Section>, others: array{yes: int, no: int, maybe: int, no_response: int, responses: list<array<array-key, mixed>>, non_responding_users: list<PendingUser>, maybe_users: list<PendingUser>}, non_responding_users?: list<PendingUser>, maybe_users?: list<PendingUser>}
 * @psalm-type SummaryCache = array{groupsMode: string, sectionGroups: list<string>, sectionGroupsLower: list<string>, sectionTeams: list<string>, teamMembers: array<array-key, array<string>>, teamInfo: array<array-key, array{id: string, label: string, type: string}>, users: array<string, IUser>, userGroups: array<string, array<IGroup>>, checkinStates: array<string, ?string>, groupUsers: array<array-key, list<IUser>>, allUsers: array<string, IUser>, allUserGroups: array<string, array<IGroup>>, appointmentHasRestrictions: bool, appointmentVisibleGroupsLower: array<string>, confirmedIds: array<string, true>}
 */
class ResponseSummaryService {
	private ConfigService $configService;
	private VisibilityService $visibilityService;
	private PermissionService $permissionService;
	private IGroupManager $groupManager;
	private IUserManager $userManager;
	private GuestService $guestService;
	private CapacityService $capacityService;

	public function __construct(
		ConfigService $configService,
		VisibilityService $visibilityService,
		PermissionService $permissionService,
		IGroupManager $groupManager,
		IUserManager $userManager,
		GuestService $guestService,
		CapacityService $capacityService,
	) {
		$this->configService = $configService;
		$this->visibilityService = $visibilityService;
		$this->permissionService = $permissionService;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->guestService = $guestService;
		$this->capacityService = $capacityService;
	}

	/**
	 * Get response summary for an appointment. Takes the responses rather than
	 * reading them: the list builds one summary per row and reads them in bulk.
	 *
	 * @param list<AttendanceResponse> $responses All responses of the appointment
	 * @param bool $includeComments Whether to include the free-text comment /
	 *                              checkinComment fields in the serialized
	 *                              responses. Gated by the caller's
	 *                              PERMISSION_SEE_COMMENTS — never expose these
	 *                              to users who may not read comments.
	 * @return array The response summary
	 */
	public function getResponseSummary(Appointment $appointment, array $responses, bool $includeComments = false): array {
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

		$summary['by_group'] = $this->sortGroups($summary['by_group'], $cache['sectionGroups']);
		$summary['by_team'] = $this->sortTeams($summary['by_team'], $cache['sectionTeams']);

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
	 *
	 * @param list<AttendanceResponse> $responses All responses of the appointment
	 */
	public function getResponseCounts(Appointment $appointment, array $responses): array {
		/** @var array{yes: int, no: int, maybe: int, no_response: int} $counts */
		$counts = $this->initializeSummary();
		/** @var array<string, true> $respondedUserIds */
		$respondedUserIds = [];
		foreach ($this->countedResponses($appointment, $responses) as $answer => $response) {
			$counts[$answer]++;
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
	 * Display names of who answered what. Carries names — only for callers
	 * that may see the response overview of this appointment.
	 *
	 * @param list<AttendanceResponse> $responses All responses of the appointment
	 * @return array{yes: list<string>, no: list<string>, maybe: list<string>}
	 */
	public function getRespondersByAnswer(Appointment $appointment, array $responses): array {
		$names = ['yes' => [], 'no' => [], 'maybe' => []];
		foreach ($this->countedResponses($appointment, $responses) as $answer => $response) {
			$userId = $response->getUserId();
			$names[$answer][] = $this->userManager->getDisplayName($userId) ?? $userId;
		}

		foreach ($names as &$list) {
			sort($list, SORT_NATURAL | SORT_FLAG_CASE);
		}

		return $names;
	}

	/**
	 * The responses the numbers are made of: a real answer, and not a manager's
	 * on an appointment they were never part of.
	 *
	 * @param list<AttendanceResponse> $responses
	 * @return \Generator<'yes'|'no'|'maybe', AttendanceResponse>
	 */
	private function countedResponses(Appointment $appointment, array $responses): \Generator {
		foreach ($responses as $response) {
			$answer = $response->getResponse();
			if (in_array($answer, ['yes', 'no', 'maybe'], true)
				&& !$this->isNonAttendeeAdminResponse($appointment, $response->getUserId())) {
				yield $answer => $response;
			}
		}
	}

	/**
	 * Build cache of users, groups, teams, and settings to avoid N+1 queries.
	 *
	 * Optimized to only load relevant users based on appointment visibility
	 * and whitelisted groups/teams, avoiding loading ALL users in large instances.
	 *
	 * @param list<AttendanceResponse> $responses
	 * @return SummaryCache
	 */
	private function buildCache(Appointment $appointment, array $responses): array {
		// Cache whitelisted groups (called once instead of per-group)
		$whitelistedGroups = $this->configService->getWhitelistedGroups();
		$groupsMode = $this->configService->getResponseSummaryGroupsMode();
		// Only "specific" mode pins sections to the whitelist. The raw list
		// still separately narrows the audience of open appointments via
		// getTargetAttendees() below, regardless of this display mode.
		$sectionGroups = $groupsMode === ConfigService::RESPONSE_SUMMARY_MODE_SPECIFIC ? $whitelistedGroups : [];
		$sectionGroupsLower = array_map('strtolower', $sectionGroups);

		// Cache whitelisted teams
		$whitelistedTeams = $this->configService->getWhitelistedTeams();
		$teamsMode = $this->configService->getResponseSummaryTeamsMode();
		$sectionTeams = $teamsMode === ConfigService::RESPONSE_SUMMARY_MODE_SPECIFIC ? $whitelistedTeams : [];

		$visibilitySettings = $this->visibilityService->getVisibilitySettings($appointment);
		$appointmentHasRestrictions = $this->visibilityService->hasRestrictedVisibility($appointment);
		$appointmentVisibleGroupsLower = array_map('strtolower', $visibilitySettings['groups']);

		// Somebody can turn up without ever answering: their row carries a
		// check-in state and no response, so processResponse() drops it and
		// they only ever reach the summary as a bare name. Keep the state here
		// so the chips can show it (issue #229).
		$checkinStates = [];
		foreach ($responses as $response) {
			if ($response->isCheckedIn()) {
				$checkinStates[$response->getUserId()] = $response->getCheckinState();
			}
		}

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

		// Pre-fetch members only of the groups that can render as sections —
		// scanning every instance group for a restricted appointment would be
		// wasted work (issue #199).
		/** @var array<array-key, list<\OCP\IUser>> $groupUsers */
		$groupUsers = [];
		$sectionGroupIds = ConfigService::sectionGroups($groupsMode, $whitelistedGroups, $visibilitySettings['groups'])
			?? $this->visibilityService->getAllGroupIds();
		foreach ($sectionGroupIds as $groupId) {
			$groupUsers[$groupId] = $this->visibilityService->getGroupMembers($groupId);
		}

		// Pre-fetch whitelisted team members and info
		$teamMembers = [];
		$teamInfo = [];
		foreach ($sectionTeams as $teamId) {
			$teamMembers[$teamId] = $this->visibilityService->getTeamMembers($teamId);
			$info = $this->visibilityService->getTeamInfo($teamId);
			if ($info !== null) {
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
			'groupsMode' => $groupsMode,
			'sectionGroups' => $sectionGroups,
			'sectionGroupsLower' => $sectionGroupsLower,
			'sectionTeams' => $sectionTeams,
			'teamMembers' => $teamMembers,
			'teamInfo' => $teamInfo,
			'users' => $users,
			'userGroups' => $userGroups,
			'checkinStates' => $checkinStates,
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
	 * The chip shape for somebody with no answer to show — a non-responder or a
	 * "maybe". Carries the check-in state: attending without answering is
	 * exactly the case the organizer has to be able to see.
	 *
	 * @param SummaryCache $cache
	 * @return PendingUser
	 */
	private function pendingUserData(IUser $user, array $cache): array {
		$userId = $user->getUID();
		return [
			'userId' => $userId,
			'displayName' => $user->getDisplayName(),
			'isGuest' => $this->guestService->isGuestUser($userId),
			'checkinState' => $cache['checkinStates'][$userId] ?? null,
		];
	}

	/**
	 * Check if a group may appear as a section in the summary (using cache).
	 *
	 * Driven by the admin's chosen response-summary-groups mode: 'specific'
	 * sections only the configured whitelist regardless of the appointment's
	 * own visibility restrictions (issue #199). 'none' and 'all' both still
	 * section a restricted appointment by its own restriction groups (issue
	 * #199 applies with no whitelist too) — only a fully open appointment
	 * tells them apart: 'none' sections nothing there (issue #212), 'all'
	 * sections every group the responder belongs to.
	 *
	 * @param SummaryCache $cache
	 */
	private function isGroupAllowedCached(string $groupId, array $cache): bool {
		if ($cache['groupsMode'] === ConfigService::RESPONSE_SUMMARY_MODE_SPECIFIC) {
			return in_array(strtolower($groupId), $cache['sectionGroupsLower']);
		}

		if ($cache['appointmentVisibleGroupsLower'] !== []) {
			return in_array(strtolower($groupId), $cache['appointmentVisibleGroupsLower']);
		}

		return $cache['groupsMode'] === ConfigService::RESPONSE_SUMMARY_MODE_ALL;
	}

	/**
	 * Check if a group should appear as its own section in the summary.
	 *
	 * Same as isGroupAllowedCached but hides the Guests app's system group
	 * unless an admin opts in via the whitelist — otherwise every guest user
	 * would be lumped under one section regardless of context.
	 *
	 * @param SummaryCache $cache
	 */
	private function isGroupVisibleAsSection(string $groupId, array $cache): bool {
		if (GuestService::isGuestsSystemGroup($groupId)
			&& !in_array(GuestService::GUESTS_SYSTEM_GROUP, $cache['sectionGroupsLower'], true)) {
			return false;
		}
		return $this->isGroupAllowedCached($groupId, $cache);
	}

	/**
	 * Whether a response is a manager's answer to an appointment they were
	 * never part of — possible until issue #251, and such rows remain. Only
	 * that case is filtered: a regular user who answered while still a target
	 * attendee keeps their response even if they later leave the group/team
	 * (issue #213) — on upcoming appointments the write side already withdrew
	 * it (AppointmentService::withdrawOutsiders, issue #326).
	 */
	private function isNonAttendeeAdminResponse(Appointment $appointment, string $userId): bool {
		return !$this->visibilityService->isUserTargetAttendee($appointment, $userId)
			&& $this->permissionService->canManageAppointments($userId);
	}

	/**
	 * Initialize the summary structure.
	 *
	 * @return Summary
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
	 *
	 * @param Summary $summary
	 * @param array<string, Answer> $respondedUserIds
	 * @param SummaryCache $cache
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

		// A former target attendee keeps their recorded response after leaving
		// the group/team that made the appointment visible to them (issue
		// #213) — only a manager's test response on an appointment they were
		// never part of is filtered.
		if ($this->isNonAttendeeAdminResponse($appointment, $userId)) {
			return;
		}

		$responseValue = $response->getResponse();

		// Skip invalid or empty responses
		if (!in_array($responseValue, ['yes', 'no', 'maybe'], true)) {
			return;
		}

		$summary[$responseValue]++;
		$respondedUserIds[$userId] = $responseValue;

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
			foreach ($cache['sectionTeams'] as $teamId) {
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
	 *
	 * @param SummaryCache $cache
	 * @return array<array-key, mixed>
	 */
	private function serializeResponse(AttendanceResponse $response, IUser $user, bool $includeComments, array $cache): array {
		$responseData = $response->jsonSerialize();
		if (!$includeComments) {
			unset($responseData['comment'], $responseData['checkinComment']);
		}
		$responseData['userName'] = $user->getDisplayName();
		$responseData['isGuest'] = $this->guestService->isGuestUser($user->getUID());
		// Whoever may see who answered what also sees where they stand in the
		// queue — it is strictly less than the names already in this payload.
		$confirmedIds = $cache['confirmedIds'];
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
	 *
	 * @param Summary $summary
	 * @param Answer $responseValue
	 * @param SummaryCache $cache
	 */
	private function addResponseToGroup(
		array &$summary,
		string $groupId,
		string $responseValue,
		AttendanceResponse $response,
		IUser $user,
		bool $includeComments,
		array $cache,
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
	 *
	 * @param Summary $summary
	 * @param Answer $responseValue
	 * @param SummaryCache $cache
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
			$summary['by_team'][$teamId] = [
				'displayName' => $cache['teamInfo'][$teamId]['label'] ?? $teamId,
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
	 *
	 * @param Summary $summary
	 * @param array<string, Answer> $respondedUserIds
	 * @param SummaryCache $cache
	 */
	private function addNonRespondingUsers(
		array &$summary,
		array $respondedUserIds,
		array $cache,
	): void {
		// $cache['groupUsers'] already holds exactly the candidate groups for
		// the active mode (every group for 'all', the whitelist for
		// 'specific', none for 'none') — no need to branch on mode again here.
		foreach ($cache['groupUsers'] as $groupId => $groupUsers) {
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

			$nonRespondingUsers = [];
			$maybeUsers = [];

			foreach ($groupUsers as $user) {
				$userId = $user->getUID();

				// allUsers already is the audience — a hash lookup replaces the check.
				if (!isset($cache['allUsers'][$userId])) {
					continue;
				}

				$userResponse = $respondedUserIds[$userId] ?? null;
				if ($userResponse === null) {
					$nonRespondingUsers[] = $this->pendingUserData($user, $cache);
				} elseif ($userResponse === 'maybe') {
					$maybeUsers[] = $this->pendingUserData($user, $cache);
				}
			}

			$summary['by_group'][$groupId]['no_response'] = count($nonRespondingUsers);
			$summary['by_group'][$groupId]['non_responding_users'] = $nonRespondingUsers;
			$summary['by_group'][$groupId]['maybe_users'] = $maybeUsers;
		}
	}

	/**
	 * Add non-responding users to team summaries.
	 *
	 * @param Summary $summary
	 * @param array<string, Answer> $respondedUserIds
	 * @param SummaryCache $cache
	 */
	private function addNonRespondingTeamUsers(
		Appointment $appointment,
		array &$summary,
		array $respondedUserIds,
		array $cache,
	): void {
		foreach ($cache['sectionTeams'] as $teamId) {
			if (!isset($summary['by_team'][$teamId])) {
				$summary['by_team'][$teamId] = [
					'displayName' => $cache['teamInfo'][$teamId]['label'] ?? $teamId,
					'yes' => 0,
					'no' => 0,
					'maybe' => 0,
					'no_response' => 0,
					'responses' => [],
					'non_responding_users' => []
				];
			}

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
						$userData = $this->pendingUserData($user, $cache);
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
	 *
	 * @param Summary $summary
	 * @param array<string, Answer> $respondedUserIds
	 * @param SummaryCache $cache
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
				foreach ($cache['sectionTeams'] as $teamId) {
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

			$userData = $this->pendingUserData($user, $cache);

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
	 *
	 * @param array<array-key, Section> $byGroup
	 * @return array<array-key, Section>
	 */
	private function filterEmptyGroups(array $byGroup): array {
		return array_filter($byGroup, function (array $group): bool {
			// A group is considered non-empty if it has any responses, non-responding, or maybe users
			$hasResponses = $group['responses'] !== [];
			$hasNonResponding = ($group['non_responding_users'] ?? []) !== [];
			$hasMaybeUsers = ($group['maybe_users'] ?? []) !== [];
			return $hasResponses || $hasNonResponding || $hasMaybeUsers;
		});
	}

	/**
	 * Sort groups by whitelisted order or alphabetically.
	 *
	 * @param array<array-key, Section> $byGroup
	 * @param list<string> $whitelistedGroups
	 * @return array<array-key, Section>
	 */
	private function sortGroups(array $byGroup, array $whitelistedGroups): array {
		return $this->sortSections($byGroup, $whitelistedGroups, static fn (int|string $a, int|string $b): int => $a <=> $b);
	}

	/**
	 * Sort teams by whitelisted order or alphabetically by display name.
	 *
	 * @param array<array-key, Section> $byTeam
	 * @param list<string> $whitelistedTeams
	 * @return array<array-key, Section>
	 */
	private function sortTeams(array $byTeam, array $whitelistedTeams): array {
		return $this->sortSections($byTeam, $whitelistedTeams, static fn (int|string $a, int|string $b): int => strcasecmp(
			$byTeam[$a]['displayName'] ?? (string)$a,
			$byTeam[$b]['displayName'] ?? (string)$b,
		));
	}

	/**
	 * Whitelisted sections first in their configured order, the rest by $compare.
	 *
	 * @param array<array-key, Section> $sections
	 * @param list<string> $whitelist
	 * @param \Closure(array-key, array-key): int $compare
	 * @return array<array-key, Section>
	 */
	private function sortSections(array $sections, array $whitelist, \Closure $compare): array {
		$position = [];
		foreach ($whitelist as $index => $id) {
			$position[$id] ??= $index;
		}
		uksort($sections, static fn (int|string $a, int|string $b): int
			=> (($position[$a] ?? PHP_INT_MAX) <=> ($position[$b] ?? PHP_INT_MAX)) ?: $compare($a, $b));
		return $sections;
	}
}
