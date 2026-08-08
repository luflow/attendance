<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Db\CategoryMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Cross-appointment evaluation: how each person answered, and whether they
 * actually turned up.
 *
 * Two denominators, deliberately different. The response rate counts every
 * appointment a person was addressed to, upcoming ones included. The
 * attendance rate counts only appointments that are over *and* had at least
 * one check-in recorded — otherwise a person's number would depend on how
 * diligently somebody else worked the check-in list.
 *
 * @psalm-type StatsCounts = array{targetCount: int, yes: int, no: int, maybe: int, noResponse: int, present: int, absent: int, notRecorded: int, attendanceBase: int, noShow: int, attendedDespiteNo: int, responseRate: ?float, acceptRate: ?float, attendanceRate: ?float}
 * @psalm-type StatsPerson = array{userId: string, displayName: string, isGuest: bool, sections: list<string>, targetCount: int, yes: int, no: int, maybe: int, noResponse: int, present: int, absent: int, notRecorded: int, attendanceBase: int, noShow: int, attendedDespiteNo: int, responseRate: ?float, acceptRate: ?float, attendanceRate: ?float}
 * @psalm-type StatsSection = array{id: string, displayName: string, personCount: int, targetCount: int, yes: int, no: int, maybe: int, noResponse: int, present: int, absent: int, notRecorded: int, attendanceBase: int, noShow: int, attendedDespiteNo: int, responseRate: ?float, acceptRate: ?float, attendanceRate: ?float}
 * @psalm-type StatsTimelinePoint = array{appointmentId: int, name: string, startDatetime: ?string, targetCount: int, yes: int, present: int, attendanceRecorded: bool}
 * @psalm-type StatsCategoryTally = array{categoryId: ?int, appointmentCount: int, targetCount: int, yes: int, present: int, attendanceBase: int}
 * @psalm-type StatsCategory = array{categoryId: ?int, displayName: string, appointmentCount: int, targetCount: int, yes: int, present: int, attendanceBase: int, acceptRate: ?float, attendanceRate: ?float}
 */
class StatisticsService {
	/**
	 * Above this the evaluation refuses to run. The target expansion is
	 * appointments × addressees, so an unbounded "everything" range on a large
	 * instance would hit the PHP time limit instead of answering.
	 */
	public const MAX_APPOINTMENTS = 1000;

	public const SECTION_OTHERS = '__others__';

	/** @var array<string, array<string, IUser>> groupId → members */
	private array $groupMembersCache = [];
	/** @var ?array<string, IUser> every user, for appointments open to all without a whitelist */
	private ?array $allUsersCache = null;
	/** @var array<string, list<string>> userId → group IDs */
	private array $userGroupsCache = [];

	public function __construct(
		private AppointmentMapper $appointmentMapper,
		private AttendanceResponseMapper $responseMapper,
		private CategoryMapper $categoryMapper,
		private ConfigService $configService,
		private VisibilityService $visibilityService,
		private GuestService $guestService,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private ITimeFactory $timeFactory,
		private IL10N $l10n,
	) {
	}

