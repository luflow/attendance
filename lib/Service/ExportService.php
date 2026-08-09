<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;

class ExportService {
	private AppointmentMapper $appointmentMapper;
	private AttendanceResponseMapper $responseMapper;
	private IRootFolder $rootFolder;
	private IUserManager $userManager;
	private IGroupManager $groupManager;
	private ConfigService $configService;
	private OdsWriter $odsWriter;
	private IL10N $l10n;

	public function __construct(
		AppointmentMapper $appointmentMapper,
		AttendanceResponseMapper $responseMapper,
		IRootFolder $rootFolder,
		IUserManager $userManager,
		IGroupManager $groupManager,
		ConfigService $configService,
		OdsWriter $odsWriter,
		IL10N $l10n,
	) {
		$this->appointmentMapper = $appointmentMapper;
		$this->responseMapper = $responseMapper;
		$this->rootFolder = $rootFolder;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->configService = $configService;
		$this->odsWriter = $odsWriter;
		$this->l10n = $l10n;
	}

	/**
	 * Export appointments to an ODS file with optional filtering
	 *
	 * @param string $userId The user ID who is exporting
	 * @param array|null $appointmentIds Specific appointment IDs to export (null for all)
	 * @param string|null $startDate Start date filter (Y-m-d format, inclusive)
	 * @param string|null $endDate End date filter (Y-m-d format, inclusive)
	 * @param bool $includeComments Whether to include user comments in the export
	 * @return array Array with 'path' and 'filename' keys
	 * @throws \Exception
	 */
	public function exportToOds(string $userId, ?array $appointmentIds = null, ?string $startDate = null, ?string $endDate = null, bool $includeComments = false): array {
		// Get appointments with filtering
		$appointments = $this->appointmentMapper->findForExport($appointmentIds, $startDate, $endDate);

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

		foreach ($appointments as $appointment) {
			$responses = $this->responseMapper->findByAppointment($appointment->getId());
			$appointmentResponses[$appointment->getId()] = [];

			foreach ($responses as $response) {
				$respUserId = $response->getUserId();
				$allUserIds[$respUserId] = true;
				$appointmentResponses[$appointment->getId()][$respUserId] = $response;
			}
		}

		// Get user display names and groups
		$whitelistedGroups = $this->configService->getWhitelistedGroups();
		$users = [];
		foreach (array_keys($allUserIds) as $uid) {
			$user = $this->userManager->get($uid);
			if ($user) {
				// Get user's groups
				$userGroups = $this->groupManager->getUserGroupIds($user);

				// Find first whitelisted group or use "Others"
				$userGroup = $this->l10n->t('Others');
				if (!empty($whitelistedGroups)) {
					foreach ($userGroups as $groupId) {
						if (in_array($groupId, $whitelistedGroups)) {
							$userGroup = $groupId;
							break;
						}
					}
				} else {
					// If no whitelist, use first group or "Others"
					$userGroup = !empty($userGroups) ? $userGroups[0] : $this->l10n->t('Others');
				}

				$users[] = [
					'userId' => $uid,
					'displayName' => $user->getDisplayName(),
					'group' => $userGroup,
				];
			}
		}

		// Sort users by display name
		usort($users, function ($a, $b) {
			return strcmp($a['displayName'], $b['displayName']);
		});

		$odsContent = $this->odsWriter->write($this->getTableXml($appointments, $users, $appointmentResponses, $includeComments));

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
		$filterSuffix = $this->generateFilenameSuffix($appointmentIds, $startDate, $endDate);
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
	 * Render the export sheet. The document around it comes from OdsWriter.
	 */
	private function getTableXml(array $appointments, array $users, array $appointmentResponses, bool $includeComments = false): string {
		$xml = '
			<table:table table:name="Attendance" table:print="false">';

		// Calculate number of columns: 2 for Name+Group + (2 or 3 * number of appointments)
		// Each appointment has RSVP + CheckIn columns, plus Comment if comments enabled
		$columnsPerAppointment = $includeComments ? 3 : 2;
		$columnCount = 2 + (count($appointments) * $columnsPerAppointment);

		// Add column definitions
		$xml .= '
				<table:table-column table:style-name="co1" table:number-columns-repeated="2"/>';
		$xml .= '
				<table:table-column table:style-name="co2" table:number-columns-repeated="' . (count($appointments) * $columnsPerAppointment) . '"/>';

		// Add first header row with appointment names only
		$xml .= '
				<table:table-row>
					<table:table-cell table:style-name="' . OdsWriter::STYLE_HEADER . '" office:value-type="string">
						<text:p>Name</text:p>
					</table:table-cell>
					<table:table-cell table:style-name="' . OdsWriter::STYLE_HEADER . '" office:value-type="string">
						<text:p>' . $this->l10n->t('Group') . '</text:p>
					</table:table-cell>';

		foreach ($appointments as $appointment) {
			$appointmentName = $this->odsWriter->escape($appointment->getName());

			// Add merged cell for appointment name spanning correct number of columns
			$xml .= '
					<table:table-cell table:style-name="' . OdsWriter::STYLE_HEADER . '" office:value-type="string" table:number-columns-spanned="' . $columnsPerAppointment . '">
						<text:p>' . $appointmentName . '</text:p>
					</table:table-cell>';

			// Add covered cells for the spanned columns
			for ($i = 1; $i < $columnsPerAppointment; $i++) {
				$xml .= '<table:covered-table-cell/>';
			}
		}

		$xml .= '
				</table:table-row>';

		// Add second header row with dates
		$xml .= '
				<table:table-row>
					<table:table-cell table:style-name="' . OdsWriter::STYLE_HEADER . '" office:value-type="string">
						<text:p></text:p>
					</table:table-cell>
					<table:table-cell table:style-name="' . OdsWriter::STYLE_HEADER . '" office:value-type="string">
						<text:p></text:p>
					</table:table-cell>';

		foreach ($appointments as $appointment) {
			$startDate = date('Y-m-d', strtotime($appointment->getStartDatetime()));
			$location = $appointment->getLocation();
			$dateLabel = $location !== null ? $startDate . ' · ' . $this->odsWriter->escape($location) : $startDate;

			// Add date (+ location, when set) merged cell spanning correct number of columns
			$xml .= '
					<table:table-cell table:style-name="' . OdsWriter::STYLE_HEADER . '" office:value-type="string" table:number-columns-spanned="' . $columnsPerAppointment . '">
						<text:p>' . $dateLabel . '</text:p>
					</table:table-cell>';

			// Add covered cells for the spanned columns
			for ($i = 1; $i < $columnsPerAppointment; $i++) {
				$xml .= '<table:covered-table-cell/>';
			}
		}

		$xml .= '
				</table:table-row>';

		// Add third header row with RSVP and CheckIn labels
		$xml .= '
				<table:table-row>
					<table:table-cell table:style-name="' . OdsWriter::STYLE_HEADER . '" office:value-type="string">
						<text:p></text:p>
					</table:table-cell>
					<table:table-cell table:style-name="' . OdsWriter::STYLE_HEADER . '" office:value-type="string">
						<text:p></text:p>
					</table:table-cell>';

		foreach ($appointments as $appointment) {
			$xml .= '
					<table:table-cell table:style-name="' . OdsWriter::STYLE_HEADER . '" office:value-type="string">
						<text:p>RSVP</text:p>
					</table:table-cell>
					<table:table-cell table:style-name="' . OdsWriter::STYLE_HEADER . '" office:value-type="string">
						<text:p>CheckIn</text:p>
					</table:table-cell>';

			if ($includeComments) {
				$xml .= '
					<table:table-cell table:style-name="' . OdsWriter::STYLE_HEADER . '" office:value-type="string">
						<text:p>Comment</text:p>
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
					</table:table-cell>
					<table:table-cell table:style-name="' . OdsWriter::STYLE_CELL . '" office:value-type="string">
						<text:p>' . $this->odsWriter->escape($user['group']) . '</text:p>
					</table:table-cell>';

			// Add RSVP, CheckIn, and optionally Comment data for each appointment
			foreach ($appointments as $appointment) {
				$response = $appointmentResponses[$appointment->getId()][$user['userId']] ?? null;

				// RSVP column
				$rsvpValue = $response ? $response->getResponse() : null;
				$rsvp = $this->formatResponse($rsvpValue);
				$rsvpStyle = $this->getResponseCellStyle($rsvpValue);
				$xml .= '
					<table:table-cell table:style-name="' . $rsvpStyle . '" office:value-type="string">
						<text:p>' . $rsvp . '</text:p>
					</table:table-cell>';

				// CheckIn column
				$checkinValue = $response ? $response->getCheckinState() : null;
				$checkin = $this->formatResponse($checkinValue);
				$checkinStyle = $this->getResponseCellStyle($checkinValue);
				$xml .= '
					<table:table-cell table:style-name="' . $checkinStyle . '" office:value-type="string">
						<text:p>' . $checkin . '</text:p>
					</table:table-cell>';

				// Comment column (if enabled)
				if ($includeComments) {
					$comment = $response && $response->getComment() ? $this->odsWriter->escape($response->getComment()) : '';
					$xml .= '
					<table:table-cell table:style-name="' . OdsWriter::STYLE_CELL . '" office:value-type="string">
						<text:p>' . $comment . '</text:p>
					</table:table-cell>';
				}
			}

			$xml .= '
				</table:table-row>';
		}

		$xml .= '
			</table:table>';

		return $xml;
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
	private function generateFilenameSuffix(?array $appointmentIds, ?string $startDate, ?string $endDate): string {
		if ($appointmentIds !== null && !empty($appointmentIds)) {
			if (count($appointmentIds) === 1) {
				return '_appointment_' . $appointmentIds[0];
			}
			return '_selected_' . count($appointmentIds) . '_appointments';
		}

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
