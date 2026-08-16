<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;

/**
 * Writes the statistics table into the user's Attendance folder as an ODS
 * with two sheets: one row per person, one row per group. Numbers only —
 * the charts stay in the app.
 *
 * @psalm-import-type OdsCell from OdsWriter
 * @psalm-import-type StatsCounts from StatisticsService
 * @psalm-import-type StatsPerson from StatisticsService
 * @psalm-import-type StatsSection from StatisticsService
 */
class StatisticsExportService {
	public function __construct(
		private StatisticsService $statisticsService,
		private OdsWriter $odsWriter,
		private IRootFolder $rootFolder,
		private IL10N $l10n,
	) {
	}

	/**
	 * @return array{path: string, filename: string}
	 * @throws StatisticsRangeException
	 * @throws \Exception
	 */
	public function exportToOds(string $userId, StatisticsFilter $filter): array {
		$statistics = $this->statisticsService->getStatistics($filter);

		if ($statistics['people'] === []) {
			throw new \Exception('No statistics found to export');
		}

		/** @var array<string, string> $sectionNames */
		$sectionNames = [];
		foreach ($statistics['sections'] as $section) {
			$sectionNames[$section['id']] = $section['displayName'];
		}

		$scheduling = $statistics['schedulingEnabled'];

		$content = $this->odsWriter->write(
			$this->odsWriter->renderTable(
				// TRANSLATORS Name of the spreadsheet sheet holding one row per person. The same word labels the person-count column on the other sheet.
				$this->l10n->t('People'),
				$this->peopleRows($statistics['people'], $statistics['totals'], $sectionNames, $scheduling),
			)
			. $this->odsWriter->renderTable(
				$this->l10n->t('Groups'),
				$this->sectionRows($statistics['sections'], $statistics['totals'], $scheduling),
			),
		);

		return $this->store($userId, $content, $filter);
	}

	/**
	 * @param list<StatsPerson> $people
	 * @param StatsCounts $totals
	 * @param array<string, string> $sectionNames
	 * @return list<list<OdsCell>>
	 */
	private function peopleRows(array $people, array $totals, array $sectionNames, bool $scheduling): array {
		$rows = [$this->header([$this->l10n->t('Name'), $this->l10n->t('Groups')], $scheduling)];

		foreach ($people as $person) {
			$names = array_map(
				static fn (string $id): string => $sectionNames[$id] ?? $id,
				$person['sections'],
			);
			$rows[] = array_merge(
				[$person['displayName'], implode(', ', $names)],
				$this->counts($scheduling, $person),
			);
		}

		$rows[] = array_merge(
			[['value' => $this->l10n->t('Total'), 'style' => OdsWriter::STYLE_SECTION], ''],
			$this->counts($scheduling, $totals),
		);

		return $rows;
	}

	/**
	 * @param list<StatsSection> $sections
	 * @param StatsCounts $totals
	 * @return list<list<OdsCell>>
	 */
	private function sectionRows(array $sections, array $totals, bool $scheduling): array {
		// TRANSLATORS "People" here is the column counting how many people are in the group, and also names the other sheet.
		$rows = [$this->header([$this->l10n->t('Group'), $this->l10n->t('People')], $scheduling)];

		foreach ($sections as $section) {
			$rows[] = array_merge(
				[$section['displayName'], ['value' => $section['personCount'], 'type' => 'float']],
				$this->counts($scheduling, $section),
			);
		}

		$rows[] = array_merge(
			[['value' => $this->l10n->t('Total'), 'style' => OdsWriter::STYLE_SECTION], ''],
			$this->counts($scheduling, $totals),
		);

		return $rows;
	}

	/**
	 * Kept in the same shape as counts() below — the two are positional lists
	 * that have to stay index-aligned, and matching notation is what makes a
	 * misalignment visible while editing.
	 *
	 * @param list<string> $leading
	 * @return list<OdsCell>
	 */
	private function header(array $leading, bool $scheduling): array {
		$labels = [
			...$leading,
			$this->l10n->t('Appointments'),
			$this->l10n->t('Yes'),
			$this->l10n->t('No'),
			$this->l10n->t('Maybe'),
			$this->l10n->t('No response'),
			$this->l10n->t('Present'),
			$this->l10n->t('Absent'),
			$this->l10n->t('Not recorded'),
			$this->l10n->t('No-show'),
			...($scheduling ? [
				$this->l10n->t('Scheduled'),
				// TRANSLATORS: Column header — the person accepted and the inquiry was closed, but they did not get a place.
				$this->l10n->t('Not scheduled'),
			] : []),
			$this->l10n->t('Response rate'),
			$this->l10n->t('Acceptance rate'),
			$this->l10n->t('Attendance rate'),
			...($scheduling ? [
				// TRANSLATORS: Column header — share of the person's acceptances that got a place, counted only over closed inquiries where somebody was scheduled.
				$this->l10n->t('Scheduling rate'),
			] : []),
		];

		return array_map(
			static fn (string $label): array => ['value' => $label, 'style' => OdsWriter::STYLE_HEADER],
			$labels,
		);
	}

	/**
	 * @param StatsCounts|StatsPerson|StatsSection $counts
	 * @return list<OdsCell>
	 */
	private function counts(bool $scheduling, array $counts): array {
		return [
			['value' => $counts['targetCount'], 'type' => 'float'],
			['value' => $counts['yes'], 'type' => 'float'],
			['value' => $counts['no'], 'type' => 'float'],
			['value' => $counts['maybe'], 'type' => 'float'],
			['value' => $counts['noResponse'], 'type' => 'float'],
			['value' => $counts['present'], 'type' => 'float'],
			['value' => $counts['absent'], 'type' => 'float'],
			['value' => $counts['notRecorded'], 'type' => 'float'],
			['value' => $counts['noShow'], 'type' => 'float'],
			...($scheduling ? [
				['value' => $counts['scheduled'], 'type' => 'float'],
				['value' => $counts['notScheduled'], 'type' => 'float'],
			] : []),
			['value' => $counts['responseRate'], 'type' => 'percentage'],
			['value' => $counts['acceptRate'], 'type' => 'percentage'],
			['value' => $counts['attendanceRate'], 'type' => 'percentage'],
			...($scheduling ? [
				['value' => $counts['scheduledRate'], 'type' => 'percentage'],
			] : []),
		];
	}

	/**
	 * @return array{path: string, filename: string}
	 */
	private function store(string $userId, string $content, StatisticsFilter $filter): array {
		$folder = $this->attendanceFolder($userId);

		$range = trim(($filter->startDate ?? '') . '_' . ($filter->endDate ?? ''), '_');
		$filename = 'attendance_statistics'
			. ($range !== '' ? '_' . $range : '')
			. '_' . date('Y-m-d_His') . '.ods';

		if ($folder->nodeExists($filename)) {
			$folder->get($filename)->delete();
		}

		$folder->newFile($filename)->putContent($content);

		return [
			'path' => '/Attendance/' . $filename,
			'filename' => $filename,
		];
	}

	private function attendanceFolder(string $userId): Folder {
		try {
			/** @var Folder $userFolder */
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Exception $e) {
			throw new \Exception('Failed to get user folder: ' . $e->getMessage());
		}

		try {
			$folder = $userFolder->get('Attendance');
		} catch (NotFoundException $e) {
			return $userFolder->newFolder('Attendance');
		}

		if (!$folder instanceof Folder) {
			throw new \Exception('Attendance path exists but is not a folder');
		}

		return $folder;
	}
}
