<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;

class ExportService {
	private AppointmentMapper $appointmentMapper;
	private AttendanceResponseMapper $responseMapper;
	private IRootFolder $rootFolder;
	private IUserManager $userManager;
	private IGroupManager $groupManager;
	private IConfig $config;
	private IL10N $l10n;

	public function __construct(
		AppointmentMapper $appointmentMapper,
		AttendanceResponseMapper $responseMapper,
		IRootFolder $rootFolder,
		IUserManager $userManager,
		IGroupManager $groupManager,
		IConfig $config,
		IL10N $l10n,
	) {
		$this->appointmentMapper = $appointmentMapper;
		$this->responseMapper = $responseMapper;
		$this->rootFolder = $rootFolder;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->config = $config;
		$this->l10n = $l10n;
	}

	/**
	 * Get whitelisted groups from app config
	 */
	private function getWhitelistedGroups(): array {
		$groupsJson = $this->config->getAppValue('attendance', 'whitelisted_groups', '[]');
		return json_decode($groupsJson, true) ?: [];
	}

	/**
	 * Export all appointments to an ODS file
	 *
	 * @param string $userId The user ID who is exporting
	 * @return array Array with 'path' and 'filename' keys
	 * @throws \Exception
	 */
	public function exportToOds(string $userId): array {
		// Get all appointments
		$appointments = $this->appointmentMapper->findAll();

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
		$whitelistedGroups = $this->getWhitelistedGroups();
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

		// Generate ODS content
		$odsContent = $this->generateOdsContent($appointments, $users, $appointmentResponses);

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

		// Generate filename with timestamp
		$timestamp = date('Y-m-d_His');
		$filename = "attendance_export_{$timestamp}.ods";

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
	 * Generate ODS file content
	 *
	 * @param array $appointments Array of Appointment entities
	 * @param array $users Array of user data
	 * @param array $appointmentResponses Map of appointment ID to user responses
	 * @return string Binary ODS content
	 */
	private function generateOdsContent(array $appointments, array $users, array $appointmentResponses): string {
		// Check if ZipArchive extension is available
		if (!class_exists('\ZipArchive')) {
			throw new \Exception('ZipArchive extension is not available. Please install php-zip extension.');
		}

		// Create temporary file for the ODS
		$tempFile = tempnam(sys_get_temp_dir(), 'ods_');
		if ($tempFile === false) {
			throw new \Exception('Failed to create temporary file');
		}

		$zip = new \ZipArchive();
		$result = $zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		if ($result !== true) {
			throw new \Exception('Failed to create ODS file. ZipArchive error code: ' . $result);
		}

		// Add mimetype (must be first and uncompressed)
		$zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
		$zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);

		// Add META-INF/manifest.xml
		$zip->addFromString('META-INF/manifest.xml', $this->getManifestXml());

		// Add content.xml with the table
		$zip->addFromString('content.xml', $this->getContentXml($appointments, $users, $appointmentResponses));

		// Add styles.xml
		$zip->addFromString('styles.xml', $this->getStylesXml());

		// Add meta.xml
		$zip->addFromString('meta.xml', $this->getMetaXml());

		$zip->close();

		// Read the file content
		$content = file_get_contents($tempFile);
		if ($content === false) {
			throw new \Exception('Failed to read generated ODS file');
		}

		unlink($tempFile);

		return $content;
	}

	/**
	 * Get manifest.xml content
	 */
	private function getManifestXml(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>
<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">
	<manifest:file-entry manifest:full-path="/" manifest:version="1.2" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/>
	<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>
	<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>
	<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>
</manifest:manifest>';
	}

	/**
	 * Get meta.xml content
	 */
	private function getMetaXml(): string {
		$date = date('Y-m-d\TH:i:s');
		return '<?xml version="1.0" encoding="UTF-8"?>
<office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" 
	xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" 
	xmlns:dc="http://purl.org/dc/elements/1.1/" 
	office:version="1.2">
	<office:meta>
		<meta:generator>Nextcloud Attendance App</meta:generator>
		<dc:title>Attendance Export</dc:title>
		<dc:date>' . $date . '</dc:date>
	</office:meta>
</office:document-meta>';
	}

	/**
	 * Get styles.xml content
	 */
	private function getStylesXml(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>
<office:document-styles xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" 
	xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" 
	xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" 
	xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"
	xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"
	office:version="1.2">
	<office:font-face-decls>
		<style:font-face style:name="Liberation Sans" svg:font-family="&apos;Liberation Sans&apos;" style:font-family-generic="swiss" style:font-pitch="variable"/>
	</office:font-face-decls>
	<office:styles>
		<style:default-style style:family="table-cell">
			<style:text-properties style:font-name="Liberation Sans" fo:font-size="11pt"/>
		</style:default-style>
	</office:styles>
</office:document-styles>';
	}

