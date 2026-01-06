<?php

declare(strict_types=1);

namespace OCA\Attendance\Listener;

use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Service\ConfigService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Reader;

/**
 * Listens for calendar object updates and syncs changes to linked attendance appointments.
 *
 * This listener handles CalendarObjectUpdatedEvent and CalendarObjectDeletedEvent
 * to keep attendance appointments in sync with their source calendar events.
 *
 * @template-implements IEventListener<Event>
 */
class CalendarObjectUpdateListener implements IEventListener {
	private AppointmentMapper $appointmentMapper;
	private ConfigService $configService;
	private LoggerInterface $logger;

	public function __construct(
		AppointmentMapper $appointmentMapper,
		ConfigService $configService,
		LoggerInterface $logger,
	) {
		$this->appointmentMapper = $appointmentMapper;
		$this->configService = $configService;
		$this->logger = $logger;
	}

	public function handle(Event $event): void {
		$eventClass = get_class($event);
		$this->logger->debug('Attendance: CalendarObjectUpdateListener received event: {class}', [
			'class' => $eventClass,
		]);

		// Check if calendar sync is enabled
		if (!$this->configService->isCalendarSyncEnabled()) {
			$this->logger->debug('Attendance: Calendar sync is disabled, skipping');
			return;
		}

		// Handle both updated and deleted events
		// We use string comparison because the classes only exist in NC 32+
		if (str_ends_with($eventClass, 'CalendarObjectUpdatedEvent')) {
			$this->handleUpdate($event);
		} elseif (str_ends_with($eventClass, 'CalendarObjectDeletedEvent')) {
			$this->handleDelete($event);
		}
	}

	/**
	 * Handle calendar object update - sync changes to linked appointments
	 */
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

			// Parse iCal data using Sabre VObject
			$vcalendar = Reader::read($objectData['calendardata']);
			if (!$vcalendar || !isset($vcalendar->VEVENT)) {
				return;
			}

			$vevent = $vcalendar->VEVENT;
			$uid = (string)($vevent->UID ?? '');
			if (!$uid) {
				return;
			}

			// Find appointments linked to this calendar event
			// The stored value is the filename (e.g., "uid.ics"), so search with .ics suffix
			$appointments = $this->appointmentMapper->findByCalendarEventUid($uid . '.ics', $calendarUri);
			if (empty($appointments)) {
				return;
			}

			// Extract event details
			$summary = (string)($vevent->SUMMARY ?? '');
			$description = (string)($vevent->DESCRIPTION ?? '');
			$dtstart = $vevent->DTSTART ? $vevent->DTSTART->getDateTime() : null;
			$dtend = $vevent->DTEND ? $vevent->DTEND->getDateTime() : null;

			// Convert to UTC for storage (database stores UTC)
			$utcTimezone = new \DateTimeZone('UTC');

			// Update each linked appointment
			foreach ($appointments as $appointment) {
				if ($summary) {
					$appointment->setName($summary);
				}
				if ($description !== '') {
					$appointment->setDescription($description);
				}
				if ($dtstart) {
					$dtstartUtc = (clone $dtstart)->setTimezone($utcTimezone);
					$appointment->setStartDatetime($dtstartUtc->format('Y-m-d H:i:s'));
				}
				if ($dtend) {
					$dtendUtc = (clone $dtend)->setTimezone($utcTimezone);
					$appointment->setEndDatetime($dtendUtc->format('Y-m-d H:i:s'));
				}

				$appointment->setUpdatedAt(date('Y-m-d H:i:s'));
				$this->appointmentMapper->update($appointment);

				$this->logger->info('Updated attendance appointment {id} from calendar event {uid}', [
					'id' => $appointment->getId(),
					'uid' => $uid,
				]);
			}
		} catch (\Exception $e) {
			$this->logger->error('Failed to sync calendar update to attendance: {message}', [
				'message' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * Handle calendar object deletion - we don't delete the appointment,
	 * but we could optionally clear the calendar reference
	 */
	private function handleDelete(Event $event): void {
		// For now, we don't delete appointments when calendar events are deleted
		// This could be a future enhancement with a separate setting
	}
}
