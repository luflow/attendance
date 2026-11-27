<?php

declare(strict_types=1);

namespace OCA\Attendance\Listener;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Service\AppointmentService;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\OrgCalendarSyncService;
use OCA\Attendance\Service\TimezoneService;
use OCP\Calendar\Events\AbstractCalendarObjectEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property\ICalendar\DateTime as VDateTime;
use Sabre\VObject\Reader;

/**
 * Listens for calendar object updates, deletions, and trash events
 * to keep attendance appointments in sync with their source calendar events.
 *
 * @template-implements IEventListener<Event>
 */
class CalendarObjectUpdateListener implements IEventListener {
	public function __construct(
		private AppointmentMapper $appointmentMapper,
		private ConfigService $configService,
		private AppointmentService $appointmentService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private TimezoneService $timezoneService,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		$this->logger->debug('Attendance: CalendarObjectUpdateListener received event: {class}', [
			'class' => get_class($event),
		]);

		if (!$this->configService->isCalendarSyncEnabled()) {
			$this->logger->debug('Attendance: Calendar sync is disabled, skipping');
			return;
		}

		if ($event instanceof CalendarObjectUpdatedEvent) {
			$this->handleUpdate($event);
		} elseif ($event instanceof CalendarObjectDeletedEvent
			|| $event instanceof CalendarObjectMovedToTrashEvent) {
			$this->handleDelete($event);
		}
	}

	/**
	 * Find linked appointments using a 3-step fallback lookup:
	 * 1. By VEVENT UID (new imports)
	 * 2. By UID.ics (old native Nextcloud imports)
	 * 3. By DAV object URI (old external imports, e.g. Google Calendar)
	 *
	 * @return array<Appointment>
	 */
	private function findLinkedAppointments(string $uid, ?string $davUri, int $calendarId): array {
		$appointments = $this->appointmentMapper->findByCalendarEventUid($uid, $calendarId);
		if (empty($appointments)) {
			$appointments = $this->appointmentMapper->findByCalendarEventUid($uid . '.ics', $calendarId);
		}
		if (empty($appointments) && $davUri !== null && $davUri !== '') {
			$appointments = $this->appointmentMapper->findByCalendarEventUid($davUri, $calendarId);
		}
		return $appointments;
	}

	/**
	 * Sync properties from a VEVENT to an appointment and persist.
	 *
	 * Dragging the event in the Calendar app is the same edit as moving the
	 * appointment in the app, so it announces itself the same way. The wave is
	 * driven by the actual field diff, which is what keeps the app's own
	 * write-back through OrgCalendarSyncService from echoing back as a change.
	 */
	private function syncAppointmentFromVevent(Appointment $appointment, VEvent $vevent, string $fallbackSummary, string $fallbackDescription): void {
		$before = clone $appointment;
		$utcTimezone = new \DateTimeZone('UTC');

		$summary = (string)($vevent->SUMMARY ?? '') ?: $fallbackSummary;
		$description = (string)($vevent->DESCRIPTION ?? '') ?: $fallbackDescription;

		if ($summary) {
			$appointment->setName($summary);
		}
		if ($description !== '') {
			// The app-generated block is calendar-only content
			$appointment->setDescription(strip_tags(OrgCalendarSyncService::stripAppendedBlock($description)));
		}

		// A date-only start means whole days; they carry no zone, so they are the creator's.
		$start = $vevent->DTSTART ?? null;
		$end = $vevent->DTEND ?? null;
		$dayZone = preg_match('/^\d{8}$/', (string)($start ?? '')) === 1
			? $this->timezoneService->ofCreator($appointment)
			: null;
		$dtstart = $start instanceof VDateTime ? $start->getDateTime($dayZone) : null;
		$dtend = $end instanceof VDateTime ? $end->getDateTime($dayZone) : null;
		if ($dtstart !== null) {
			$appointment->setStartDatetime($dtstart->setTimezone($utcTimezone)->format('Y-m-d H:i:s'));
			$appointment->setAllDay($dayZone !== null);
		}
		if ($dtend !== null) {
			$appointment->setEndDatetime($dtend->setTimezone($utcTimezone)->format('Y-m-d H:i:s'));
		} elseif ($dayZone !== null) {
			// RFC 5545: a date without an end is that one day
			$appointment->setEndDatetime($appointment->getStartDatetime());
			$appointment->alignAllDay($dayZone);
		}
		if ($dayZone !== null) {
			$appointment->keepMidnightsOfSameDays($before, $dayZone);
		}

		$appointment->setUpdatedAt(gmdate('Y-m-d H:i:s'));
		$updated = $this->appointmentMapper->update($appointment);

		$this->appointmentService->announceAppointmentUpdate(
			$before,
			$updated,
			$this->userSession->getUser()?->getUID(),
		);
	}