	/**
	 * Get content.xml with the table
	 */
	private function getContentXml(array $appointments, array $users, array $appointmentResponses): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>
<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" 
	xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" 
	xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" 
	xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" 
	xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" 
	xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"
	office:version="1.2">
	<office:font-face-decls>
		<style:font-face style:name="Liberation Sans" svg:font-family="&apos;Liberation Sans&apos;" style:font-family-generic="swiss" style:font-pitch="variable"/>
	</office:font-face-decls>
	<office:automatic-styles>
		<style:style style:name="co1" style:family="table-column">
			<style:table-column-properties style:column-width="3cm"/>
		</style:style>
		<style:style style:name="co2" style:family="table-column">
			<style:table-column-properties style:column-width="1.7cm"/>
		</style:style>
		<style:style style:name="ce1" style:family="table-cell">
			<style:table-cell-properties fo:border="0.05pt solid #000000"/>
			<style:text-properties style:font-name="Liberation Sans" fo:font-size="11pt"/>
		</style:style>
		<style:style style:name="ce2" style:family="table-cell">
			<style:table-cell-properties fo:border="0.05pt solid #000000" fo:background-color="#e0e0e0"/>
			<style:text-properties style:font-name="Liberation Sans" fo:font-size="11pt" fo:font-weight="bold"/>
		</style:style>
	</office:automatic-styles>
	<office:body>
		<office:spreadsheet>
			<table:table table:name="Attendance" table:print="false">';

		// Calculate number of columns: 2 for Name+Group + (2 * number of appointments)
		$columnCount = 2 + (count($appointments) * 2);

		// Add column definitions
		$xml .= '
				<table:table-column table:style-name="co1" table:number-columns-repeated="2"/>';
		$xml .= '
				<table:table-column table:style-name="co2" table:number-columns-repeated="' . (count($appointments) * 2) . '"/>';

		// Add first header row with appointment names only
		$xml .= '
				<table:table-row>
					<table:table-cell table:style-name="ce2" office:value-type="string">
						<text:p>Name</text:p>
					</table:table-cell>
					<table:table-cell table:style-name="ce2" office:value-type="string">
						<text:p>' . $this->l10n->t('Group') . '</text:p>
					</table:table-cell>';

		foreach ($appointments as $appointment) {
			$appointmentName = $this->escapeXml($appointment->getName());

			// Add merged cell for appointment name spanning 2 columns
			$xml .= '
					<table:table-cell table:style-name="ce2" office:value-type="string" table:number-columns-spanned="2">
						<text:p>' . $appointmentName . '</text:p>
					</table:table-cell>
					<table:covered-table-cell/>';
		}

		$xml .= '
				</table:table-row>';

		// Add second header row with dates
		$xml .= '
				<table:table-row>
					<table:table-cell table:style-name="ce2" office:value-type="string">
						<text:p></text:p>
					</table:table-cell>
					<table:table-cell table:style-name="ce2" office:value-type="string">
						<text:p></text:p>
					</table:table-cell>';

		foreach ($appointments as $appointment) {
			$startDate = date('Y-m-d', strtotime($appointment->getStartDatetime()));

			// Add date merged cell spanning 2 columns
			$xml .= '
					<table:table-cell table:style-name="ce2" office:value-type="string" table:number-columns-spanned="2">
						<text:p>' . $startDate . '</text:p>
					</table:table-cell>
					<table:covered-table-cell/>';
		}

		$xml .= '
				</table:table-row>';

		// Add third header row with RSVP and CheckIn labels
		$xml .= '
				<table:table-row>
					<table:table-cell table:style-name="ce2" office:value-type="string">
						<text:p></text:p>
					</table:table-cell>
					<table:table-cell table:style-name="ce2" office:value-type="string">
						<text:p></text:p>
					</table:table-cell>';

		foreach ($appointments as $appointment) {
			$xml .= '
					<table:table-cell table:style-name="ce2" office:value-type="string">
						<text:p>RSVP</text:p>
					</table:table-cell>
					<table:table-cell table:style-name="ce2" office:value-type="string">
						<text:p>CheckIn</text:p>
					</table:table-cell>';
		}

		$xml .= '
				</table:table-row>';

		// Add data rows for each user
		foreach ($users as $user) {
			$xml .= '
				<table:table-row>
					<table:table-cell table:style-name="ce1" office:value-type="string">
						<text:p>' . $this->escapeXml($user['displayName']) . '</text:p>
					</table:table-cell>
					<table:table-cell table:style-name="ce1" office:value-type="string">
						<text:p>' . $this->escapeXml($user['group']) . '</text:p>
					</table:table-cell>';

			// Add RSVP and CheckIn data for each appointment
			foreach ($appointments as $appointment) {
				$response = $appointmentResponses[$appointment->getId()][$user['userId']] ?? null;

				// RSVP column
				$rsvp = $response ? $this->formatResponse($response->getResponse()) : '-';
				$xml .= '
					<table:table-cell table:style-name="ce1" office:value-type="string">
						<text:p>' . $rsvp . '</text:p>
					</table:table-cell>';

				// CheckIn column
				$checkin = $response ? $this->formatResponse($response->getCheckinState()) : '-';
				$xml .= '
					<table:table-cell table:style-name="ce1" office:value-type="string">
						<text:p>' . $checkin . '</text:p>
					</table:table-cell>';
			}

			$xml .= '
				</table:table-row>';
		}

		$xml .= '
			</table:table>
		</office:spreadsheet>
	</office:body>
</office:document-content>';

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
	 * Escape XML special characters
	 */
	private function escapeXml(string $text): string {
		return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}
