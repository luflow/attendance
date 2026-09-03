<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Db\CategoryMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory as IL10NFactory;
use Psr\Log\LoggerInterface;

/**
 * Pushes appointments into a single admin-configured "organization calendar"
 * so that everybody the calendar is shared with sees them in the Calendar app
 * (issue #70).
 *
 * Direction app → calendar only lives here. The reverse direction (edits made
 * in the Calendar app) is covered by the existing CalendarObjectUpdateListener,
 * because pushed events are linked through the same calendarUri/calendarEventUid
 * columns the import flow uses. The listener writes through the mapper (not this
 * service), so no push/echo loop can form; the listener echo of our own writes
 * is idempotent because the pushed DESCRIPTION is the plain appointment
 * description plus the app-generated block, which the listener strips again
 * via stripAppendedBlock() before writing back.
 *
 * Object CRUD uses OCA\DAV\CalDAV\CalDavBackend because OCP offers no public
 * update/delete API for calendar objects (nextcloud/server#20154):
 * ICreateFromString and ICalendarEventBuilder can only create, and both throw
 * when the object URI already exists. The backend is resolved lazily and every
 * entry point swallows failures with a warning — a broken calendar must never
 * break appointment management.
 *
 * @psalm-suppress MixedMethodCall The CalDAV backend is intentionally untyped
 * (see getCalDavBackend()) so the app keeps loading without the dav app.
 */
class OrgCalendarSyncService {
	private const UID_PREFIX = 'attendance-org-';

	/**
	 * Separator line between the appointment description and the app-generated
	 * block (response summary and deep link) inside the event DESCRIPTION.
	 * Doubles as the suppression marker: CalendarObjectUpdateListener strips
	 * everything from this line on before syncing a calendar-side description
	 * back into the appointment, so the block never leaks into the appointment
	 * description (echo loop).
	 * Must stay untranslated — stripping has to work in every language.
	 */
	public const BLOCK_SEPARATOR = '--- Attendance ---';

	/**
	 * Properties managedProperties() derives from the appointment's updatedAt,
	 * so they change whenever the row is touched — on a comment edit or a
	 * repeated identical answer just as much as on a real change. Skipping them
	 * when comparing is what makes those writes skippable. Keep this in step
	 * with managedProperties(): a further per-write property left out here makes
	 * every document look different and quietly retires the skip.
	 */
	private const VOLATILE_PROPERTIES = ['DTSTAMP', 'LAST-MODIFIED'];

	/** @var array{0: int, 1: string}|false|null Memoized target; false = resolution failed */
	private array|false|null $resolvedTarget = null;
	private ?object $calDavBackend = null;
	private bool $backendResolved = false;
	private ?string $uidDomain = null;
	private ?IL10N $l10n = null;

	public function __construct(
		private CalendarService $calendarService,
		private AppointmentMapper $appointmentMapper,
		private AttendanceResponseMapper $responseMapper,
		private ConfigService $configService,
		private IcalService $icalService,
		private IURLGenerator $urlGenerator,
		private IL10NFactory $l10nFactory,
		private IConfig $config,
		private LoggerInterface $logger,
		private CategoryMapper $categoryMapper,
	) {
	}

	/**
	 * Whether the feature is switched on and fully configured.
	 */
	public function isEnabled(): bool {
		return $this->configService->isOrgCalendarEnabled()
			&& $this->configService->getOrgCalendarUri() !== ''
			&& $this->configService->getOrgCalendarUserId() !== '';
	}

	/**
	 * Apply an admin settings change: persist the toggles, remember which
	 * account resolves and writes the calendar, and backfill upcoming
	 * appointments when the feature was enabled or re-pointed. Owning the
	 * transition here keeps controller and any future callers consistent.
	 *
	 * @param array{enabled?: bool, calendarUri?: string, summary?: bool} $orgCalendar Settings payload
	 * @param string $actingUserId The admin performing the change
	 */
	public function applySettings(array $orgCalendar, string $actingUserId): void {
		$changed = false;

		if (isset($orgCalendar['enabled'])) {
			$changed = $this->configService->isOrgCalendarEnabled() !== (bool)$orgCalendar['enabled'];
			$this->configService->setOrgCalendarEnabled((bool)$orgCalendar['enabled']);
		}

		if (isset($orgCalendar['summary'])) {
			// Backfill in both directions: switching off has to take the summary
			// out of the events that already carry it, not just stop adding it.
			$changed = $changed || $this->configService->isOrgCalendarSummaryEnabled() !== $orgCalendar['summary'];
			$this->configService->setOrgCalendarSummaryEnabled($orgCalendar['summary']);
		}

		if (isset($orgCalendar['calendarUri']) && $orgCalendar['calendarUri'] !== '') {
			$oldUri = $this->configService->getOrgCalendarUri();
			$this->configService->setOrgCalendarUri($orgCalendar['calendarUri']);
			// Writes happen through the principal of the admin who selected
			// the calendar; keep the stored account when the target is unchanged.
			if ($oldUri !== $orgCalendar['calendarUri'] || $this->configService->getOrgCalendarUserId() === '') {
				$this->configService->setOrgCalendarUserId($actingUserId);
				$changed = true;
			}
		}

		if ($changed) {
			$this->resolvedTarget = null;
			$this->syncAllUpcoming();
		}
	}

