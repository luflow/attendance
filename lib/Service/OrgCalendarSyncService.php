<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\IConfig;
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
 * service), so no push/echo loop can form; our own writes fired back at the
 * listener are idempotent because the pushed DESCRIPTION is exactly the plain
 * appointment description.
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
	 * response summary inside the event DESCRIPTION. Doubles as the suppression
	 * marker: CalendarObjectUpdateListener strips everything from this line on
	 * before syncing a calendar-side description back into the appointment, so
	 * the summary never leaks into the appointment description (echo loop).
	 * Must stay untranslated — stripping has to work in every language.
	 */
	public const SUMMARY_SEPARATOR = '--- Attendance ---';

	public function __construct(
		private ICalendarManager $calendarManager,
		private AppointmentMapper $appointmentMapper,
		private AttendanceResponseMapper $responseMapper,
		private ConfigService $configService,
		private IcalService $icalService,
		private IURLGenerator $urlGenerator,
		private IL10NFactory $l10nFactory,
		private IConfig $config,
		private LoggerInterface $logger,
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
	 * Create or update the calendar event for an appointment.
	 *
	 * Appointments that are linked to a foreign calendar event (imported ones)
	 * are skipped: they already live in a calendar, and overwriting the source
	 * event with our plain-text representation would be lossy.
	 *
	 * @return bool True if an event was written
	 */
	public function syncAppointment(Appointment $appointment): bool {
		try {
			if (!$this->isEnabled() || !$appointment->getIsActive()) {
				return false;
			}

			$uid = $this->buildEventUid($appointment->getId());
			$existingUid = $appointment->getCalendarEventUid();
			if ($existingUid !== null && $existingUid !== '' && $existingUid !== $uid) {
				// Imported from a calendar — that event is the source of truth
				return false;
			}

			$resolved = $this->resolveTargetCalendar();
			if ($resolved === null) {
				return false;
			}
			[$calendarId, $ownerCalendarUri] = $resolved;

			$backend = $this->getCalDavBackend();
			if ($backend === null) {
				return false;
			}

			$objectUri = $uid . '.ics';

			$existing = $backend->getCalendarObject($calendarId, $objectUri);
			if ($existing !== null) {
				// Patch only the properties the app models so anything added in
				// the Calendar app (alarms, location, attendees, …) survives.
				$existingIcs = $this->extractCalendarData($existing);
				$ics = $existingIcs !== null
					? $this->patchIcs($existingIcs, $appointment)
					: $this->buildIcs($appointment, $uid);
				$backend->updateCalendarObject($calendarId, $objectUri, $ics);
			} else {
				$backend->createCalendarObject($calendarId, $objectUri, $this->buildIcs($appointment, $uid));
			}

			// Store the link with the owner's calendar URI — calendar events
			// dispatched by the server carry that URI, so the existing
			// CalendarObjectUpdateListener can match edits back to us.
			if ($appointment->getCalendarEventUid() !== $uid
				|| $appointment->getCalendarUri() !== $ownerCalendarUri) {
				$appointment->setCalendarUri($ownerCalendarUri);
				$appointment->setCalendarEventUid($uid);
				$this->appointmentMapper->update($appointment);
			}

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
	 *
	 * @return bool True if an event was removed
	 */
	public function handleAppointmentDeleted(Appointment $appointment): bool {
		try {
			if (!$this->isEnabled()) {
				return false;
			}

			$uid = $this->buildEventUid($appointment->getId());
			if ($appointment->getCalendarEventUid() !== $uid) {
				return false;
			}

			$resolved = $this->resolveTargetCalendar();
			if ($resolved === null) {
				return false;
			}
			[$calendarId, ] = $resolved;

			$backend = $this->getCalDavBackend();
			if ($backend === null) {
				return false;
			}

			$objectUri = $uid . '.ics';
			if ($backend->getCalendarObject($calendarId, $objectUri) === null) {
				return false;
			}

			$backend->deleteCalendarObject($calendarId, $objectUri);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Attendance: failed to remove organization calendar event for appointment {id}: {message}', [
				'id' => $appointment->getId(),
				'message' => $e->getMessage(),
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * Push all upcoming active appointments into the organization calendar.
	 * Used as backfill when the feature is enabled or the target changes.
	 *
	 * @return int Number of appointments written
	 */
	public function syncAllUpcoming(): int {
		$count = 0;
		try {
			foreach ($this->appointmentMapper->findUpcoming() as $appointment) {
				if ($this->syncAppointment($appointment)) {
					$count++;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Attendance: organization calendar backfill failed: {message}', [
				'message' => $e->getMessage(),
				'exception' => $e,
			]);
		}
		return $count;
	}

	/**
	 * Deterministic VEVENT UID for an appointment. Also used to recognize
	 * "our" events: an appointment whose calendarEventUid matches this value
	 * was pushed by this service, anything else is an import.
	 */
	public function buildEventUid(int $appointmentId): string {
		$domain = $this->urlGenerator->getAbsoluteURL('/');
		$domain = parse_url($domain, PHP_URL_HOST) ?? 'nextcloud';
		return self::UID_PREFIX . $appointmentId . '@' . $domain;
	}

	/**
	 * Build the full VCALENDAR document for a new appointment event.
	 *
	 * DESCRIPTION is exactly the plain appointment description — no extras —
	 * so that the CalendarObjectUpdateListener echoing our own write back into
	 * the appointment is a no-op. The deep link goes into URL instead.
	 */
	public function buildIcs(Appointment $appointment, string $uid): string {
		$utc = new \DateTimeZone('UTC');
		$created = new \DateTime($appointment->getCreatedAt() ?: 'now', $utc);

		$appointmentUrl = $this->urlGenerator->linkToRouteAbsolute('attendance.page.index')
			. '#/appointment/' . $appointment->getId();

		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Nextcloud//Attendance App//EN',
			'CALSCALE:GREGORIAN',
			'BEGIN:VEVENT',
			'UID:' . $uid,
			'CREATED:' . $created->format('Ymd\THis\Z'),
			'URL:' . $appointmentUrl,
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
	 * app models (times, summary, description, status) inside the first
	 * VEVENT and leave every other line untouched — alarms, location,
	 * attendees, categories and custom properties added in the Calendar app
	 * survive app-side edits (issue #70, phase 2).
	 *
	 * Properties inside nested components (e.g. a VALARM's DESCRIPTION) are
	 * never touched. Falls back to a full rebuild if no VEVENT is found.
	 */
	public function patchIcs(string $existingIcs, Appointment $appointment): string {
		// Normalize newlines and unfold continuation lines (RFC 5545 3.1)
		$content = str_replace(["\r\n", "\r"], "\n", $existingIcs);
		$content = preg_replace("/\n[ \t]/", '', $content) ?? $content;
		$lines = explode("\n", trim($content));

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
				$name = strtoupper(substr($line, 0, strcspn($line, ';:')));
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
	 * A null value means the property must be absent (e.g. empty description).
	 *
	 * @return array<string, ?string> property name => line
	 */
	private function managedProperties(Appointment $appointment): array {
		$utc = new \DateTimeZone('UTC');
		$start = new \DateTime($appointment->getStartDatetime(), $utc);
		$end = new \DateTime($appointment->getEndDatetime(), $utc);
		$lastModified = new \DateTime($appointment->getUpdatedAt() ?: 'now', $utc);

		$description = $this->buildDescription($appointment);

		return [
			'DTSTAMP' => 'DTSTAMP:' . $lastModified->format('Ymd\THis\Z'),
			'LAST-MODIFIED' => 'LAST-MODIFIED:' . $lastModified->format('Ymd\THis\Z'),
			'DTSTART' => 'DTSTART:' . $start->format('Ymd\THis\Z'),
			'DTEND' => 'DTEND:' . $end->format('Ymd\THis\Z'),
			'SUMMARY' => 'SUMMARY:' . $this->icalService->escapeIcalText($appointment->getName()),
			'DESCRIPTION' => $description !== ''
				? 'DESCRIPTION:' . $this->icalService->escapeIcalText($description)
				: null,
			'STATUS' => 'STATUS:' . ($appointment->isCancelled() ? 'CANCELLED' : 'CONFIRMED'),
		];
	}

	/**
	 * Event DESCRIPTION = plain appointment description, plus — when anybody
	 * responded — the response summary behind the SUMMARY_SEPARATOR marker
	 * (the "who is coming" visibility agreed in issue #71).
	 */
	private function buildDescription(Appointment $appointment): string {
		$description = trim($appointment->getDescription() ?? '');

		$summary = $this->buildResponseSummary($appointment);
		if ($summary !== null) {
			$description = ($description !== '' ? $description . "\n\n" : '')
				. self::SUMMARY_SEPARATOR . "\n" . $summary;
		}

		return $description;
	}

	/**
	 * One-line response summary, e.g. "12 attending, 3 declined, 2 maybe".
	 * Uses the instance default language — the calendar is shared org-wide,
	 * so the text must not depend on whoever responded last.
	 *
	 * @return string|null Null when nobody responded yet
	 */
	private function buildResponseSummary(Appointment $appointment): ?string {
		$counts = ['yes' => 0, 'no' => 0, 'maybe' => 0];
		foreach ($this->responseMapper->findByAppointment($appointment->getId()) as $response) {
			$value = $response->getResponse();
			if (isset($counts[$value])) {
				$counts[$value]++;
			}
		}
		if (array_sum($counts) === 0) {
			return null;
		}

		$lang = $this->config->getSystemValueString('default_language', 'en');
		$l = $this->l10nFactory->get('attendance', $lang);

		return implode(', ', [
			$l->n('%n attending', '%n attending', $counts['yes']),
			$l->n('%n declined', '%n declined', $counts['no']),
			$l->n('%n maybe', '%n maybe', $counts['maybe']),
		]);
	}

	/**
	 * Remove the app-generated response summary block from a calendar-side
	 * description. Used by CalendarObjectUpdateListener before syncing a
	 * description back into the appointment, so our own summary (which always
	 * sits at the end) never becomes part of the appointment description.
	 */
	public static function stripResponseSummary(string $description): string {
		$pos = strpos($description, self::SUMMARY_SEPARATOR);
		if ($pos === false) {
			return $description;
		}
		return rtrim(substr($description, 0, $pos));
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
	 * Resolve the configured calendar to its numeric CalDAV id plus the
	 * owner-side calendar URI (a calendar shared with the configured admin has
	 * a different URI in their principal than in the owner's).
	 *
	 * @return array{0: int, 1: string}|null
	 */
	private function resolveTargetCalendar(): ?array {
		$userId = $this->configService->getOrgCalendarUserId();
		$uri = $this->configService->getOrgCalendarUri();

		$principal = 'principals/users/' . $userId;
		$calendars = $this->calendarManager->getCalendarsForPrincipal($principal, [$uri]);

		foreach ($calendars as $calendar) {
			if ($calendar->getUri() !== $uri || $calendar->isDeleted()) {
				continue;
			}
			if (!$calendar instanceof ICreateFromString) {
				continue;
			}
			if ($calendar instanceof ICalendarIsWritable && !$calendar->isWritable()) {
				continue;
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
			return [$calendarId, (string)($row['uri'] ?? $uri)];
		}

		$this->logger->warning('Attendance: configured organization calendar {uri} not found or not writable for user {userId}', [
			'uri' => $uri,
			'userId' => $userId,
		]);
		return null;
	}

	/**
	 * Lazily resolve the CalDAV backend. Protected so unit tests can stub it;
	 * resolved by class name string so the app still loads if the dav app's
	 * internals move.
	 */
	protected function getCalDavBackend(): ?object {
		try {
			return \OCP\Server::get('OCA\DAV\CalDAV\CalDavBackend');
		} catch (\Throwable $e) {
			$this->logger->warning('Attendance: CalDAV backend unavailable, organization calendar sync skipped: {message}', [
				'message' => $e->getMessage(),
			]);
			return null;
		}
	}
}