	/**
	 * @param ?string $limitToUserId When set, only this person's row is
	 *                               returned and the chart series are left out —
	 *                               the shape for users without the permission.
	 * @return array{appointmentCount: int, pastCount: int, attendanceRecordedCount: int, groupBy: string, people: list<StatsPerson>, sections: list<StatsSection>, totals: StatsCounts, timeline: list<StatsTimelinePoint>, byCategory: list<StatsCategory>}
	 * @throws StatisticsRangeException
	 */
	public function getStatistics(StatisticsFilter $filter, ?string $limitToUserId = null): array {
		$appointments = $this->loadAppointments($filter);
		$evaluation = $this->evaluate($appointments);
		$tallies = $evaluation['tallies'];

		$membership = [];
		foreach (array_keys($tallies) as $userId) {
			$membership[$userId] = $this->sectionsFor($userId, $filter);
		}

		$sections = $this->buildSections($tallies, $membership, $filter);

		$totals = new StatisticsTally();
		foreach ($tallies as $tally) {
			$totals->add($tally);
		}

		/** @var array<string, string> $displayNames */
		$displayNames = [];
		foreach (array_keys($tallies) as $userId) {
			if ($limitToUserId !== null && $userId !== $limitToUserId) {
				continue;
			}
			$displayNames[$userId] = $this->userManager->get($userId)?->getDisplayName() ?? $userId;
		}
		uasort($displayNames, static fn (string $a, string $b): int => strcasecmp($a, $b));

		$people = [];
		foreach ($displayNames as $userId => $displayName) {
			$people[] = [
				'userId' => $userId,
				'displayName' => $displayName,
				'isGuest' => $this->guestService->isGuestUser($userId),
				'sections' => $membership[$userId] ?? [],
			] + $tallies[$userId]->toArray();
		}

		return [
			'appointmentCount' => count($appointments),
			'pastCount' => $evaluation['pastCount'],
			'attendanceRecordedCount' => $evaluation['attendanceRecordedCount'],
			'groupBy' => $filter->groupBy,
			'people' => $people,
			'sections' => $sections,
			'totals' => $totals->toArray(),
			'timeline' => $limitToUserId === null ? $evaluation['timeline'] : [],
			'byCategory' => $limitToUserId === null ? $this->buildCategorySeries($evaluation['byCategory']) : [],
		];
	}

	/**
	 * One person's appointments in the filtered range, for the drill-down.
	 *
	 * @return array{userId: string, displayName: string, isGuest: bool, entries: list<array{appointmentId: int, name: string, startDatetime: ?string, categoryId: ?int, response: ?string, checkinState: ?string, attendanceRecorded: bool}>}
	 * @throws StatisticsRangeException
	 */
	public function getPersonDetail(StatisticsFilter $filter, string $userId): array {
		$appointments = $this->loadAppointments($filter);
		$responses = $this->indexResponses($appointments);
		$user = $this->userManager->get($userId);

		$entries = [];
		foreach ($appointments as $appointment) {
			$appointmentId = $appointment->getId();
			if (!in_array($userId, $this->targetUserIds($appointment), true)) {
				continue;
			}

			$response = $responses[$appointmentId][$userId] ?? null;
			$entries[] = [
				'appointmentId' => $appointmentId,
				'name' => $appointment->getName(),
				'startDatetime' => $this->startOf($appointment),
				'categoryId' => $appointment->getCategoryId(),
				'response' => $this->responseValue($response),
				'checkinState' => $this->checkinState($response),
				'attendanceRecorded' => $this->countsForAttendance($appointment, $responses),
			];
		}

		return [
			'userId' => $userId,
			'displayName' => $user?->getDisplayName() ?? $userId,
			'isGuest' => $this->guestService->isGuestUser($userId),
			'entries' => $entries,
		];
	}

	/**
	 * @return list<Appointment>
	 * @throws StatisticsRangeException
	 */
	private function loadAppointments(StatisticsFilter $filter): array {
		$appointments = $this->appointmentMapper->findForStatistics(
			$filter->startDate,
			$filter->endDate,
			$filter->categoryIds,
			$filter->includeUncategorized,
		);

		if (count($appointments) > self::MAX_APPOINTMENTS) {
			throw new StatisticsRangeException(count($appointments), self::MAX_APPOINTMENTS);
		}

		return $appointments;
	}