	/**
	 * The calendar ID is the owner's for a shared calendar, so a write-sharee's
	 * edit still reaches the linked appointment.
	 */
	private function handleUpdate(AbstractCalendarObjectEvent $event): void {
		try {
			$objectData = $event->getObjectData();
			$calendarData = $objectData['calendardata'] ?? null;
			if (!is_string($calendarData) && !is_resource($calendarData)) {
				return;
			}

			$vcalendar = Reader::read($calendarData);
			if (!$vcalendar instanceof VCalendar) {
				return;
			}
			$vevents = self::veventsOf($vcalendar);
			$vevent = $vevents[0] ?? null;
			if ($vevent === null) {
				return;
			}

			$uid = (string)($vevent->UID ?? '');
			if ($uid === '') {
				return;
			}

			$appointments = $this->findLinkedAppointments($uid, self::stringOrNull($objectData, 'uri'), $event->getCalendarId());
			if (empty($appointments)) {
				return;
			}

			$isRecurring = isset($vevent->RRULE) || count($vevents) > 1;
			$summary = (string)($vevent->SUMMARY ?? '');
			$description = (string)($vevent->DESCRIPTION ?? '');

			if (!$isRecurring) {
				foreach ($appointments as $appointment) {
					$this->syncAppointmentFromVevent($appointment, $vevent, $summary, $description);
					$this->logger->debug('Synced appointment {id} from calendar event {uid}', [
						'id' => $appointment->getId(), 'uid' => $uid,
					]);
				}
				return;
			}

			// Recurring: expand occurrences and match by timestamp proximity
			$occurrenceTimestamps = $this->expandOccurrences($vcalendar, $appointments);

			foreach ($appointments as $appointment) {
				$appointmentStart = $appointment->getStartDatetime();
				if (!$appointmentStart) {
					// No start time — sync shared properties only
					if ($summary) {
						$appointment->setName($summary);
					}
					if ($description !== '') {
						$appointment->setDescription(strip_tags(OrgCalendarSyncService::stripAppendedBlock($description)));
					}
					$appointment->setUpdatedAt(gmdate('Y-m-d H:i:s'));
					$this->appointmentMapper->update($appointment);
					continue;
				}

				$matchedVe = $this->findClosestOccurrence($appointmentStart, $occurrenceTimestamps);
				if ($matchedVe !== null) {
					$this->syncAppointmentFromVevent($appointment, $matchedVe, $summary, $description);
					$this->logger->debug('Synced recurring appointment {id} from calendar event {uid}', [
						'id' => $appointment->getId(), 'uid' => $uid,
					]);
				} else {
					// Occurrence removed (EXDATE) — soft-delete
					$appointment->setIsActive(0);
					$appointment->setUpdatedAt(gmdate('Y-m-d H:i:s'));
					$this->appointmentMapper->update($appointment);
					$this->logger->info('Soft-deleted appointment {id} — occurrence removed from recurring event {uid}', [
						'id' => $appointment->getId(), 'uid' => $uid,
					]);
				}
			}
		} catch (\Exception $e) {
			$this->logger->error('Failed to sync calendar update to attendance: {message}', [
				'message' => $e->getMessage(), 'exception' => $e,
			]);
		}
	}

