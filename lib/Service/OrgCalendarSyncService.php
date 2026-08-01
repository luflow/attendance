<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\IURLGenerator;
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

	public function __construct(
		private ICalendarManager $calendarManager,
		private AppointmentMapper $appointmentMapper,
		private ConfigService $configService,
		private IcalService $icalService,
		private IURLGenerator $urlGenerator,
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
			$ics = $this->buildIcs($appointment, $uid);

			if ($backend->getCalendarObject($calendarId, $objectUri) !== null) {
				$backend->updateCalendarObject($calendarId, $objectUri, $ics);
			} else {
				$backend->createCalendarObject($calendarId, $objectUri, $ics);
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
	 * Build the full VCALENDAR document for an appointment.
	 *
	 * DESCRIPTION is exactly the plain appointment description — no extras —
	 * so that the CalendarObjectUpdateListener echoing our own write back into
	 * the appointment is a no-op. The deep link goes into URL instead.
	 */
	public function buildIcs(Appointment $appointment, string $uid): string {
		$utc = new \DateTimeZone('UTC');
		$start = new \DateTime($appointment->getStartDatetime(), $utc);
		$end = new \DateTime($appointment->getEndDatetime(), $utc);
		$lastModified = new \DateTime($appointment->getUpdatedAt() ?: 'now', $utc);
		$created = new \DateTime($appointment->getCreatedAt() ?: 'now', $utc);

		$appointmentUrl = $this->urlGenerator->linkToRouteAbsolute('attendance.page.index')
			. '#/appointment/' . $appointment->getId();

		$status = $appointment->isCancelled() ? 'CANCELLED' : 'CONFIRMED';

		$output = "BEGIN:VCALENDAR\r\n";
		$output .= "VERSION:2.0\r\n";
		$output .= "PRODID:-//Nextcloud//Attendance App//EN\r\n";
		$output .= "CALSCALE:GREGORIAN\r\n";
		$output .= "BEGIN:VEVENT\r\n";
		$output .= 'UID:' . $uid . "\r\n";
		$output .= 'DTSTAMP:' . $lastModified->format('Ymd\THis\Z') . "\r\n";
		$output .= 'CREATED:' . $created->format('Ymd\THis\Z') . "\r\n";
		$output .= 'LAST-MODIFIED:' . $lastModified->format('Ymd\THis\Z') . "\r\n";
		$output .= 'DTSTART:' . $start->format('Ymd\THis\Z') . "\r\n";
		$output .= 'DTEND:' . $end->format('Ymd\THis\Z') . "\r\n";
		$output .= 'SUMMARY:' . $this->icalService->escapeIcalText($appointment->getName()) . "\r\n";
		if (($appointment->getDescription() ?? '') !== '') {
			$output .= 'DESCRIPTION:' . $this->icalService->escapeIcalText($appointment->getDescription()) . "\r\n";
		}
		$output .= 'URL:' . $appointmentUrl . "\r\n";
		$output .= 'STATUS:' . $status . "\r\n";
		$output .= "TRANSP:OPAQUE\r\n";
		$output .= "END:VEVENT\r\n";
		$output .= "END:VCALENDAR\r\n";

		return $this->icalService->foldIcalContent($output);
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