	/**
	 * @param list<Appointment> $appointments
	 * @return array{tallies: array<string, StatisticsTally>, timeline: list<StatsTimelinePoint>, byCategory: array<int, StatsCategoryTally>, pastCount: int, attendanceRecordedCount: int}
	 */
	private function evaluate(array $appointments): array {
		$responses = $this->indexResponses($appointments);

		/** @var array<string, StatisticsTally> $tallies */
		$tallies = [];
		$timeline = [];
		/** @var array<int, StatsCategoryTally> $byCategory */
		$byCategory = [];
		$pastCount = 0;
		$attendanceRecordedCount = 0;

		foreach ($appointments as $appointment) {
			$appointmentId = $appointment->getId();
			$targets = $this->targetUserIds($appointment);
			$countsForAttendance = $this->countsForAttendance($appointment, $responses);

			if ($this->hasEnded($appointment)) {
				$pastCount++;
			}
			if ($countsForAttendance) {
				$attendanceRecordedCount++;
			}

			$categoryId = $appointment->getCategoryId();
			$categoryKey = $categoryId ?? 0;
			if (!isset($byCategory[$categoryKey])) {
				$byCategory[$categoryKey] = [
					'categoryId' => $categoryId,
					'appointmentCount' => 0,
					'targetCount' => 0,
					'yes' => 0,
					'present' => 0,
					'attendanceBase' => 0,
				];
			}
			$byCategory[$categoryKey]['appointmentCount']++;
			$byCategory[$categoryKey]['targetCount'] += count($targets);

			$yesCount = 0;
			$presentCount = 0;

			foreach ($targets as $targetUserId) {
				$response = $responses[$appointmentId][$targetUserId] ?? null;
				$answer = $this->responseValue($response);
				$checkin = $this->checkinState($response);

				$tallies[$targetUserId] ??= new StatisticsTally();
				$tallies[$targetUserId]->record($answer, $checkin, $countsForAttendance);

				if ($answer === 'yes') {
					$yesCount++;
				}
				if ($countsForAttendance && $checkin === 'yes') {
					$presentCount++;
				}
			}

			$byCategory[$categoryKey]['yes'] += $yesCount;
			$byCategory[$categoryKey]['present'] += $presentCount;
			if ($countsForAttendance) {
				$byCategory[$categoryKey]['attendanceBase'] += count($targets);
			}

			$timeline[] = [
				'appointmentId' => $appointmentId,
				'name' => $appointment->getName(),
				'startDatetime' => $this->startOf($appointment),
				'targetCount' => count($targets),
				'yes' => $yesCount,
				'present' => $presentCount,
				'attendanceRecorded' => $countsForAttendance,
			];
		}

		return [
			'tallies' => $tallies,
			'timeline' => $timeline,
			'byCategory' => $byCategory,
			'pastCount' => $pastCount,
			'attendanceRecordedCount' => $attendanceRecordedCount,
		];
	}

	/**
	 * @param list<Appointment> $appointments
	 * @return array<int, array<string, AttendanceResponse>> appointmentId → userId → response
	 */
	private function indexResponses(array $appointments): array {
		$ids = [];
		foreach ($appointments as $appointment) {
			$ids[] = $appointment->getId();
		}

		$indexed = [];
		foreach ($this->responseMapper->findByAppointmentIds($ids) as $response) {
			$indexed[$response->getAppointmentId()][$response->getUserId()] = $response;
		}

		return $indexed;
	}

	/**
	 * Everyone the appointment was addressed to.
	 *
	 * Mirrors VisibilityService::isUserTargetAttendee(), but resolves the
	 * audience once per appointment instead of once per candidate — over a
	 * thousand appointments the per-user check decodes the same JSON fields
	 * hundreds of thousands of times.
	 *
	 * @return list<string>
	 */
	private function targetUserIds(Appointment $appointment): array {
		if (!$this->visibilityService->hasRestrictedVisibility($appointment)) {
			return array_keys($this->openAudience($appointment));
		}

		$settings = $this->visibilityService->getVisibilitySettings($appointment);

		$userIds = [];
		foreach ($settings['users'] as $userId) {
			if ($this->userManager->get($userId) !== null) {
				$userIds[$userId] = true;
			}
		}
		foreach ($settings['groups'] as $groupId) {
			foreach (array_keys($this->groupMembers($groupId)) as $memberId) {
				$userIds[$memberId] = true;
			}
		}
		foreach ($settings['teams'] as $teamId) {
			foreach ($this->visibilityService->getTeamMembers($teamId) as $memberId) {
				$userIds[$memberId] = true;
			}
		}

		return array_keys($userIds);
	}

