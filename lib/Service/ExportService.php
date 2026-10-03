<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Db\DatetimeFormatTrait;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * @psalm-import-type AttendanceExportSeries from \OCA\Attendance\ResponseDefinitions
 */
class ExportService {
	use DatetimeFormatTrait;

	private AppointmentMapper $appointmentMapper;
	private AttendanceResponseMapper $responseMapper;
	private IRootFolder $rootFolder;
	private IUserManager $userManager;
	private IGroupManager $groupManager;
	private ConfigService $configService;
	private VisibilityService $visibilityService;
	private OdsWriter $odsWriter;
	private IL10N $l10n;
	private CapacityService $capacityService;

	public function __construct(
		AppointmentMapper $appointmentMapper,
		AttendanceResponseMapper $responseMapper,
		IRootFolder $rootFolder,
		IUserManager $userManager,
		IGroupManager $groupManager,
		ConfigService $configService,
		VisibilityService $visibilityService,
		OdsWriter $odsWriter,
		IL10N $l10n,
		CapacityService $capacityService,
	) {
		$this->appointmentMapper = $appointmentMapper;
		$this->responseMapper = $responseMapper;
		$this->rootFolder = $rootFolder;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->configService = $configService;
		$this->visibilityService = $visibilityService;
		$this->odsWriter = $odsWriter;
		$this->l10n = $l10n;
		$this->capacityService = $capacityService;
	}

	/**
	 * Every series the export dialog can filter by.
	 *
	 * Ongoing series come first, soonest start first, so the ones people export
	 * from are at the top; finished series follow most-recent first, because a
	 * years-old series is the least likely pick in a list that only ever grows.
	 *
	 * @return list<AttendanceExportSeries>
	 */
	public function getSeriesOverview(): array {
		$now = gmdate('Y-m-d H:i:s');

		$overview = array_map(
			fn (array $entry): array => [
				'seriesId' => $entry['seriesId'],
				'name' => $entry['name'],
				'startDatetime' => (string)$this->formatDatetimeToUtc($entry['startDatetime']),
				'endDatetime' => (string)$this->formatDatetimeToUtc($entry['endDatetime']),
				'appointmentCount' => $entry['appointmentCount'],
				'ongoing' => $entry['endDatetime'] >= $now,
			],
			$this->appointmentMapper->findSeriesOverview(),
		);

		usort(
			$overview,
			/**
			 * @param AttendanceExportSeries $a
			 * @param AttendanceExportSeries $b
			 */
			static function (array $a, array $b): int {
				if ($a['ongoing'] !== $b['ongoing']) {
					return $a['ongoing'] ? -1 : 1;
				}

				return $a['ongoing']
					? strcmp($a['startDatetime'], $b['startDatetime'])
					: strcmp($b['startDatetime'], $a['startDatetime']);
			}
		);

		return $overview;
	}

	/**
	 * Which groups the column may name, lowercased, or null when it may name
	 * any of them.
	 *
	 * Mirrors the rule the response summary sections by
	 * (ResponseSummaryService::isGroupAllowedCached), so the sheet says what
	 * the overview shows. In order: the admin's group list decides when the
	 * mode is "specific"; otherwise the groups a restricted appointment names
	 * decide, because those are what let people in (issue #252); otherwise
	 * every group counts in "all" mode and none in "none".
	 *
	 * A run covering several appointments takes the union of their
	 * restrictions — one column cannot answer per appointment.
	 *
	 * @param list<Appointment> $appointments
	 * @param array<array-key, string> $whitelistedGroups
	 * @return ?list<string> null means "any group"
	 */
	private function nameableGroups(array $appointments, array $whitelistedGroups): ?array {
		if ($this->configService->getResponseSummaryGroupsMode() === ConfigService::RESPONSE_SUMMARY_MODE_SPECIFIC) {
			return array_values(array_map('strtolower', $whitelistedGroups));
		}

		$restrictionGroups = [];
		foreach ($appointments as $appointment) {
			// A numerically-named group decodes to an int out of the JSON column.
			$groups = array_map('strval', $this->visibilityService->getVisibilitySettings($appointment)['groups']);
			foreach ($groups as $groupId) {
				$restrictionGroups[strtolower($groupId)] = true;
			}
		}
		if ($restrictionGroups !== []) {
			return array_keys($restrictionGroups);
		}

		return $this->configService->getResponseSummaryGroupsMode() === ConfigService::RESPONSE_SUMMARY_MODE_ALL
			? null
			: [];
	}