	/**
	 * Create or update the calendar event for an appointment.
	 *
	 * Appointments that are linked to a foreign calendar event (imported ones)
	 * are skipped: they already live in a calendar, and overwriting the source
	 * event with our plain-text representation would be lossy.
	 *
	 * @return bool True if the appointment is in the calendar afterwards —
	 *              including when it was already current and nothing had to be
	 *              written. Callers count coverage, not writes: the admin's
	 *              sync button promises to create or update the events for all
	 *              upcoming appointments, and reporting 0 because they were all
	 *              already correct reads as a failure.
	 */
	public function syncAppointment(Appointment $appointment): bool {
		try {
			if (!$this->isEnabled() || !$appointment->getIsActive()) {
				return false;
			}

			$existingUid = $appointment->getCalendarEventUid() ?? '';
			if ($existingUid !== '' && !$this->isOwnEventUid($existingUid)) {
				// Imported from a calendar — that event is the source of truth
				return false;
			}
			// Reuse the stored UID so events survive an instance domain change
			$uid = $existingUid !== '' ? $existingUid : $this->buildEventUid($appointment->getId());

			$resolved = $this->resolveTargetCalendar();
			$backend = $this->getCalDavBackend();
			if ($resolved === null || $backend === null) {
				return false;
			}
			[$calendarId, $ownerCalendarUri] = $resolved;

			$objectUri = $uid . '.ics';

			$existing = $backend->getCalendarObject($calendarId, $objectUri);
			if ($existing !== null) {
				// Patch only the properties the app models so anything added in
				// the Calendar app (alarms, attendees, categories, …) survives.
				$existingIcs = $this->extractCalendarData($existing);
				$ics = $existingIcs !== null
					? $this->patchIcs($existingIcs, $appointment)
					: $this->buildIcs($appointment, $uid);
				// Skip a write that changes nothing a reader would see: it would
				// still raise a calendar activity for everybody the calendar is
				// shared with — see isOrgCalendarSummaryEnabled(). The appointment
				// is in the calendar either way, so the caller is told so.
				if ($existingIcs === null || !$this->matchesIgnoringTimestamps($existingIcs, $ics)) {
					$backend->updateCalendarObject($calendarId, $objectUri, $ics);
				}
			} else {
				$backend->createCalendarObject($calendarId, $objectUri, $this->buildIcs($appointment, $uid));
			}

			$this->linkAppointment($appointment, $uid, $ownerCalendarUri);

			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Attendance: failed to sync appointment {id} to organization calendar: {message}', [
				'id' => $appointment->getId(),
				'message' => $e->getMessage(),
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * Remove the calendar event of a deleted appointment (moves it to the
	 * calendar trash bin). Only events this service created are touched.
	 */
	public function handleAppointmentDeleted(Appointment $appointment): void {
		try {
			if (!$this->isEnabled()) {
				return;
			}

			$uid = $appointment->getCalendarEventUid() ?? '';
			if (!$this->isOwnEventUid($uid)) {
				return;
			}

			$resolved = $this->resolveTargetCalendar();
			$backend = $this->getCalDavBackend();
			if ($resolved === null || $backend === null) {
				return;
			}
			[$calendarId, ] = $resolved;

			$objectUri = $uid . '.ics';
			if ($backend->getCalendarObject($calendarId, $objectUri) !== null) {
				$backend->deleteCalendarObject($calendarId, $objectUri);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Attendance: failed to remove organization calendar event for appointment {id}: {message}', [
				'id' => $appointment->getId(),
				'message' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * Push all upcoming active appointments into the organization calendar.
	 * Runs as backfill when the feature is enabled or the target changes, and
	 * on demand via the admin settings button.
	 *
	 * @return int Number of appointments written
	 */
	public function syncAllUpcoming(): int {
		$count = 0;
		try {
			if (!$this->isEnabled()) {
				return 0;
			}
			foreach ($this->appointmentMapper->findUpcoming() as $appointment) {
				if ($this->syncAppointment($appointment)) {
					$count++;
				}
			}
			$this->logger->info('Attendance: backfilled {count} appointments into the organization calendar', [
				'count' => $count,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('Attendance: organization calendar backfill failed: {message}', [
				'message' => $e->getMessage(),
				'exception' => $e,
			]);
		}
		return $count;
	}

	/**
	 * Store the link with the owner's calendar URI — calendar events dispatched
	 * by the server carry that URI, so the existing CalendarObjectUpdateListener
	 * can match edits back to us. Also runs when the write itself was skipped:
	 * the link is what makes an event ours, and a skipped write must not leave
	 * it unset.
	 */
	private function linkAppointment(Appointment $appointment, string $uid, string $ownerCalendarUri): void {
		if ($appointment->getCalendarEventUid() === $uid
			&& $appointment->getCalendarUri() === $ownerCalendarUri) {
			return;
		}
		$appointment->setCalendarUri($ownerCalendarUri);
		$appointment->setCalendarEventUid($uid);
		$this->appointmentMapper->update($appointment);
	}

	/**
	 * Whether two VCALENDAR documents say the same thing to a reader.
	 *
	 * VOLATILE_PROPERTIES are excluded — see the constant. Folding and line
	 * endings are normalized first, because the stored document may have been
	 * folded by the Calendar app rather than by us.
	 */
	public function matchesIgnoringTimestamps(string $left, string $right): bool {
		return $this->comparableLines($left) === $this->comparableLines($right);
	}

	/**
	 * @return list<string> Unfolded lines without the volatile timestamps
	 */
	private function comparableLines(string $ics): array {
		$lines = [];
		foreach (IcalService::unfoldIcalContent($ics) as $line) {
			if (in_array(IcalService::icalPropertyName($line), self::VOLATILE_PROPERTIES, true)) {
				continue;
			}
			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * Deterministic VEVENT UID for an appointment.
	 */
	public function buildEventUid(int $appointmentId): string {
		if ($this->uidDomain === null) {
			$domain = $this->urlGenerator->getAbsoluteURL('/');
			$this->uidDomain = parse_url($domain, PHP_URL_HOST) ?? 'nextcloud';
		}
		return self::UID_PREFIX . $appointmentId . '@' . $this->uidDomain;
	}

	/**
	 * Whether a stored calendarEventUid was created by this service. Matched
	 * by prefix, not full equality, so ownership survives an instance domain
	 * change; anything else is an import.
	 */
	private function isOwnEventUid(string $uid): bool {
		return str_starts_with($uid, self::UID_PREFIX);
	}

	/**
	 * Build the full VCALENDAR document for a new appointment event.
	 *
	 * DESCRIPTION carries only content derived from the appointment (plus the
	 * app block the listener strips again), so the listener echo of our own
	 * writes is a no-op. URL carries the same deep link for the clients that
	 * surface that property.
	 */
	public function buildIcs(Appointment $appointment, string $uid): string {
		$utc = new \DateTimeZone('UTC');
		$created = new \DateTime($appointment->getCreatedAt() ?: 'now', $utc);

		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Nextcloud//Attendance App//EN',
			'CALSCALE:GREGORIAN',
			'BEGIN:VEVENT',
			'UID:' . $uid,
			'CREATED:' . $created->format('Ymd\THis\Z'),
			'URL:' . $this->icalService->getAppointmentUrl($appointment->getId()),
			'TRANSP:OPAQUE',
		];
		foreach ($this->managedProperties($appointment) as $line) {
			if ($line !== null) {
				$lines[] = $line;
			}
		}
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return $this->icalService->foldIcalContent(implode("\r\n", $lines) . "\r\n");
	}

	/**
	 * Patch an existing VCALENDAR document: replace only the properties the
	 * app models (times, summary, description, location, status) inside the
	 * first VEVENT and leave every other line untouched — alarms, attendees,
	 * categories and custom properties added in the Calendar app survive
	 * app-side edits (issue #70, phase 2).
	 *
	 * Hand-rolled on purpose: Sabre VObject only exists at Nextcloud runtime,
	 * and this transform has to stay unit-testable. Properties inside nested
	 * components (e.g. a VALARM's DESCRIPTION) are never touched. Falls back
	 * to a full rebuild if no VEVENT is found.
	 */
	public function patchIcs(string $existingIcs, Appointment $appointment): string {
		$lines = IcalService::unfoldIcalContent($existingIcs);

		$props = $this->managedProperties($appointment);
		$result = [];
		$inVevent = false;
		$nested = 0;
		$patched = false;

		foreach ($lines as $line) {
			if (!$inVevent) {
				if ($line === 'BEGIN:VEVENT' && !$patched) {
					$inVevent = true;
				}
				$result[] = $line;
				continue;
			}
			if (str_starts_with($line, 'BEGIN:')) {
				$nested++;
				$result[] = $line;
				continue;
			}
			if (str_starts_with($line, 'END:')) {
				if ($nested > 0) {
					$nested--;
					$result[] = $line;
					continue;
				}
				// END:VEVENT — append managed properties the event did not have yet
				foreach ($props as $newLine) {
					if ($newLine !== null) {
						$result[] = $newLine;
					}
				}
				$props = [];
				$inVevent = false;
				$patched = true;
				$result[] = $line;
				continue;
			}
			if ($nested === 0) {
				$name = IcalService::icalPropertyName($line);
				if (array_key_exists($name, $props)) {
					$newLine = $props[$name];
					unset($props[$name]);
					if ($newLine !== null) {
						$result[] = $newLine;
					}
					continue;
				}
			}
			$result[] = $line;
		}

		if (!$patched) {
			return $this->buildIcs($appointment, $this->buildEventUid($appointment->getId()));
		}

		return $this->icalService->foldIcalContent(implode("\r\n", $result) . "\r\n");
	}

	/**
	 * The VEVENT properties the app owns, as full unfolded lines.
	 * A null value means the property must be absent (e.g. an unset location).
	 *
	 * @return array<string, ?string> property name => line
	 */
	private function managedProperties(Appointment $appointment): array {
		$utc = new \DateTimeZone('UTC');
		$start = new \DateTime($appointment->getStartDatetime(), $utc);
		$end = new \DateTime($appointment->getEndDatetime(), $utc);
		$lastModified = new \DateTime($appointment->getUpdatedAt() ?: 'now', $utc);

		$location = $appointment->getLocation();
		$categoryName = $this->resolveCategoryName($appointment->getCategoryId());

		return [
			'DTSTAMP' => 'DTSTAMP:' . $lastModified->format('Ymd\THis\Z'),
			'LAST-MODIFIED' => 'LAST-MODIFIED:' . $lastModified->format('Ymd\THis\Z'),
			'DTSTART' => 'DTSTART:' . $start->format('Ymd\THis\Z'),
			'DTEND' => 'DTEND:' . $end->format('Ymd\THis\Z'),
			'SUMMARY' => 'SUMMARY:' . IcalService::escapeIcalText($appointment->getName()),
			'DESCRIPTION' => 'DESCRIPTION:' . IcalService::escapeIcalText($this->buildDescription($appointment)),
			'LOCATION' => $location !== null
				? 'LOCATION:' . IcalService::escapeIcalText($location)
				: null,
			'CATEGORIES' => $categoryName !== null
				? 'CATEGORIES:' . IcalService::escapeIcalText($categoryName)
				: null,
			'STATUS' => 'STATUS:' . ($appointment->isCancelled() ? 'CANCELLED' : 'CONFIRMED'),
		];
	}

	/**
	 * Resolves a category id to its current name, dropping ids for categories
	 * that have since been deleted rather than erroring the sync.
	 */
	private function resolveCategoryName(?int $categoryId): ?string {
		if ($categoryId === null) {
			return null;
		}
		try {
			return $this->categoryMapper->find($categoryId)->getName();
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Event DESCRIPTION = plain appointment description, plus the app block
	 * behind the BLOCK_SEPARATOR marker: the response summary when anybody
	 * responded (issue #71) and a link into the app to answer (issue #205).
	 *
	 * Only the summary sits behind the summary switch — the link never changes,
	 * so carrying it costs no extra write.
	 */
	private function buildDescription(Appointment $appointment): string {
		$description = trim($appointment->getDescription() ?? '');
		$summary = $this->configService->isOrgCalendarSummaryEnabled()
			? $this->buildResponseSummary($appointment)
			: null;

		return ($description !== '' ? $description . "\n\n" : '')
			. self::BLOCK_SEPARATOR . "\n"
			. ($summary !== null ? $summary . "\n" : '')
			. $this->icalService->appointmentLinkLine($this->getL10N(), $appointment->getId());
	}

	/**
	 * One-line response summary, e.g. "12 attending, 3 declined, 2 maybe".
	 *
	 * @return string|null Null when nobody responded yet
	 */
	private function buildResponseSummary(Appointment $appointment): ?string {
		$counts = $this->responseMapper->getResponseSummary($appointment->getId());
		if (($counts['yes'] ?? 0) + ($counts['no'] ?? 0) + ($counts['maybe'] ?? 0) === 0) {
			return null;
		}

		$l10n = $this->getL10N();

		return implode(', ', [
			$l10n->n('%n attending', '%n attending', $counts['yes'] ?? 0),
			$l10n->n('%n declined', '%n declined', $counts['no'] ?? 0),
			$l10n->n('%n maybe', '%n maybe', $counts['maybe'] ?? 0),
		]);
	}

	/**
	 * Instance default language — the calendar is shared org-wide, so its text
	 * must not depend on whoever triggered the write.
	 */
	private function getL10N(): IL10N {
		if ($this->l10n === null) {
			$lang = $this->config->getSystemValueString('default_language', 'en');
			$this->l10n = $this->l10nFactory->get('attendance', $lang);
		}
		return $this->l10n;
	}

	/**
	 * Remove the app-generated block from a calendar-side description. Used by
	 * CalendarObjectUpdateListener before syncing a description back into the
	 * appointment, so our own summary and link (which always sit at the end)
	 * never become part of the appointment description.
	 */
	public static function stripAppendedBlock(string $description): string {
		$pos = strpos($description, self::BLOCK_SEPARATOR);
		if ($pos === false) {
			return $description;
		}
		return rtrim(substr($description, 0, $pos));
	}

	/**
	 * Resolve the configured calendar to its numeric CalDAV id plus the
	 * owner-side calendar URI (a calendar shared with the configured admin has
	 * a different URI in their principal than in the owner's). Memoized —
	 * the target cannot change within a request, and the series/backfill
	 * loops call this once per appointment.
	 *
	 * @return array{0: int, 1: string}|null
	 */
	private function resolveTargetCalendar(): ?array {
		if ($this->resolvedTarget !== null) {
			return $this->resolvedTarget ?: null;
		}

		$this->resolvedTarget = false;
		$userId = $this->configService->getOrgCalendarUserId();
		$uri = $this->configService->getOrgCalendarUri();

		$calendar = $this->calendarService->findWritableCalendar($userId, $uri);
		if ($calendar === null) {
			$this->logger->warning('Attendance: configured organization calendar {uri} not found or not writable for user {userId}', [
				'uri' => $uri,
				'userId' => $userId,
			]);
			return null;
		}

		$calendarId = (int)$calendar->getKey();
		$backend = $this->getCalDavBackend();
		if ($backend === null) {
			return null;
		}
		$row = $backend->getCalendarById($calendarId);
		if ($row === null) {
			return null;
		}

		$this->resolvedTarget = [$calendarId, (string)($row['uri'] ?? $uri)];
		return $this->resolvedTarget;
	}

	/**
	 * Get the raw ICS string out of a CalDavBackend object row.
	 */
	private function extractCalendarData(array $object): ?string {
		$data = $object['calendardata'] ?? null;
		if (is_resource($data)) {
			$data = stream_get_contents($data);
		}
		return (is_string($data) && $data !== '') ? $data : null;
	}

	/**
	 * Lazily resolve the CalDAV backend, once per request. Protected so unit
	 * tests can stub it; resolved by class name string so the app still loads
	 * if the dav app's internals move.
	 */
	protected function getCalDavBackend(): ?object {
		if ($this->backendResolved) {
			return $this->calDavBackend;
		}
		$this->backendResolved = true;
		try {
			$this->calDavBackend = \OCP\Server::get('OCA\DAV\CalDAV\CalDavBackend');
		} catch (\Throwable $e) {
			$this->logger->warning('Attendance: CalDAV backend unavailable, organization calendar sync skipped: {message}', [
				'message' => $e->getMessage(),
			]);
			$this->calDavBackend = null;
		}
		return $this->calDavBackend;
	}
}