	/**
	 * The audience of an appointment nobody restricted: the whitelisted groups
	 * when an admin configured any, everyone otherwise. Identical for every
	 * such appointment, so it is resolved once per request.
	 *
	 * @return array<string, IUser>
	 */
	private function openAudience(Appointment $appointment): array {
		return $this->allUsersCache ??= $this->visibilityService->getRelevantUsersForAppointment(
			$appointment,
			$this->configService->getWhitelistedGroups(),
		);
	}

	/**
	 * @return array<string, IUser>
	 */
	private function groupMembers(string $groupId): array {
		if (isset($this->groupMembersCache[$groupId])) {
			return $this->groupMembersCache[$groupId];
		}

		$members = [];
		$group = $this->groupManager->get($groupId);
		if ($group !== null) {
			foreach ($group->getUsers() as $user) {
				$members[$user->getUID()] = $user;
			}
		}

		return $this->groupMembersCache[$groupId] = $members;
	}

	/**
	 * Sections a person shows up in. People in several whitelisted groups
	 * appear in each of them, exactly like the per-appointment summary — the
	 * totals row is what counts everyone once.
	 *
	 * @return list<string>
	 */
	private function sectionsFor(string $userId, StatisticsFilter $filter): array {
		if ($filter->groupsByTeams()) {
			$teams = [];
			foreach ($this->configService->getWhitelistedTeams() as $teamId) {
				if ($this->visibilityService->isUserInTeam($userId, $teamId)) {
					$teams[] = $teamId;
				}
			}
			return $teams !== [] ? $teams : [self::SECTION_OTHERS];
		}

		$whitelisted = array_map('strtolower', $this->configService->getWhitelistedGroups());
		$sections = [];
		foreach ($this->userGroups($userId) as $groupId) {
			if (GuestService::isGuestsSystemGroup($groupId)
				&& !in_array(GuestService::GUESTS_SYSTEM_GROUP, $whitelisted, true)) {
				continue;
			}
			if ($whitelisted !== [] && !in_array(strtolower($groupId), $whitelisted, true)) {
				continue;
			}
			$sections[] = $groupId;
		}

		return $sections !== [] ? $sections : [self::SECTION_OTHERS];
	}

	/**
	 * @return list<string>
	 */
	private function userGroups(string $userId): array {
		if (isset($this->userGroupsCache[$userId])) {
			return $this->userGroupsCache[$userId];
		}

		$user = $this->userManager->get($userId);
		return $this->userGroupsCache[$userId] = $user !== null
			? array_map('strval', $this->groupManager->getUserGroupIds($user))
			: [];
	}

	/**
	 * @param array<string, StatisticsTally> $tallies
	 * @param array<string, list<string>> $membership userId → section IDs
	 * @return list<StatsSection>
	 */
	private function buildSections(array $tallies, array $membership, StatisticsFilter $filter): array {
		/** @var array<array-key, StatisticsTally> $buckets */
		$buckets = [];
		/** @var array<array-key, int> $memberCounts */
		$memberCounts = [];

		foreach ($tallies as $userId => $tally) {
			foreach ($membership[$userId] ?? [] as $sectionId) {
				$buckets[$sectionId] ??= new StatisticsTally();
				$buckets[$sectionId]->add($tally);
				$memberCounts[$sectionId] = ($memberCounts[$sectionId] ?? 0) + 1;
			}
		}

		$sections = [];
		foreach ($buckets as $sectionId => $tally) {
			$sections[(string)$sectionId] = [
				'id' => (string)$sectionId,
				'displayName' => $this->sectionName((string)$sectionId, $filter),
				'personCount' => $memberCounts[$sectionId] ?? 0,
			] + $tally->toArray();
		}

		return $this->sortSections($sections, $filter);
	}

