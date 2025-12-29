<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AppointmentAttachmentMapper;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Db\IcalToken;
use OCA\Attendance\Db\IcalTokenMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Security\ISecureRandom;

/**
 * Service for managing iCal feeds and tokens.
 */
class IcalService {
	private IcalTokenMapper $icalTokenMapper;
	private AppointmentMapper $appointmentMapper;
	private AppointmentAttachmentMapper $attachmentMapper;
	private AttendanceResponseMapper $responseMapper;
	private VisibilityService $visibilityService;
	private ISecureRandom $secureRandom;
	private IURLGenerator $urlGenerator;
	private IL10NFactory $l10nFactory;
	private IConfig $config;

	private const TOKEN_LENGTH = 64;
	private const TOKEN_CHARS = 'abcdef0123456789';

	public function __construct(
		IcalTokenMapper $icalTokenMapper,
		AppointmentMapper $appointmentMapper,
		AppointmentAttachmentMapper $attachmentMapper,
		AttendanceResponseMapper $responseMapper,
		VisibilityService $visibilityService,
		ISecureRandom $secureRandom,
		IURLGenerator $urlGenerator,
		IL10NFactory $l10nFactory,
		IConfig $config
	) {
		$this->icalTokenMapper = $icalTokenMapper;
		$this->appointmentMapper = $appointmentMapper;
		$this->attachmentMapper = $attachmentMapper;
		$this->responseMapper = $responseMapper;
		$this->visibilityService = $visibilityService;
		$this->secureRandom = $secureRandom;
		$this->urlGenerator = $urlGenerator;
		$this->l10nFactory = $l10nFactory;
		$this->config = $config;
	}

	/**
	 * Get existing token for user or create new one
	 */
	public function getOrCreateToken(string $userId): IcalToken {
		$existingToken = $this->icalTokenMapper->findByUserId($userId);
		if ($existingToken !== null) {
			return $existingToken;
		}

		return $this->createToken($userId);
	}

	/**
	 * Regenerate token for user (invalidates old token)
	 */
	public function regenerateToken(string $userId): IcalToken {
		$this->icalTokenMapper->deleteByUserId($userId);
		return $this->createToken($userId);
	}

	/**
	 * Validate token and return userId if valid
	 */
	public function validateToken(string $token): ?string {
		// Validate token format
		if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
			return null;
		}

		$icalToken = $this->icalTokenMapper->findByToken($token);
		if ($icalToken === null) {
			return null;
		}

		// Update last used timestamp
		$icalToken->setLastUsedAt(date('Y-m-d H:i:s'));
		$this->icalTokenMapper->update($icalToken);

