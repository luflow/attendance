<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
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
 * @psalm-type StatsCounts = array{targetCount: int, yes: int, no: int, maybe: int, noResponse: int, present: int, absent: int, notRecorded: int, attendanceBase: int, noShow: int, responseRate: ?float, acceptRate: ?float, attendanceRate: ?float}
 * @psalm-type StatsPerson = array{userId: string, displayName: string, isGuest: bool, sections: list<string>, targetCount: int, yes: int, no: int, maybe: int, noResponse: int, present: int, absent: int, notRecorded: int, attendanceBase: int, noShow: int, responseRate: ?float, acceptRate: ?float, attendanceRate: ?float}
 * @psalm-type StatsSection = array{id: string, displayName: string, personCount: int, targetCount: int, yes: int, no: int, maybe: int, noResponse: int, present: int, absent: int, notRecorded: int, attendanceBase: int, noShow: int, responseRate: ?float, acceptRate: ?float, attendanceRate: ?float}
 * @psalm-type StatsTimelinePoint = array{appointmentId: int, name: string, startDatetime: ?string, targetCount: int, yes: int, present: int, attendanceRecorded: bool}
 * @psalm-type StatsCategory = array{categoryId: ?int, displayName: string, appointmentCount: int, targetCount: int, yes: int, present: int, attendanceBase: int, acceptRate: ?float, attendanceRate: ?float}
 * @psalm-type StatsPersonDetail = array{userId: string, displayName: string, isGuest: bool, entries: list<array{appointmentId: int, name: string, startDatetime: ?string, response: ?string, checkinState: ?string, attendanceRecorded: bool, comment: ?string}>}
 * @psalm-type StatsCategoryTally = array{categoryId: ?int, appointmentCount: int, tally: StatisticsTally}
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
	/** @var ?array<string, IUser> the audience of an unrestricted appointment */
	private ?array $openAudienceCache = null;
	/** @var ?array<array-key, true> the same audience as a lookup set */
	private ?array $openAudienceIdsCache = null;

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

		$membership = $this->buildMembership(array_keys($tallies), $filter);

		// A group average is a statement about colleagues too, so it needs the
		// same permission the individual rows do.
		$sections = $limitToUserId === null ? $this->buildSections($tallies, $membership, $filter) : [];

		$totals = new StatisticsTally();
		/** @var array<string, string> $displayNames */
		$displayNames = [];
		foreach ($tallies as $userId => $tally) {
			if ($limitToUserId !== null && $userId !== $limitToUserId) {
				continue;
			}
			$totals->add($tally);
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
	 * @param bool $withComments Whether the viewer may read this person's comments
	 * @return StatsPersonDetail
	 * @throws StatisticsRangeException
	 */
	public function getPersonDetail(StatisticsFilter $filter, string $userId, bool $withComments = false): array {
		$appointments = $this->loadAppointments($filter);
		$appointmentIds = $this->idsOf($appointments);
		$responses = $this->indexResponses($appointmentIds, $userId, $withComments);
		$checkedIn = $this->appointmentsWithCheckins($appointmentIds);
		$user = $this->userManager->get($userId);

		$entries = [];
		foreach ($appointments as $appointment) {
			$appointmentId = $appointment->getId();
			if (!isset($this->targetUserIdSet($appointment)[$userId])) {
				continue;
			}

			$answer = $responses[$appointmentId][$userId] ?? null;
			$entries[] = [
				'appointmentId' => $appointmentId,
				'name' => $appointment->getName(),
				'startDatetime' => $this->startOf($appointment),
				'response' => $answer !== null ? $this->responseValue($answer['response']) : null,
				'checkinState' => $answer !== null ? $this->checkinState($answer['checkinState']) : null,
				'attendanceRecorded' => $this->hasEnded($appointment) && isset($checkedIn[$appointmentId]),
				'comment' => $answer['comment'] ?? null,
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
		// One over the cap is enough to know the range is too wide, and stops
		// the query hydrating everything behind it just to be refused.
		$appointments = $this->appointmentMapper->findForStatistics(
			$filter->startDate,
			$filter->endDate,
			$filter->categoryIds,
			$filter->includeUncategorized,
			self::MAX_APPOINTMENTS + 1,
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
		$appointmentIds = $this->idsOf($appointments);
		$responses = $this->indexResponses($appointmentIds);
		$checkedIn = $this->appointmentsWithCheckins($appointmentIds);

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
			$isPast = $this->hasEnded($appointment);
			$countsForAttendance = $isPast && isset($checkedIn[$appointmentId]);

			if ($isPast) {
				$pastCount++;
			}
			if ($countsForAttendance) {
				$attendanceRecordedCount++;
			}

			$perAppointment = new StatisticsTally();
			foreach ($targets as $targetUserId) {
				$answer = $responses[$appointmentId][$targetUserId] ?? null;
				$response = $answer !== null ? $this->responseValue($answer['response']) : null;
				$checkin = $answer !== null ? $this->checkinState($answer['checkinState']) : null;

				$tallies[$targetUserId] ??= new StatisticsTally();
				$tallies[$targetUserId]->record($response, $checkin, $countsForAttendance);
				$perAppointment->record($response, $checkin, $countsForAttendance);
			}

			$categoryKey = $appointment->getCategoryId() ?? 0;
			if (!isset($byCategory[$categoryKey])) {
				$byCategory[$categoryKey] = [
					'categoryId' => $appointment->getCategoryId(),
					'appointmentCount' => 0,
					'tally' => new StatisticsTally(),
				];
			}
			$byCategory[$categoryKey]['appointmentCount']++;
			$byCategory[$categoryKey]['tally']->add($perAppointment);

			$timeline[] = [
				'appointmentId' => $appointmentId,
				'name' => $appointment->getName(),
				'startDatetime' => $this->startOf($appointment),
				'targetCount' => $perAppointment->targetCount,
				'yes' => $perAppointment->yes,
				'present' => $perAppointment->present,
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
	 * @return list<int>
	 */
	private function idsOf(array $appointments): array {
		$ids = [];
		foreach ($appointments as $appointment) {
			$ids[] = $appointment->getId();
		}
		return $ids;
	}

	/**
	 * @param list<int> $appointmentIds
	 * @return array<int, array<string, array{response: ?string, checkinState: ?string, comment?: ?string}>> appointmentId → userId → answer
	 */
	private function indexResponses(array $appointmentIds, ?string $userId = null, bool $withComments = false): array {
		$indexed = [];
		foreach ($this->responseMapper->findStatisticsRows($appointmentIds, $userId, $withComments) as $row) {
			$answer = [
				'response' => $row['response'],
				'checkinState' => $row['checkinState'],
			];
			if ($withComments) {
				$answer['comment'] = $row['comment'] ?? null;
			}
			$indexed[$row['appointmentId']][$row['userId']] = $answer;
		}

		return $indexed;
	}

	/**
	 * @param list<int> $appointmentIds
	 * @return array<int, true> appointment IDs whose check-in list was worked
	 */
	private function appointmentsWithCheckins(array $appointmentIds): array {
		return array_fill_keys($this->responseMapper->findAppointmentIdsWithCheckins($appointmentIds), true);
	}

	/**
	 * Everyone the appointment was addressed to, keyed by user ID.
	 *
	 * Mirrors VisibilityService::isUserTargetAttendee(), but resolves the
	 * audience once per appointment instead of once per candidate — over a
	 * thousand appointments the per-user check decodes the same JSON fields
	 * hundreds of thousands of times.
	 *
	 * @return array<array-key, true>
	 */
	private function targetUserIdSet(Appointment $appointment): array {
		$settings = $this->visibilityService->getVisibilitySettings($appointment);

		if ($settings['users'] === [] && $settings['groups'] === [] && $settings['teams'] === []) {
			return $this->openAudienceIdsCache ??= array_fill_keys(array_keys($this->openAudience($appointment)), true);
		}

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

		return $userIds;
	}

	/**
	 * @return list<string>
	 */
	private function targetUserIds(Appointment $appointment): array {
		// Numeric-string user IDs come back as int array keys (issue #63).
		return array_map('strval', array_keys($this->targetUserIdSet($appointment)));
	}

	/**
	 * The audience of an appointment nobody restricted: the whitelisted groups
	 * when an admin configured any, everyone otherwise. Identical for every
	 * such appointment, so it is resolved once per request.
	 *
	 * @return array<string, IUser>
	 */
	private function openAudience(Appointment $appointment): array {
		return $this->openAudienceCache ??= $this->visibilityService->getRelevantUsersForAppointment(
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
	 * Which sections each person shows up in. People in several whitelisted
	 * groups appear in each of them, exactly like the per-appointment summary —
	 * the totals row is what counts everyone once.
	 *
	 * Resolved per section rather than per person: with a whitelist configured
	 * the member lists are already loaded, so asking every person for their
	 * groups would be one database round trip per person for an answer that is
	 * a handful of lookups away.
	 *
	 * @param list<string> $userIds
	 * @return array<string, list<string>> userId → section IDs
	 */
	private function buildMembership(array $userIds, StatisticsFilter $filter): array {
		$membership = array_fill_keys($userIds, []);
		$known = array_fill_keys($userIds, true);

		foreach ($this->sectionMembers($filter) as $sectionId => $memberIds) {
			foreach ($memberIds as $memberId) {
				if (isset($known[$memberId])) {
					$membership[$memberId][] = (string)$sectionId;
				}
			}
		}

		foreach ($membership as $userId => $sections) {
			if ($sections === []) {
				$membership[$userId] = [self::SECTION_OTHERS];
			}
		}

		return $membership;
	}

	/**
	 * @return array<array-key, array<array-key, string>> sectionId → member user IDs
	 */
	private function sectionMembers(StatisticsFilter $filter): array {
		if ($filter->groupsByTeams()) {
			$members = [];
			foreach ($this->configService->getWhitelistedTeams() as $teamId) {
				$members[$teamId] = $this->visibilityService->getTeamMembers($teamId);
			}
			return $members;
		}

		$whitelisted = $this->configService->getWhitelistedGroups();
		if ($whitelisted !== []) {
			$members = [];
			foreach ($whitelisted as $groupId) {
				$members[$groupId] = array_keys($this->groupMembers($groupId));
			}
			return $members;
		}

		// No whitelist: every group is a section, so the group list has to come
		// from the people themselves. The Guests app's system group is hidden —
		// it would otherwise lump every guest into one section.
		$members = [];
		foreach (array_keys($this->openAudienceCache ?? []) as $userId) {
			$user = $this->userManager->get($userId);
			if ($user === null) {
				continue;
			}
			foreach ($this->groupManager->getUserGroupIds($user) as $groupId) {
				if (GuestService::isGuestsSystemGroup($groupId)) {
					continue;
				}
				$members[$groupId][] = $userId;
			}
		}

		return $members;
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
			$tally = $entry['tally'];
			$series[] = [
				'categoryId' => $categoryId,
				'displayName' => $categoryId !== null
					? ($names[$categoryId] ?? (string)$categoryId)
					: $this->l10n->t('Without category'),
				'appointmentCount' => $entry['appointmentCount'],
				'targetCount' => $tally->targetCount,
				'yes' => $tally->yes,
				'present' => $tally->present,
				'attendanceBase' => $tally->attendanceBase,
				'acceptRate' => StatisticsTally::rate($tally->yes, $tally->targetCount),
				'attendanceRate' => StatisticsTally::rate($tally->present, $tally->attendanceBase),
			];
		}

		usort($series, static fn (array $a, array $b): int => strcasecmp((string)$a['displayName'], (string)$b['displayName']));

		return $series;
	}

	private function hasEnded(Appointment $appointment): bool {
		$now = $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
		return $appointment->getEndDatetime() < $now;
	}

	private function startOf(Appointment $appointment): ?string {
		$serialized = $appointment->jsonSerialize();
		return isset($serialized['startDatetime']) ? (string)$serialized['startDatetime'] : null;
	}

	private function responseValue(?string $value): ?string {
		return in_array($value, ['yes', 'no', 'maybe'], true) ? $value : null;
	}

	private function checkinState(?string $state): ?string {
		return in_array($state, ['yes', 'no'], true) ? $state : null;
	}
}