	/**
	 * The group cell for one person: every group of theirs the column may
	 * name, comma-separated, because that is exactly the set of sections they
	 * appear under in the overview. "Others" when none qualifies — which is
	 * also what a person with no tracked group gets there.
	 *
	 * The Guests app's system group stays out unless an admin put it on the
	 * list, mirroring ResponseSummaryService::isGroupVisibleAsSection: every
	 * guest belongs to it, so it groups nothing.
	 *
	 * @param list<string> $userGroups
	 * @param ?list<string> $nameable Lowercased, or null for "any group"
	 */
	private function groupCell(array $userGroups, ?array $nameable): string {
		$named = array_values(array_filter(
			$userGroups,
			static fn (string $groupId): bool => (!GuestService::isGuestsSystemGroup($groupId)
					|| ($nameable !== null && in_array(GuestService::GUESTS_SYSTEM_GROUP, $nameable, true)))
				&& ($nameable === null || in_array(strtolower($groupId), $nameable, true)),
		));

		return $named === [] ? $this->l10n->t('Others') : implode(', ', $named);
	}

	/**
	 * Export appointments to an ODS file with optional filtering
	 *
	 * @param string $userId The user ID who is exporting
	 * @param ExportOptions $options What to export and which columns to give it
	 * @return array Array with 'path' and 'filename' keys
	 * @throws \Exception
	 */
	public function exportToOds(string $userId, ExportOptions $options): array {
		// Get appointments with filtering
		$appointments = $this->appointmentMapper->findForExport(
			$options->appointmentIds,
			$options->startDate,
			$options->endDate,
			$options->seriesIds,
		);

		if (empty($appointments)) {
			throw new \Exception('No appointments found to export');
		}

		// Sort appointments by start datetime
		usort($appointments, function ($a, $b) {
			return strcmp($a->getStartDatetime(), $b->getStartDatetime());
		});

		// Collect all unique users who have responded or checked in
		$allUserIds = [];
		$appointmentResponses = [];
		// Who actually holds a spot, per limited appointment. Resolved once here
		// rather than per cell: this is a users x appointments matrix, and the
		// queue is one query.
		$confirmedIds = [];

		foreach ($appointments as $appointment) {
			$responses = $this->responseMapper->findByAppointment($appointment->getId());
			$appointmentResponses[$appointment->getId()] = [];

			foreach ($responses as $response) {
				$respUserId = $response->getUserId();
				$allUserIds[$respUserId] = true;
				$appointmentResponses[$appointment->getId()][$respUserId] = $response;
			}

			if ($this->capacityService->limitOf($appointment) !== null) {
				$confirmed = $this->capacityService->split($appointment)['confirmed'];
				$confirmedIds[$appointment->getId()] = array_flip(
					array_map(static fn ($row): string => $row->getUserId(), $confirmed),
				);
			}
		}

		// Get user display names and groups
		$nameableGroups = $this->nameableGroups(
			$appointments,
			$this->configService->getWhitelistedGroups(),
		);
		$users = [];
		foreach (array_keys($allUserIds) as $uid) {
			$user = $this->userManager->get($uid);
			if ($user) {
				$users[] = [
					'userId' => $uid,
					'displayName' => $user->getDisplayName(),
					'group' => $this->groupCell(
						$this->groupManager->getUserGroupIds($user),
						$nameableGroups,
					),
				];
			}
		}

		// Sort users by display name
		usort($users, function ($a, $b) {
			return strcmp($a['displayName'], $b['displayName']);
		});

		$odsContent = $this->odsWriter->write($this->getTableXml($appointments, $users, $appointmentResponses, $options, $confirmedIds));

		// Create the Attendance folder
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Exception $e) {
			throw new \Exception('Failed to get user folder: ' . $e->getMessage());
		}

