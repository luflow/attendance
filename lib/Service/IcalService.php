<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentAttachmentMapper;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Db\CategoryMapper;
use OCA\Attendance\Db\IcalToken;
use OCA\Attendance\Db\IcalTokenMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IL10N;
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
	private ConfigService $configService;
	private BookingService $bookingService;
	private ISecureRandom $secureRandom;
	private IURLGenerator $urlGenerator;
	private IL10NFactory $l10nFactory;
	private IConfig $config;
	private CategoryMapper $categoryMapper;
	private CapacityService $capacityService;

	/** @var array<int, ?string> A feed names the same few categories on every event */
	private array $categoryNames = [];

	private const TOKEN_LENGTH = 64;
	private const TOKEN_CHARS = 'abcdef0123456789';

	public function __construct(
		IcalTokenMapper $icalTokenMapper,
		AppointmentMapper $appointmentMapper,
		AppointmentAttachmentMapper $attachmentMapper,
		AttendanceResponseMapper $responseMapper,
		VisibilityService $visibilityService,
		ConfigService $configService,
		BookingService $bookingService,
		ISecureRandom $secureRandom,
		IURLGenerator $urlGenerator,
		IL10NFactory $l10nFactory,
		IConfig $config,
		CategoryMapper $categoryMapper,
		CapacityService $capacityService,
		private TimezoneService $timezoneService,
		private PermissionService $permissionService,
		private ResponseSummaryService $responseSummaryService,
	) {
		$this->icalTokenMapper = $icalTokenMapper;
		$this->appointmentMapper = $appointmentMapper;
		$this->attachmentMapper = $attachmentMapper;
		$this->responseMapper = $responseMapper;
		$this->visibilityService = $visibilityService;
		$this->configService = $configService;
		$this->bookingService = $bookingService;
		$this->secureRandom = $secureRandom;
		$this->urlGenerator = $urlGenerator;
		$this->l10nFactory = $l10nFactory;
		$this->config = $config;
		$this->categoryMapper = $categoryMapper;
		$this->capacityService = $capacityService;
	}

	/**
	 * Get the existing token of one of a user's feeds or create a new one
	 *
	 * @param string $kind One of IcalToken::KINDS
	 */
	public function getOrCreateToken(string $userId, string $kind): IcalToken {
		$existingToken = $this->icalTokenMapper->findByUserId($userId, $kind);
		if ($existingToken !== null) {
			return $existingToken;
		}

		return $this->createToken($userId, $kind);
	}

	/**
	 * Regenerate the token of one of a user's feeds (invalidates the old one)
	 *
	 * @param string $kind One of IcalToken::KINDS
	 */
	public function regenerateToken(string $userId, string $kind): IcalToken {
		$this->icalTokenMapper->deleteByUserId($userId, $kind);
		return $this->createToken($userId, $kind);
	}

	/**
	 * Validate a token and return it, so the caller knows whose feed of which
	 * kind it unlocks
	 */
	public function validateToken(string $token): ?IcalToken {
		// Validate token format
		if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
			return null;
		}

		$icalToken = $this->icalTokenMapper->findByToken($token);
		if ($icalToken === null) {
			return null;
		}

		$icalToken->setLastUsedAt(gmdate('Y-m-d H:i:s'));
		$this->icalTokenMapper->update($icalToken);

		return $icalToken;
	}

	/**
	 * Generate the feed a validated token unlocks
	 */
	public function generateFeedFor(IcalToken $token): string {
		return $token->getKind() === IcalToken::KIND_ORGANIZER
			? $this->generateOrganizerFeed($token->getUserId())
			: $this->generateIcalFeed($token->getUserId());
	}

	/**
	 * What a feed reaches back to: the last 150 days and everything upcoming.
	 *
	 * @return list<Appointment>
	 */
	private function feedCandidates(): array {
		$cutoffDate = (new \DateTime())->modify('-150 days')->format('Y-m-d H:i:s');

		/** @var list<Appointment> $active */
		$active = $this->appointmentMapper->findAll();

		return array_values(array_filter(
			$active,
			fn (Appointment $apt) => $apt->getStartDatetime() >= $cutoffDate,
		));
	}

	private function feedLanguage(string $userId): IL10N {
		$userLang = $this->config->getUserValue($userId, 'core', 'lang', 'en');

		return $this->l10nFactory->get('attendance', $userLang);
	}

	private function uidDomain(): string {
		$host = parse_url($this->urlGenerator->getAbsoluteURL('/'), PHP_URL_HOST);

		return is_string($host) ? $host : 'nextcloud';
	}

	private function instanceName(): string {
		return $this->config->getSystemValueString('instancename', 'Nextcloud');
	}

	private function calendarHeader(string $calendarName): string {
		$output = "BEGIN:VCALENDAR\r\n";
		$output .= "VERSION:2.0\r\n";
		$output .= "PRODID:-//Nextcloud//Attendance App//EN\r\n";
		$output .= "CALSCALE:GREGORIAN\r\n";
		$output .= 'NAME:' . self::escapeIcalText($calendarName) . "\r\n";
		$output .= 'X-WR-CALNAME:' . self::escapeIcalText($calendarName) . "\r\n";
		$output .= "REFRESH-INTERVAL;VALUE=DURATION:PT15M\r\n";
		$output .= "X-PUBLISHED-TTL:PT15M\r\n";

		return $output;
	}

	/**
	 * Generate iCal feed content for user
	 */
	public function generateIcalFeed(string $userId): string {
		$l = $this->feedLanguage($userId);

		// Only if user is target attendee — no see-all bypass, and deliberately
		// narrower than the list's "My appointments": this feed carries what you
		// are asked to attend. What you organize is the organizer feed.
		$appointments = array_filter(
			$this->feedCandidates(),
			fn (Appointment $apt) => $this->visibilityService->isUserTargetAttendee($apt, $userId),
		);

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

		$domain = $this->uidDomain();
		$output = $this->calendarHeader($l->t('Attendance') . ' – ' . $this->instanceName());

		// Get user's reminder trigger preferences
		$reminderTriggers = $this->configService->getUserIcalReminderTriggers($userId);

		foreach ($appointments as $appointment) {
			$response = $userResponses[$appointment->getId()] ?? null;
			$output .= $this->generateVEvent($appointment, $response, $userId, $l, $domain, $reminderTriggers);
		}

		$output .= "END:VCALENDAR\r\n";

		return $this->foldIcalContent($output);
	}

	/**
	 * The organizer calendar: what the user manages, with everybody's answers.
	 * Rights are checked on every poll, so a link loses what its holder gave up.
	 */
	public function generateOrganizerFeed(string $userId): string {
		$l = $this->feedLanguage($userId);

		// A deactivated account keeps its groups, and with them its rights.
		$appointments = $this->visibilityService->getEnabledUser($userId) === null ? [] : array_values(array_filter(
			$this->feedCandidates(),
			fn (Appointment $apt) => $this->permissionService->canManageAppointment($userId, $apt),
		));
		$responses = $this->responseMapper->findByAppointments(
			array_map(fn (Appointment $apt) => $apt->getId(), $appointments),
		);

		$domain = $this->uidDomain();
		$output = $this->calendarHeader(
			$l->t('Attendance') . ' – ' . $this->instanceName() . ' (' . $l->t('Organizers') . ')',
		);

		foreach ($appointments as $appointment) {
			$output .= $this->generateOrganizerVEvent($appointment, $responses[$appointment->getId()] ?? [], $l, $domain);
		}

		$output .= "END:VCALENDAR\r\n";

		return $this->foldIcalContent($output);
	}

	public function getFeedUrl(IcalToken $token): string {
		return $this->urlGenerator->linkToRouteAbsolute('attendance.ical.feed', [
			'token' => $token->getToken()
		]);
	}

	/**
	 * Create a new token for one of a user's feeds
	 */
	private function createToken(string $userId, string $kind): IcalToken {
		$token = new IcalToken();
		$token->setUserId($userId);
		$token->setKind($kind);
		$token->setToken($this->secureRandom->generate(self::TOKEN_LENGTH, self::TOKEN_CHARS));
		$token->setCreatedAt(gmdate('Y-m-d H:i:s'));

		return $this->icalTokenMapper->insert($token);
	}

	/**
	 * Generate VEVENT for an appointment
	 *
	 * @param list<string> $reminderTriggers List of duration strings for VALARM triggers
	 */
	private function generateVEvent(
		Appointment $appointment,
		?AttendanceResponse $response,
		string $userId,
		IL10N $l,
		string $domain,
		array $reminderTriggers = [],
	): string {
		$responseState = $response ? $response->getResponse() : null;
		$responseComment = $response ? $response->getComment() : null;

		// Translate response labels
		$responseLabels = [
			'yes' => $l->t('Yes'),
			'no' => $l->t('No'),
			'maybe' => $l->t('Maybe'),
		];
		$responseLabel = $responseState !== null ? ($responseLabels[$responseState] ?? '?') : '?';

		// TRANSP: OPAQUE (busy) for yes, TRANSPARENT (free) for no/maybe/none
		$transpMap = [
			'yes' => 'OPAQUE',
			'no' => 'TRANSPARENT',
			'maybe' => 'TRANSPARENT',
		];
		$transp = $responseState !== null ? ($transpMap[$responseState] ?? 'TRANSPARENT') : 'TRANSPARENT';

		// Build summary with response suffix
		$summary = $appointment->getName() . ' (' . $l->t('Me') . ': ' . $responseLabel . ')';

		// Booking status: the scheduling verdict replaces the plain answer in the
		// title, and drives TRANSP — scheduled → busy (OPAQUE), not scheduled →
		// free (TRANSPARENT). No COLOR, Google ignores it in subscribed feeds.
		// Flows through on the next poll since the feed is regenerated per
		// request.
		//
		// Only once the inquiry is closed, and only for somebody the close-time
		// wave actually reached: while people can still answer, nobody has been
		// passed over yet, and a title saying "not scheduled" would be a verdict
		// the organizer has not made. Same rule the cards use in
		// finalScheduleStatus(), so calendar and app never disagree.
		$bookingStatus = $response ? $this->bookingService->effectiveBookingStatus($response) : null;
		if ($responseState === 'yes'
			&& $this->configService->isBookingEnabled()
			&& $appointment->isClosed()
			&& ($bookingStatus === BookingService::STATUS_BOOKED
				|| $bookingStatus === BookingService::STATUS_DECLINED)) {
			if ($bookingStatus === BookingService::STATUS_BOOKED) {
				$transp = 'OPAQUE';
				// TRANSLATORS Status marker appended to the calendar event title — the person got a place in the appointment (German "Eingeplant", not "Geplant"; the appointment itself is not being planned).
				$statusLabel = $l->t('Scheduled');
			} else {
				$transp = 'TRANSPARENT';
				// TRANSLATORS Status marker appended to the calendar event title — the person did not get a place in the appointment (German "Nicht eingeplant").
				$statusLabel = $l->t('Not scheduled');
			}
			$summary = $appointment->getName()
				. ' (' . $l->t('Me') . ': ' . $statusLabel . ')';
		}

		// A place in line is not a place at the appointment, so the slot stays
		// free. Unlike the scheduling verdict above this needs no closed check:
		// "you do not have a spot" is not a judgement the organizer has yet to
		// make, it is where the person stands right now, and promotion flips it
		// back on the next poll.
		if ($responseState === 'yes'
			&& $this->capacityService->limitOf($appointment) !== null
			&& !$this->capacityService->holdsSpot($appointment, $userId)) {
			$transp = 'TRANSPARENT';
			// TRANSLATORS Status marker appended to the calendar event title — the appointment was full, so the person is waiting for a spot rather than holding one (German "Warteliste").
			$summary = $appointment->getName()
				. ' (' . $l->t('Me') . ': ' . $l->t('Waitlist') . ')';
		}

		// Cancelled appointments: the event will not take place. This is the one
		// case where iCal STATUS:CANCELLED is semantically correct. Mark the title
		// and free up the slot (TRANSPARENT). The feed is regenerated on every
		// poll, so this flows through to subscribers automatically.
		if ($appointment->isCancelled()) {
			$transp = 'TRANSPARENT';
			// TRANSLATORS Prefix on the calendar event title of an appointment that was called off (German "Abgesagt", not "Abgebrochen").
			$summary = $l->t('Cancelled') . ': ' . $summary;
		}

		// Build description (use double quotes for actual newlines)
		$descriptionParts = $this->cancelReasonLines($appointment, $l);

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

		// Add attachments section if there are any
		$attachments = $this->attachmentMapper->findByAppointment($appointment->getId());
		$attachmentUrls = [];
		foreach ($attachments as $attachment) {
			$attachmentUrls[$attachment->getFileId()] = $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $attachment->getFileId()]);
		}
		if (count($attachments) > 0) {
			$attachmentLines = [$l->t('Attachments') . ':'];
			foreach ($attachments as $attachment) {
				$attachmentLines[] = $attachment->getFileName();
				$attachmentLines[] = $attachmentUrls[$attachment->getFileId()];
				$attachmentLines[] = ''; // Empty line between attachments
			}
			// Remove trailing empty line
			array_pop($attachmentLines);
			$descriptionParts[] = implode("\n", $attachmentLines);
		}

		// Add link to respond
		$descriptionParts[] = $this->appointmentLinkLine($l, $appointment->getId());

		$description = implode("\n\n", $descriptionParts);

		// Reminders for confirmed/tentative attendance (issue #62)
		$alarms = ($responseState === 'yes' || $responseState === 'maybe') ? $reminderTriggers : [];

		return $this->renderVEvent(
			$appointment,
			'attendance-appointment-' . $appointment->getId() . '@' . $domain,
			$summary,
			$description,
			$response !== null ? [$response] : [],
			$transp,
			array_values($attachmentUrls),
			$alarms,
		);
	}

	/**
	 * VEVENT of the organizer calendar: the title carries the counts, the
	 * description who answered what.
	 *
	 * @param list<AttendanceResponse> $responses All responses of the appointment
	 */
	private function generateOrganizerVEvent(Appointment $appointment, array $responses, IL10N $l, string $domain): string {
		$responders = $this->responseSummaryService->getRespondersByAnswer($appointment, $responses);
		$counts = array_map('count', $responders);

		// Symbols, not words: the numbers have to survive a month view that
		// cuts titles short.
		$summary = $appointment->getName();
		if (array_sum($counts) > 0) {
			$summary .= ' (' . $counts['yes'] . ' ✓ · ' . $counts['no'] . ' ✗ · ' . $counts['maybe'] . ' ?)';
		}

		if ($appointment->isCancelled()) {
			$summary = $l->t('Cancelled') . ': ' . $summary;
		}

		$descriptionParts = $this->cancelReasonLines($appointment, $l);
		$appointmentDescription = trim($appointment->getDescription() ?? '');
		if ($appointmentDescription !== '') {
			$descriptionParts[] = $appointmentDescription;
		}
		$labels = self::answerCountLabels($l, $counts['yes'], $counts['no'], $counts['maybe']);
		$descriptionParts[] = implode("\n", array_map(
			fn (string $label, array $names) => $names === [] ? $label : $label . ' (' . implode(', ', $names) . ')',
			$labels,
			$responders,
		));
		$descriptionParts[] = $this->getAppointmentUrl($appointment->getId());

		// Never busy and no reminders: whether the organizer attends is the
		// personal feed's business, and both may be subscribed side by side.
		return $this->renderVEvent(
			$appointment,
			// Own UID space, so a calendar app holding both feeds keeps both events.
			'attendance-organizer-' . $appointment->getId() . '@' . $domain,
			$summary,
			implode("\n\n", $descriptionParts),
			$responses,
			'TRANSPARENT',
		);
	}

	/**
	 * @return list<string> The organizer's reason as the opening line, if the appointment was called off with one
	 */
	private function cancelReasonLines(Appointment $appointment, IL10N $l): array {
		$reason = $appointment->getCancelReason();
		if ($reason === null) {
			return [];
		}
		// TRANSLATORS Opening line in the calendar event description of an appointment that was called off (German "Absagegrund"). %1$s is the reason the organizer typed, in their own words.
		return [$l->t('Reason for cancellation: %1$s', [$reason])];
	}

	/**
	 * "12 attending", "3 declined", "2 maybe" — one source string each for
	 * every calendar that spells the counts out.
	 *
	 * @return array{yes: string, no: string, maybe: string}
	 */
	public static function answerCountLabels(IL10N $l, int $yes, int $no, int $maybe): array {
		return [
			'yes' => $l->n('%n attending', '%n attending', $yes),
			'no' => $l->n('%n declined', '%n declined', $no),
			'maybe' => $l->n('%n maybe', '%n maybe', $maybe),
		];
	}

	/**
	 * The VEVENT envelope both feeds share: revision, times, place, category and link.
	 *
	 * @param list<AttendanceResponse> $responses The answers this event shows; each one is a revision of it
	 * @param list<string> $attachmentUrls
	 * @param list<string> $alarmTriggers Duration strings for VALARM triggers
	 */
	private function renderVEvent(
		Appointment $appointment,
		string $uid,
		string $summary,
		string $description,
		array $responses,
		string $transp,
		array $attachmentUrls = [],
		array $alarmTriggers = [],
	): string {
		$utc = new \DateTimeZone('UTC');
		$startDt = new \DateTime($appointment->getStartDatetime(), $utc);
		$endDt = new \DateTime($appointment->getEndDatetime(), $utc);
		$createdDt = new \DateTime($appointment->getCreatedAt(), $utc);

		// SEQUENCE is how calendar apps notice a change: one step for an edit, one per answer.
		$lastModifiedDt = new \DateTime($appointment->getUpdatedAt(), $utc);
		$sequence = $lastModifiedDt > $createdDt ? 1 : 0;
		foreach ($responses as $response) {
			$respondedAt = $response->getRespondedAt();
			if (!$respondedAt) {
				continue;
			}
			$sequence++;
			$respondedDt = new \DateTime($respondedAt, $utc);
			if ($respondedDt > $lastModifiedDt) {
				$lastModifiedDt = $respondedDt;
			}
		}

		$output = "BEGIN:VEVENT\r\n";
		$output .= 'UID:' . $uid . "\r\n";
		$output .= 'DTSTAMP:' . $lastModifiedDt->format('Ymd\THis\Z') . "\r\n";
		$output .= 'CREATED:' . $createdDt->format('Ymd\THis\Z') . "\r\n";
		$output .= 'LAST-MODIFIED:' . $lastModifiedDt->format('Ymd\THis\Z') . "\r\n";
		$output .= 'SEQUENCE:' . $sequence . "\r\n";
		if ($appointment->isAllDay()) {
			$lines = self::allDayLines($appointment, $this->timezoneService->ofCreator($appointment));
			$output .= $lines['DTSTART'] . "\r\n" . $lines['DTEND'] . "\r\n";
		} else {
			$output .= 'DTSTART:' . $startDt->format('Ymd\THis\Z') . "\r\n";
			$output .= 'DTEND:' . $endDt->format('Ymd\THis\Z') . "\r\n";
		}
		$output .= 'SUMMARY:' . self::escapeIcalText($summary) . "\r\n";
		$output .= 'DESCRIPTION:' . self::escapeIcalText($description) . "\r\n";
		$location = $appointment->getLocation();
		if ($location !== null) {
			$output .= 'LOCATION:' . self::escapeIcalText($location) . "\r\n";
		}
		$categoryName = $this->resolveCategoryName($appointment->getCategoryId());
		if ($categoryName !== null) {
			$output .= 'CATEGORIES:' . self::escapeIcalText($categoryName) . "\r\n";
		}
		$output .= 'URL:' . $this->getAppointmentUrl($appointment->getId()) . "\r\n";
		$output .= 'STATUS:' . ($appointment->isCancelled() ? 'CANCELLED' : 'CONFIRMED') . "\r\n";
		$output .= 'TRANSP:' . $transp . "\r\n";

		foreach ($attachmentUrls as $attachUrl) {
			$output .= 'ATTACH:' . $attachUrl . "\r\n";
		}

		foreach ($alarmTriggers as $trigger) {
			$output .= "BEGIN:VALARM\r\n";
			$output .= 'TRIGGER:-' . $trigger . "\r\n";
			$output .= "ACTION:DISPLAY\r\n";
			$output .= 'DESCRIPTION:' . self::escapeIcalText($appointment->getName()) . "\r\n";
			$output .= "END:VALARM\r\n";
		}

		$output .= "END:VEVENT\r\n";

		return $output;
	}

	/**
	 * Resolves a category id to its current name, dropping ids for categories
	 * that have since been deleted rather than erroring the whole feed.
	 */
	private function resolveCategoryName(?int $categoryId): ?string {
		if ($categoryId === null) {
			return null;
		}
		if (!array_key_exists($categoryId, $this->categoryNames)) {
			try {
				$this->categoryNames[$categoryId] = $this->categoryMapper->find($categoryId)->getName();
			} catch (DoesNotExistException) {
				$this->categoryNames[$categoryId] = null;
			}
		}

		return $this->categoryNames[$categoryId];
	}

	/**
	 * Absolute deep link to an appointment in the web app.
	 */
	public function getAppointmentUrl(int $appointmentId): string {
		return $this->urlGenerator->linkToRouteAbsolute('attendance.page.appointment', ['id' => $appointmentId]);
	}

	/**
	 * Labelled deep link, as it appears at the end of a calendar event's
	 * description. Shared with the organization calendar so both spell the same
	 * source string — a reword here must not fork into a second one to translate.
	 *
	 * @param IL10N $l The language the event is written in
	 */
	public function appointmentLinkLine(IL10N $l, int $appointmentId): string {
		return $l->t('View or change your response') . ":\n" . $this->getAppointmentUrl($appointmentId);
	}

	/**
	 * Escape text for iCal format (RFC 5545)
	 *
	 * Static for the same reason as unfoldIcalContent() below: a pure transform
	 * tests must not have to restate in a stub.
	 */
	public static function escapeIcalText(string $text): string {
		// Escape backslashes first, then other special chars
		$text = str_replace('\\', '\\\\', $text);
		$text = str_replace(';', '\\;', $text);
		$text = str_replace(',', '\\,', $text);
		// Replace actual newlines with \n literal
		$text = str_replace(["\r\n", "\r", "\n"], '\\n', $text);
		return $text;
	}

	/**
	 * Fold iCal content lines per RFC 5545 Section 3.1
	 * Lines must not exceed 75 octets; fold with CRLF + space.
	 */
	public function foldIcalContent(string $content): string {
		$content = rtrim($content, "\r\n");
		$lines = explode("\r\n", $content);
		$folded = array_map([$this, 'foldIcalLine'], $lines);
		return implode("\r\n", $folded) . "\r\n";
	}

	/**
	 * The inverse of foldIcalContent(): normalize line endings and rejoin the
	 * continuation lines, so every property is one array entry again. Lives
	 * beside its counterpart because both encode the same RFC 5545 Section 3.1
	 * rule, and a reader that disagrees with the writer about where a line ends
	 * corrupts whatever it parses.
	 *
	 * Static because it is a pure transform, so callers can reach it without a
	 * container and tests never have to restate the rule in a stub.
	 *
	 * @return list<string>
	 */
	public static function unfoldIcalContent(string $content): array {
		$normalized = str_replace(["\r\n", "\r"], "\n", $content);
		$normalized = preg_replace("/\n[ \t]/", '', $normalized) ?? $normalized;

		return explode("\n", trim($normalized));
	}

	/**
	 * Whole-day DTSTART/DTEND lines of an all-day appointment, its days read in $zone.
	 *
	 * @return array{DTSTART: string, DTEND: string}
	 */
	public static function allDayLines(Appointment $appointment, \DateTimeZone $zone): array {
		[$start, $end] = $appointment->allDayBounds($zone);

		return [
			'DTSTART' => 'DTSTART;VALUE=DATE:' . $start->format('Ymd'),
			'DTEND' => 'DTEND;VALUE=DATE:' . $end->format('Ymd'),
		];
	}

	/**
	 * The property name of an unfolded line, upper-cased and without its
	 * parameters — "DTSTART" for both `DTSTART:…` and `DTSTART;TZID=…:…`.
	 */
	public static function icalPropertyName(string $line): string {
		return strtoupper(substr($line, 0, strcspn($line, ';:')));
	}

	/**
	 * The VTIMEZONE a document owes every TZID it uses (RFC 5545 3.6.5), cut
	 * down to the clock changes within a year of the given period.
	 *
	 * @return list<string> Unfolded lines
	 */
	public static function vtimezoneLines(\DateTimeZone $zone, \DateTimeInterface $from, \DateTimeInterface $to): array {
		$margin = 366 * 86400;
		$transitions = $zone->getTransitions($from->getTimestamp() - $margin, $to->getTimestamp() + $margin);
		if ($transitions === false) {
			$transitions = [];
		}
		// The first entry is the state at the start of the window, not a change.
		$current = array_shift($transitions) ?? ['offset' => $zone->getOffset($from), 'abbr' => $zone->getName()];

		$lines = ['BEGIN:VTIMEZONE', 'TZID:' . $zone->getName()];
		if ($transitions === []) {
			array_push($lines, ...self::observance('STANDARD', '19700101T000000', $current['offset'], $current));
		}
		foreach ($transitions as $transition) {
			// Onsets are wall-clock times in the offset that applied until then.
			$onset = gmdate('Ymd\THis', $transition['ts'] + $current['offset']);
			$type = $transition['isdst'] ? 'DAYLIGHT' : 'STANDARD';
			array_push($lines, ...self::observance($type, $onset, $current['offset'], $transition));
			$current = $transition;
		}
		$lines[] = 'END:VTIMEZONE';

		return $lines;
	}

	/**
	 * @param array{offset: int, abbr: string, ...} $state What applies from the onset on
	 * @return list<string>
	 */
	private static function observance(string $type, string $onset, int $offsetBefore, array $state): array {
		return [
			'BEGIN:' . $type,
			'DTSTART:' . $onset,
			'TZOFFSETFROM:' . self::utcOffset($offsetBefore),
			'TZOFFSETTO:' . self::utcOffset($state['offset']),
			'TZNAME:' . $state['abbr'],
			'END:' . $type,
		];
	}

	private static function utcOffset(int $seconds): string {
		$minutes = intdiv(abs($seconds), 60);

		return sprintf('%s%02d%02d', $seconds < 0 ? '-' : '+', intdiv($minutes, 60), $minutes % 60);
	}

	/**
	 * Fold a single iCal line to max 75 octets, preserving UTF-8 boundaries.
	 */
	private function foldIcalLine(string $line): string {
		if (strlen($line) <= 75) {
			return $line;
		}

		$result = '';
		$pos = 0;
		$len = strlen($line);
		$firstLine = true;

		while ($pos < $len) {
			$maxBytes = $firstLine ? 75 : 74; // continuation lines have leading space
			$end = min($pos + $maxBytes, $len);

			// Don't split UTF-8 multi-byte characters
			while ($end > $pos && $end < $len && (ord($line[$end]) & 0xC0) === 0x80) {
				$end--;
			}

			$chunk = substr($line, $pos, $end - $pos);
			if ($firstLine) {
				$result = $chunk;
				$firstLine = false;
			} else {
				$result .= "\r\n " . $chunk;
			}

			$pos = $end;
		}

		return $result;
	}
}