	/**
	 * Expand recurring event occurrences within the window of linked appointments.
	 *
	 * @param array<Appointment> $appointments
	 * @return array<int, VEvent> timestamp => VEVENT
	 */
	private function expandOccurrences(VCalendar $vcalendar, array $appointments): array {
		$utcTimezone = new \DateTimeZone('UTC');
		$earliestStart = null;
		$latestStart = null;
		foreach ($appointments as $appt) {
			$s = $appt->getStartDatetime();
			if ($earliestStart === null || $s < $earliestStart) {
				$earliestStart = $s;
			}
			if ($latestStart === null || $s > $latestStart) {
				$latestStart = $s;
			}
		}

		if ($earliestStart === null || $latestStart === null || $earliestStart === '') {
			return [];
		}

		try {
			$expandStart = (new \DateTimeImmutable($earliestStart, $utcTimezone))->modify('-2 days');
			$expandEnd = (new \DateTimeImmutable($latestStart, $utcTimezone))->modify('+2 days');
			$timestamps = self::indexByStart($vcalendar->expand($expandStart, $expandEnd));
			$this->logger->debug('Expanded recurring event to {count} occurrences', [
				'count' => count($timestamps),
			]);
			return $timestamps;
		} catch (\Exception $e) {
			$this->logger->warning('Failed to expand recurring event: {message}', [
				'message' => $e->getMessage(),
			]);
			// Fallback: use raw VEVENTs
			return self::indexByStart($vcalendar);
		}
	}

	/**
	 * @return array<int, VEvent> timestamp => VEVENT
	 */
	private static function indexByStart(VCalendar $vcalendar): array {
		$timestamps = [];
		foreach (self::veventsOf($vcalendar) as $ve) {
			$start = $ve->DTSTART ?? null;
			if ($start instanceof VDateTime) {
				$timestamps[$start->getDateTime()->getTimestamp()] = $ve;
			}
		}
		return $timestamps;
	}

	/**
	 * Typed stand-in for the magic `$vcalendar->VEVENT`, in document order.
	 *
	 * @return list<VEvent>
	 */
	private static function veventsOf(VCalendar $vcalendar): array {
		return array_values(array_filter(
			$vcalendar->select('VEVENT'),
			static fn (mixed $node): bool => $node instanceof VEvent,
		));
	}

	/**
	 * A non-empty string value from the event's object data, else null.
	 */
	private static function stringOrNull(array $objectData, string $key): ?string {
		return isset($objectData[$key]) && is_string($objectData[$key]) && $objectData[$key] !== ''
			? $objectData[$key]
			: null;
	}

	/**
	 * Find the closest expanded occurrence to an appointment's start time.
	 * Returns null if no occurrence is within 36 hours (timezone tolerance).
	 *
	 * @param array<int, VEvent> $occurrenceTimestamps
	 */
	private function findClosestOccurrence(string $appointmentStart, array $occurrenceTimestamps): ?VEvent {
		$apptTs = (new \DateTime($appointmentStart, new \DateTimeZone('UTC')))->getTimestamp();
		$matchedVe = null;
		$bestDiff = PHP_INT_MAX;

		foreach ($occurrenceTimestamps as $occTs => $ve) {
			$diff = abs($apptTs - $occTs);
			if ($diff < $bestDiff) {
				$bestDiff = $diff;
				$matchedVe = $ve;
			}
		}

		$maxDiff = 36 * 3600;
		return ($matchedVe !== null && $bestDiff <= $maxDiff) ? $matchedVe : null;
	}

	private function handleDelete(AbstractCalendarObjectEvent $event): void {
		try {
			$objectData = $event->getObjectData();
			$calendarId = $event->getCalendarId();
			$uid = self::stringOrNull($objectData, 'uid');
			$davUri = self::stringOrNull($objectData, 'uri');

			if ($uid !== null) {
				$appointments = $this->findLinkedAppointments($uid, $davUri, $calendarId);
			} elseif ($davUri !== null) {
				$appointments = $this->appointmentMapper->findByCalendarEventUid($davUri, $calendarId);
			} else {
				return;
			}

			if (empty($appointments)) {
				$this->logger->debug('Attendance handleDelete: no linked appointments found for uid={uid}', [
					'uid' => $uid ?? $davUri,
				]);
				return;
			}

			foreach ($appointments as $appointment) {
				$appointment->setIsActive(0);
				$appointment->setUpdatedAt(gmdate('Y-m-d H:i:s'));
				$this->appointmentMapper->update($appointment);

				$this->logger->info('Soft-deleted appointment {id} after calendar event {uid} was removed', [
					'id' => $appointment->getId(), 'uid' => $uid ?? $davUri,
				]);
			}
		} catch (\Exception $e) {
			$this->logger->error('Failed to delete appointments after calendar event removal: {message}', [
				'message' => $e->getMessage(), 'exception' => $e,
			]);
		}
	}
}