		try {
			$attendanceFolder = $userFolder->get('Attendance');
			if (!$attendanceFolder instanceof Folder) {
				throw new \Exception('Attendance path exists but is not a folder');
			}
		} catch (NotFoundException $e) {
			try {
				$attendanceFolder = $userFolder->newFolder('Attendance');
			} catch (\Exception $e) {
				throw new \Exception('Failed to create Attendance folder: ' . $e->getMessage());
			}
		}

		// Generate filename with timestamp and filter info
		$timestamp = date('Y-m-d_His');
		$filterSuffix = $this->generateFilenameSuffix($options);
		$filename = "attendance_export{$filterSuffix}_{$timestamp}.ods";

		// Check if file exists, if so, delete it
		if ($attendanceFolder->nodeExists($filename)) {
			try {
				$existingFile = $attendanceFolder->get($filename);
				$existingFile->delete();
			} catch (\Exception $e) {
				throw new \Exception('Failed to delete existing file: ' . $e->getMessage());
			}
		}

		// Create new file
		try {
			$file = $attendanceFolder->newFile($filename);
			$file->putContent($odsContent);
		} catch (\Exception $e) {
			throw new \Exception('Failed to create export file: ' . $e->getMessage());
		}

		return [
			'path' => '/Attendance/' . $filename,
			'filename' => $filename,
		];
	}

	/**
	 * The per-appointment columns one export run writes, in sheet order.
	 *
	 * Header label and cell content are modelled together so the label row and
	 * the data rows walk the same list — switching a column off then cannot
	 * leave the two out of step, and a further column is one entry rather than
	 * a branch in each loop.
	 *
	 * The bool handed to render says the person is only waiting for a spot.
	 *
	 * @return list<array{label: string, render: callable(?AttendanceResponse, bool): array{0: string, 1: string}}>
	 */
	private function columnsFor(ExportOptions $options): array {
		$columns = [];

		if ($options->includeRsvp) {
			$columns[] = [
				'label' => 'RSVP',
				'render' => function (?AttendanceResponse $response, bool $waitlisted): array {
					// "Yes" would be wrong on the sheet an organizer takes to the door. Amber
					// is unambiguous: an appointment with a limit never offers "Maybe".
					if ($waitlisted) {
						// TRANSLATORS Cell value in the exported spreadsheet — the person answered yes but the appointment was full, so they are waiting for a spot (German "Warteliste").
						return [OdsWriter::STYLE_MAYBE, $this->l10n->t('Waitlist')];
					}
					$value = $response?->getResponse();
					return [$this->getResponseCellStyle($value), $this->formatResponse($value)];
				},
			];
		}

		if ($options->includeCheckin) {
			$columns[] = [
				'label' => 'CheckIn',
				'render' => function (?AttendanceResponse $response): array {
					$value = $response?->getCheckinState();
					return [$this->getResponseCellStyle($value), $this->formatResponse($value)];
				},
			];
		}

		if ($options->includeComments) {
			$columns[] = [
				'label' => 'Comment',
				'render' => function (?AttendanceResponse $response): array {
					$comment = $response?->getComment() ?? '';
					return [OdsWriter::STYLE_CELL, $comment === '' ? '' : $this->odsWriter->escape($comment)];
				},
			];
		}

		return $columns;
	}

	/**
	 * Render the export sheet. The document around it comes from OdsWriter.
	 *
	 * @param list<Appointment> $appointments
	 * @param list<array{userId: string, displayName: string, group: string}> $users
	 * @param array<int, array<string, AttendanceResponse>> $appointmentResponses Keyed by appointment id, then user id
	 * @param array<int, array<string, int>> $confirmedIds user IDs holding a spot, per limited appointment
	 */
	private function getTableXml(array $appointments, array $users, array $appointmentResponses, ExportOptions $options, array $confirmedIds = []): string {
		$columns = $this->columnsFor($options);
		$span = count($columns);
		$leading = $options->leadingColumns();

		$xml = '
			<table:table table:name="Attendance" table:print="false">';

		// Add column definitions
		$xml .= '
				<table:table-column table:style-name="co1" table:number-columns-repeated="' . $leading . '"/>';
		$xml .= '
				<table:table-column table:style-name="co2" table:number-columns-repeated="' . (count($appointments) * $span) . '"/>';

		// Add first header row with appointment names only
		$xml .= '
				<table:table-row>
					<table:table-cell table:style-name="' . OdsWriter::STYLE_HEADER . '" office:value-type="string">
						<text:p>Name</text:p>
					</table:table-cell>'
			. ($options->includeGroup ? $this->plainCell(OdsWriter::STYLE_HEADER, $this->l10n->t('Group')) : '');

		foreach ($appointments as $appointment) {
			$xml .= $this->mergedCell(OdsWriter::STYLE_HEADER, $this->odsWriter->escape($appointment->getName()), $span);
		}

		$xml .= '
				</table:table-row>';

		// Add second header row with dates
		$xml .= '
				<table:table-row>'
			. str_repeat($this->plainCell(OdsWriter::STYLE_HEADER, ''), $leading);

		foreach ($appointments as $appointment) {
			$startDate = date('Y-m-d', strtotime($appointment->getStartDatetime()));
			$location = $appointment->getLocation();
			$dateLabel = $location !== null ? $startDate . ' · ' . $this->odsWriter->escape($location) : $startDate;

			$xml .= $this->mergedCell(OdsWriter::STYLE_HEADER, $dateLabel, $span);
		}

		$xml .= '
				</table:table-row>';

		// Add third header row with the labels of the columns that are switched on
		$xml .= '
				<table:table-row>'
			. str_repeat($this->plainCell(OdsWriter::STYLE_HEADER, ''), $leading);

		foreach ($appointments as $appointment) {
			foreach ($columns as $column) {
				$xml .= '
					<table:table-cell table:style-name="' . OdsWriter::STYLE_HEADER . '" office:value-type="string">
						<text:p>' . $column['label'] . '</text:p>
					</table:table-cell>';
			}
		}

		$xml .= '
				</table:table-row>';

		// Add data rows for each user
		foreach ($users as $user) {
			$xml .= '
				<table:table-row>
					<table:table-cell table:style-name="' . OdsWriter::STYLE_CELL . '" office:value-type="string">
						<text:p>' . $this->odsWriter->escape($user['displayName']) . '</text:p>
					</table:table-cell>'
				. ($options->includeGroup ? $this->plainCell(OdsWriter::STYLE_CELL, $this->odsWriter->escape($user['group'])) : '');

			foreach ($appointments as $appointment) {
				$response = $appointmentResponses[$appointment->getId()][$user['userId']] ?? null;

				$waitlisted = $response?->getResponse() === 'yes'
					&& isset($confirmedIds[$appointment->getId()])
					&& !isset($confirmedIds[$appointment->getId()][$user['userId']]);

				foreach ($columns as $column) {
					[$style, $content] = $column['render']($response, $waitlisted);
					$xml .= '
					<table:table-cell table:style-name="' . $style . '" office:value-type="string">
						<text:p>' . $content . '</text:p>
					</table:table-cell>';
				}
			}

			$xml .= '
				</table:table-row>';
		}

		if ($options->includeOrganizers) {
			$xml .= $this->getOrganizerRowXml($appointments, $span, $leading);
		}

		$xml .= '
			</table:table>';

		return $xml;
	}

	/**
	 * One plain cell. The identity columns are written through this so the four
	 * rows that start with them cannot drift apart when one is switched off.
	 */
	private function plainCell(string $style, string $text): string {
		return '
					<table:table-cell table:style-name="' . $style . '" office:value-type="string">
						<text:p>' . $text . '</text:p>
					</table:table-cell>';
	}

	/**
	 * A cell merged across one appointment's column group, followed by the
	 * covered cells the merge needs to stay aligned. A group of one is written
	 * as a plain cell: a span of 1 is a merge of nothing, and some readers
	 * baulk at it.
	 *
	 * @param string $escapedLabel Already run through OdsWriter::escape()
	 */
	private function mergedCell(string $style, string $escapedLabel, int $span): string {
		$spanAttribute = $span > 1 ? ' table:number-columns-spanned="' . $span . '"' : '';

		$xml = '
					<table:table-cell table:style-name="' . $style . '" office:value-type="string"' . $spanAttribute . '>
						<text:p>' . $escapedLabel . '</text:p>
					</table:table-cell>';

		for ($i = 1; $i < $span; $i++) {
			$xml .= '<table:covered-table-cell/>';
		}

		return $xml;
	}

	/**
	 * Closing row listing each appointment's organizers, comma-separated in one
	 * cell merged across that appointment's column group — the organizers
	 * belong to the appointment as a whole, not to any single column.
	 *
	 * @param list<Appointment> $appointments
	 */
	private function getOrganizerRowXml(array $appointments, int $span, int $leading): string {
		$xml = '
				<table:table-row>
					<table:table-cell table:style-name="' . OdsWriter::STYLE_HEADER . '" office:value-type="string">
						<text:p>' . $this->l10n->t('Organizers') . '</text:p>
					</table:table-cell>'
			. str_repeat($this->plainCell(OdsWriter::STYLE_CELL, ''), $leading - 1);

		foreach ($appointments as $appointment) {
			$names = $this->resolveOrganizerNames($appointment->getOrganizersList());

			$xml .= $this->mergedCell(OdsWriter::STYLE_CELL, $this->odsWriter->escape($names), $span);
		}

		return $xml . '
				</table:table-row>';
	}

	/**
	 * Organizer display names as one comma-separated string. A user ID that no
	 * longer resolves is kept verbatim rather than dropped, so a deleted
	 * account does not silently shorten the list.
	 *
	 * @param list<string> $organizerIds
	 */
	private function resolveOrganizerNames(array $organizerIds): string {
		$names = [];
		foreach ($organizerIds as $organizerId) {
			$user = $this->userManager->get($organizerId);
			$names[] = $user ? $user->getDisplayName() : $organizerId;
		}

		return implode(', ', $names);
	}

	/**
	 * Format response value for display (translated)
	 */
	private function formatResponse(?string $response): string {
		if ($response === null || $response === '') {
			return '-';
		}

		switch ($response) {
			case 'yes':
				return $this->l10n->t('Yes');
			case 'no':
				return $this->l10n->t('No');
			case 'maybe':
				return $this->l10n->t('Maybe');
			default:
				return $response;
		}
	}

	/**
	 * Get the cell style name based on response value
	 */
	private function getResponseCellStyle(?string $response): string {
		switch ($response) {
			case 'yes':
				return OdsWriter::STYLE_YES;
			case 'no':
				return OdsWriter::STYLE_NO;
			case 'maybe':
				return OdsWriter::STYLE_MAYBE;
			default:
				return OdsWriter::STYLE_CELL;
		}
	}

	/**
	 * Generate filename suffix based on filtering options
	 */
	private function generateFilenameSuffix(ExportOptions $options): string {
		if ($options->seriesIds !== null) {
			if (count($options->seriesIds) === 1) {
				return '_series';
			}
			return '_selected_' . count($options->seriesIds) . '_series';
		}

		if ($options->appointmentIds !== null) {
			if (count($options->appointmentIds) === 1) {
				return '_appointment_' . $options->appointmentIds[0];
			}
			return '_selected_' . count($options->appointmentIds) . '_appointments';
		}

		$startDate = $options->startDate;
		$endDate = $options->endDate;

		if ($startDate !== null && $endDate !== null) {
			return '_' . $startDate . '_to_' . $endDate;
		} elseif ($startDate !== null) {
			return '_from_' . $startDate;
		} elseif ($endDate !== null) {
			return '_until_' . $endDate;
		}

		return '_all';
	}
}
