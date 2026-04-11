<?php

declare(strict_types=1);

namespace OCA\Attendance\Listener;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Service\ConfigService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
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
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		$eventClass = get_class($event);
		$this->logger->debug('Attendance: CalendarObjectUpdateListener received event: {class}', [
			'class' => $eventClass,
		]);

		if (!$this->configService->isCalendarSyncEnabled()) {
			$this->logger->debug('Attendance: Calendar sync is disabled, skipping');
			return;
		}

		if (str_ends_with($eventClass, 'CalendarObjectUpdatedEvent')) {
			$this->handleUpdate($event);
		} elseif (str_ends_with($eventClass, 'CalendarObjectDeletedEvent')
			|| str_ends_with($eventClass, 'CalendarObjectMovedToTrashEvent')) {
			$this->handleDelete($event);
		}
	}

	/**
	 * Find linked appointments using a 3-step fallback lookup:
	 * 1. By VEVENT UID (new imports)
	 * 2. By UID.ics (old native Nextcloud imports)
	 * 3. By DAV object URI (old external imports, e.g. Google Calendar)
	 *
	 * @return list<Appointment>
	 */
	private function findLinkedAppointments(string $uid, ?string $davUri, string $calendarUri): array {
		$appointments = $this->appointmentMapper->findByCalendarEventUid($uid, $calendarUri);
		if (empty($appointments)) {
			$appointments = $this->appointmentMapper->findByCalendarEventUid($uid . '.ics', $calendarUri);
		}
		if (empty($appointments) && $davUri) {
			$appointments = $this->appointmentMapper->findByCalendarEventUid($davUri, $calendarUri);
		}
		return $appointments;
	}

	/**
	 * Sync properties from a VEVENT to an appointment and persist.
	 */
	private function syncAppointmentFromVevent(Appointment $appointment, $vevent, string $fallbackSummary, string $fallbackDescription): void {
		$utcTimezone = new \DateTimeZone('UTC');

		$summary = (string)($vevent->SUMMARY ?? '') ?: $fallbackSummary;
		$description = (string)($vevent->DESCRIPTION ?? '') ?: $fallbackDescription;

		if ($summary) {
			$appointment->setName($summary);
		}
		if ($description !== '') {
			$appointment->setDescription(strip_tags($description));
		}

		$dtstart = $vevent->DTSTART ? $vevent->DTSTART->getDateTime() : null;
		$dtend = $vevent->DTEND ? $vevent->DTEND->getDateTime() : null;
		if ($dtstart) {
			$appointment->setStartDatetime((clone $dtstart)->setTimezone($utcTimezone)->format('Y-m-d H:i:s'));
		}
		if ($dtend) {
			$appointment->setEndDatetime((clone $dtend)->setTimezone($utcTimezone)->format('Y-m-d H:i:s'));
		}

		$appointment->setUpdatedAt(gmdate('Y-m-d H:i:s'));
		$this->appointmentMapper->update($appointment);
	}

	private function handleUpdate(Event $event): void {
		try {
			$objectData = $event->getObjectData();
			$calendarData = $event->getCalendarData();

			if (!isset($objectData['calendardata'])) {
				return;
			}

			$calendarUri = $calendarData['uri'] ?? null;
			if (!$calendarUri) {
				return;
			}

			$vcalendar = Reader::read($objectData['calendardata']);
			if (!$vcalendar || !isset($vcalendar->VEVENT)) {
				return;
			}

			$vevent = $vcalendar->VEVENT;
			$uid = (string)($vevent->UID ?? '');
			if (!$uid) {
				return;
			}

			$davUri = $objectData['uri'] ?? null;
			$appointments = $this->findLinkedAppointments($uid, $davUri, $calendarUri);
			if (empty($appointments)) {
				return;
			}

			$isRecurring = isset($vevent->RRULE) || count($vcalendar->VEVENT) > 1;
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
						$appointment->setDescription(strip_tags($description));
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
	 * @param list<Appointment> $appointments
	 * @return array<int, mixed> timestamp => VEVENT
	 */
	private function expandOccurrences($vcalendar, array $appointments): array {
		$utcTimezone = new \DateTimeZone('UTC');
		$earliestStart = null;
		$latestStart = null;
		foreach ($appointments as $appt) {
			$s = $appt->getStartDatetime();
			if ($s !== null) {
				if ($earliestStart === null || $s < $earliestStart) {
					$earliestStart = $s;
				}
				if ($latestStart === null || $s > $latestStart) {
					$latestStart = $s;
				}
			}
		}

		if (!$earliestStart || !$latestStart) {
			return [];
		}

		try {
			$expandStart = (new \DateTimeImmutable($earliestStart, $utcTimezone))->modify('-2 days');
			$expandEnd = (new \DateTimeImmutable($latestStart, $utcTimezone))->modify('+2 days');
			$expanded = $vcalendar->expand($expandStart, $expandEnd);
			$timestamps = [];
			foreach ($expanded->VEVENT as $ve) {
				if ($ve->DTSTART) {
					$timestamps[$ve->DTSTART->getDateTime()->getTimestamp()] = $ve;
				}
			}
			$this->logger->debug('Expanded recurring event to {count} occurrences', [
				'count' => count($timestamps),
			]);
			return $timestamps;
		} catch (\Exception $e) {
			$this->logger->warning('Failed to expand recurring event: {message}', [
				'message' => $e->getMessage(),
			]);
			// Fallback: use raw VEVENTs
			$timestamps = [];
			foreach ($vcalendar->VEVENT as $ve) {
				if ($ve->DTSTART) {
					$timestamps[$ve->DTSTART->getDateTime()->getTimestamp()] = $ve;
				}
			}
			return $timestamps;
		}
	}

	/**
	 * Find the closest expanded occurrence to an appointment's start time.
	 * Returns null if no occurrence is within 36 hours (timezone tolerance).
	 */
	private function findClosestOccurrence(string $appointmentStart, array $occurrenceTimestamps): mixed {
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

	private function handleDelete(Event $event): void {
		try {
			$objectData = $event->getObjectData();
			$calendarData = $event->getCalendarData();

			$calendarUri = $calendarData['uri'] ?? null;
			if (!$calendarUri) {
				return;
			}

			$uid = $objectData['uid'] ?? null;
			$davUri = $objectData['uri'] ?? null;

			if (!$uid && !$davUri) {
				return;
			}

			$appointments = $uid
				? $this->findLinkedAppointments($uid, $davUri, $calendarUri)
				: $this->appointmentMapper->findByCalendarEventUid($davUri, $calendarUri);

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