		return $icalToken->getUserId();
	}

	/**
	 * Generate iCal feed content for user
	 */
	public function generateIcalFeed(string $userId): string {
		// Get user's language for translations
		$userLang = $this->config->getUserValue($userId, 'core', 'lang', 'en');
		$l = $this->l10nFactory->get('attendance', $userLang);

		// Get all appointments from last 150 days and upcoming
		$cutoffDate = (new \DateTime())->modify('-150 days')->format('Y-m-d H:i:s');
		$allAppointments = $this->appointmentMapper->findAll();

		// Filter appointments where user is target attendee and within date range
		$appointments = array_filter($allAppointments, function (Appointment $apt) use ($userId, $cutoffDate) {
			// Only active appointments
			if (!$apt->getIsActive()) {
				return false;
			}
			// Only within date range (start date >= cutoff)
			if ($apt->getStartDatetime() < $cutoffDate) {
				return false;
			}
			// Only if user is target attendee (no admin bypass)
			return $this->visibilityService->isUserTargetAttendee($apt, $userId);
		});

		// Get user's responses for all appointments
		$userResponses = [];
		try {
			$responses = $this->responseMapper->findByUser($userId);
			foreach ($responses as $response) {
				$userResponses[$response->getAppointmentId()] = $response;
			}
		} catch (\Exception $e) {
			// Continue with empty responses
		}

		// Build iCal content
		$domain = $this->urlGenerator->getAbsoluteURL('/');
		$domain = parse_url($domain, PHP_URL_HOST) ?? 'nextcloud';

		$output = "BEGIN:VCALENDAR\r\n";
		$output .= "VERSION:2.0\r\n";
		$output .= "PRODID:-//Nextcloud//Attendance App//EN\r\n";
		$output .= "CALSCALE:GREGORIAN\r\n";
		$output .= "METHOD:PUBLISH\r\n";
		$output .= "X-WR-CALNAME:" . $this->escapeIcalText($l->t('Attendance Appointments')) . "\r\n";

		foreach ($appointments as $appointment) {
			$response = $userResponses[$appointment->getId()] ?? null;
			$output .= $this->generateVEvent($appointment, $response, $userId, $l, $domain);
		}

		$output .= "END:VCALENDAR\r\n";

		return $output;
	}

	/**
	 * Get the feed URL for a user
	 */
	public function getFeedUrl(string $userId): string {
		$token = $this->getOrCreateToken($userId);
		return $this->urlGenerator->linkToRouteAbsolute('attendance.ical.feed', [
			'token' => $token->getToken()
		]);
	}

	/**
	 * Create a new token for user
	 */
	private function createToken(string $userId): IcalToken {
		$token = new IcalToken();
		$token->setUserId($userId);
		$token->setToken($this->secureRandom->generate(self::TOKEN_LENGTH, self::TOKEN_CHARS));
		$token->setCreatedAt(date('Y-m-d H:i:s'));

		return $this->icalTokenMapper->insert($token);
	}

	/**
	 * Generate VEVENT for an appointment
	 */
	private function generateVEvent(
		Appointment $appointment,
		$response,
		string $userId,
		$l,
		string $domain
	): string {
		$responseState = $response ? $response->getResponse() : null;
		$responseComment = $response ? $response->getComment() : null;

		// Translate response labels
		$responseLabels = [
			'yes' => $l->t('Yes'),
			'no' => $l->t('No'),
			'maybe' => $l->t('Maybe'),
			null => '?',
		];
		$responseLabel = $responseLabels[$responseState] ?? '?';

		// Determine iCal STATUS and TRANSP based on response
		// STATUS: CONFIRMED for all (the appointment itself is confirmed, only attendance varies)
		// TRANSP: OPAQUE (busy) for yes, TRANSPARENT (free) for no/maybe/none
		$status = 'CONFIRMED';
		$transpMap = [
			'yes' => 'OPAQUE',
			'no' => 'TRANSPARENT',
			'maybe' => 'TRANSPARENT',
			null => 'TRANSPARENT',
		];
		$transp = $transpMap[$responseState] ?? 'TRANSPARENT';

		// Build summary with response suffix
		$summary = $appointment->getName() . ' (' . $l->t('Me') . ': ' . $responseLabel . ')';

		// Build description (use double quotes for actual newlines)
		$descriptionParts = [];

		// Add appointment description if present
		$appointmentDescription = trim($appointment->getDescription() ?? '');
		if ($appointmentDescription !== '') {
			$descriptionParts[] = $appointmentDescription;
		}

		// Add response status
		if ($responseState === null) {
			$descriptionParts[] = $l->t('Please respond! We still need your answer.');
		} else {
			$responseText = $l->t('Your response') . ': ' . $responseLabel;
			if ($responseComment) {
				$responseText .= "\n" . $responseComment;
			}
			$descriptionParts[] = $responseText;
		}

		// Add link to respond
		$descriptionParts[] = $l->t('View or change your response') . ":\n" . $this->urlGenerator->linkToRouteAbsolute('attendance.page.index') . '#/appointment/' . $appointment->getId();

		$description = implode("\n\n", $descriptionParts);

		// Format dates
		$startDt = new \DateTime($appointment->getStartDatetime(), new \DateTimeZone('UTC'));
		$endDt = new \DateTime($appointment->getEndDatetime(), new \DateTimeZone('UTC'));
		$createdDt = new \DateTime($appointment->getCreatedAt(), new \DateTimeZone('UTC'));
		$updatedDt = new \DateTime($appointment->getUpdatedAt(), new \DateTimeZone('UTC'));

		// Track the latest modification time (appointment or response)
		$lastModifiedDt = $updatedDt;

		// Calculate SEQUENCE: tracks revision count for calendar apps to detect changes
		// 0 = base state, +1 for each type of change
		$sequence = 0;

		// +1 if appointment was modified after creation
		if ($updatedDt > $createdDt) {
			$sequence++;
		}

		// +1 if user has responded, and use respondedAt if it's newer
		if ($response !== null && $response->getRespondedAt()) {
			$sequence++;
			$respondedDt = new \DateTime($response->getRespondedAt(), new \DateTimeZone('UTC'));
			if ($respondedDt > $lastModifiedDt) {
				$lastModifiedDt = $respondedDt;
			}
		}

		// Build URL for direct access to appointment
		$appointmentUrl = $this->urlGenerator->linkToRouteAbsolute('attendance.page.index') . '#/appointment/' . $appointment->getId();

		$output = "BEGIN:VEVENT\r\n";
		$output .= "UID:attendance-appointment-" . $appointment->getId() . "@" . $domain . "\r\n";
		$output .= "DTSTAMP:" . $lastModifiedDt->format('Ymd\THis\Z') . "\r\n";
		$output .= "LAST-MODIFIED:" . $lastModifiedDt->format('Ymd\THis\Z') . "\r\n";
		$output .= "SEQUENCE:" . $sequence . "\r\n";
		$output .= "DTSTART:" . $startDt->format('Ymd\THis\Z') . "\r\n";
		$output .= "DTEND:" . $endDt->format('Ymd\THis\Z') . "\r\n";
		$output .= "SUMMARY:" . $this->escapeIcalText($summary) . "\r\n";
		$output .= "DESCRIPTION:" . $this->escapeIcalText($description) . "\r\n";
		$output .= "URL:" . $appointmentUrl . "\r\n";
		$output .= "STATUS:" . $status . "\r\n";
		$output .= "TRANSP:" . $transp . "\r\n";

		// Add attachments
		$attachments = $this->attachmentMapper->findByAppointment($appointment->getId());
		foreach ($attachments as $attachment) {
			$attachUrl = $this->urlGenerator->getAbsoluteURL('/f/' . $attachment->getFileId());
			$output .= "ATTACH:" . $attachUrl . "\r\n";
		}

		$output .= "END:VEVENT\r\n";

		return $output;
	}

	/**
	 * Escape text for iCal format (RFC 5545)
	 */
	private function escapeIcalText(string $text): string {
		// Escape backslashes first, then other special chars
		$text = str_replace('\\', '\\\\', $text);
		$text = str_replace(';', '\\;', $text);
		$text = str_replace(',', '\\,', $text);
		// Replace actual newlines with \n literal
		$text = str_replace(["\r\n", "\r", "\n"], '\\n', $text);
		return $text;
	}
}