	private function sectionName(string $sectionId, StatisticsFilter $filter): string {
		if ($sectionId === self::SECTION_OTHERS) {
			return $this->l10n->t('Others');
		}
		if ($filter->groupsByTeams()) {
			$info = $this->visibilityService->getTeamInfo($sectionId);
			return isset($info['label']) ? (string)$info['label'] : $sectionId;
		}
		return $this->groupManager->get($sectionId)?->getDisplayName() ?? $sectionId;
	}

	/**
	 * Admin-configured order first, the rest alphabetically, "Others" last —
	 * the same order the per-appointment summary uses.
	 *
	 * @param array<array-key, StatsSection> $sections
	 * @return list<StatsSection>
	 */
	private function sortSections(array $sections, StatisticsFilter $filter): array {
		$configured = $filter->groupsByTeams()
			? $this->configService->getWhitelistedTeams()
			: $this->configService->getWhitelistedGroups();

		$sorted = [];
		foreach ($configured as $sectionId) {
			if (isset($sections[$sectionId])) {
				$sorted[] = $sections[$sectionId];
				unset($sections[$sectionId]);
			}
		}

		$others = $sections[self::SECTION_OTHERS] ?? null;
		unset($sections[self::SECTION_OTHERS]);

		$remaining = array_values($sections);
		usort($remaining, static fn (array $a, array $b): int => strcasecmp((string)$a['displayName'], (string)$b['displayName']));

		$sorted = array_merge($sorted, $remaining);
		if ($others !== null) {
			$sorted[] = $others;
		}

		return $sorted;
	}

	/**
	 * @param array<int, StatsCategoryTally> $byCategory
	 * @return list<StatsCategory>
	 */
	private function buildCategorySeries(array $byCategory): array {
		$names = [];
		foreach ($this->categoryMapper->findAll() as $category) {
			$names[$category->getId()] = $category->getName();
		}

		$series = [];
		foreach ($byCategory as $entry) {
			$categoryId = $entry['categoryId'];
			$series[] = [
				'categoryId' => $categoryId,
				'displayName' => $categoryId !== null
					? ($names[$categoryId] ?? (string)$categoryId)
					: $this->l10n->t('Without category'),
				'appointmentCount' => $entry['appointmentCount'],
				'targetCount' => $entry['targetCount'],
				'yes' => $entry['yes'],
				'present' => $entry['present'],
				'attendanceBase' => $entry['attendanceBase'],
				'acceptRate' => StatisticsTally::rate($entry['yes'], $entry['targetCount']),
				'attendanceRate' => StatisticsTally::rate($entry['present'], $entry['attendanceBase']),
			];
		}

		usort($series, static fn (array $a, array $b): int => strcasecmp((string)$a['displayName'], (string)$b['displayName']));

		return $series;
	}

	/**
	 * Whether the appointment contributes to attendance rates at all: it has to
	 * be over, and somebody has to have worked the check-in list.
	 *
	 * @param array<int, array<string, AttendanceResponse>> $responses
	 */
	private function countsForAttendance(Appointment $appointment, array $responses): bool {
		if (!$this->hasEnded($appointment)) {
			return false;
		}

		foreach ($responses[$appointment->getId()] ?? [] as $response) {
			if (in_array($response->getCheckinState(), ['yes', 'no'], true)) {
				return true;
			}
		}

		return false;
	}

	private function hasEnded(Appointment $appointment): bool {
		$now = $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
		return $appointment->getEndDatetime() < $now;
	}

	private function startOf(Appointment $appointment): ?string {
		$serialized = $appointment->jsonSerialize();
		return isset($serialized['startDatetime']) ? (string)$serialized['startDatetime'] : null;
	}

	private function responseValue(?AttendanceResponse $response): ?string {
		$value = $response?->getResponse();
		return in_array($value, ['yes', 'no', 'maybe'], true) ? $value : null;
	}

	private function checkinState(?AttendanceResponse $response): ?string {
		$state = $response?->getCheckinState();
		return in_array($state, ['yes', 'no'], true) ? $state : null;
	}
}
