<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCP\App\IAppManager;
use OCP\Calendar\IManager as ICalendarManager;

/**
 * Service for reading user calendars and events from Nextcloud Calendar.
 */
class CalendarService {
	private ICalendarManager $calendarManager;
	private IAppManager $appManager;

	public function __construct(
		ICalendarManager $calendarManager,
		IAppManager $appManager,
	) {
		$this->calendarManager = $calendarManager;
		$this->appManager = $appManager;
	}

	/**
	 * Check if Calendar app is available.
	 *
	 * @return bool True if calendar app is enabled
	 */
	public function isCalendarAvailable(): bool {
		return $this->appManager->isEnabledForUser('calendar');
	}

	/**
	 * Get all calendars for a user.
	 *
	 * @param string $userId
	 * @return array Array of calendars with uri, displayName, color
	 */
	public function getCalendarsForUser(string $userId): array {
		if (!$this->isCalendarAvailable()) {
			return [];
		}

		$principal = 'principals/users/' . $userId;
		$calendars = $this->calendarManager->getCalendarsForPrincipal($principal);

		$result = [];
		foreach ($calendars as $calendar) {
			$result[] = [
				'uri' => $calendar->getUri(),
				'displayName' => $calendar->getDisplayName(),
				'color' => $calendar->getDisplayColor() ?? '#0082c9',
			];
		}

		return $result;
	}

	/**
	 * Get upcoming events from a specific calendar.
	 *
	 * @param string $userId
	 * @param string $calendarUri
	 * @param int $days Number of days to look ahead (default 30)
	 * @return array Array of events with uid, summary, description, dtstart, dtend
	 */
	public function getEventsFromCalendar(string $userId, string $calendarUri, int $days = 30): array {
		if (!$this->isCalendarAvailable()) {
			return [];
		}

		$principal = 'principals/users/' . $userId;

		// Build search query for upcoming events
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$end = new DateTimeImmutable("+{$days} days", new DateTimeZone('UTC'));

		$query = $this->calendarManager->newQuery($principal);
		$query->setTimerangeStart($now);
		$query->setTimerangeEnd($end);

		// Search for all events (empty search pattern matches all)
		$results = $this->calendarManager->searchForPrincipal($query);

		$events = [];
		foreach ($results as $result) {
			// Filter by calendar URI
			$resultCalendarUri = $result['calendar-uri'] ?? '';
			if ($resultCalendarUri !== $calendarUri) {
				continue;
			}

			$event = $this->parseCalendarObject($result);
			if ($event !== null) {
				$events[] = $event;
			}
		}

		// Sort by start date
		usort($events, function ($a, $b) {
			return strtotime($a['dtstart']) - strtotime($b['dtstart']);
		});

		return $events;
	}

	/**
	 * Parse a calendar search result into a simplified event array.
	 *
	 * @param array $searchResult
	 * @return array|null
	 */
	private function parseCalendarObject(array $searchResult): ?array {
		// The search result contains objects array with event properties directly
		$objects = $searchResult['objects'] ?? [];

		foreach ($objects as $object) {
			// Properties are directly on the object (SUMMARY, DTSTART, etc.)
			$uid = $this->extractProperty($object, 'UID');
			$summary = $this->extractProperty($object, 'SUMMARY');
			$description = $this->extractProperty($object, 'DESCRIPTION');
			$dtstart = $this->extractPropertyRaw($object, 'DTSTART');
			$dtend = $this->extractPropertyRaw($object, 'DTEND');

			if ($uid === null || $dtstart === null) {
				continue;
			}

			// Detect all-day events by checking if both start/end are at midnight
			// and duration is exactly 24 hours (or multiple)
			$isAllDay = $this->isAllDayEvent($dtstart, $dtend);

			// Get the URI/filename from the search result for building calendar links
			$uri = $searchResult['uri'] ?? null;

			return [
				'uid' => $uid,
				'uri' => $uri,
				'summary' => $summary ?? '',
				'description' => $description ?? '',
				'dtstart' => $this->formatDateTime($dtstart, $isAllDay),
				'dtend' => $dtend ? $this->formatDateTime($dtend, $isAllDay) : $this->formatDateTime($dtstart, $isAllDay),
				'isAllDay' => $isAllDay,
			];
		}

		return null;
	}

	/**
	 * Check if an event is all-day based on DTSTART and DTEND values.
	 * All-day events have both start and end at midnight, with duration of exactly 24h (or multiple).
	 */
	private function isAllDayEvent(mixed $dtstart, mixed $dtend): bool {
		// If it's a string like "20260107" (date only, no time), it's all-day
		if (is_string($dtstart) && preg_match('/^\d{8}$/', $dtstart)) {
			return true;
		}

		// For DateTime objects, check if both are at midnight and duration is 24h multiple
		if (($dtstart instanceof DateTimeImmutable || $dtstart instanceof DateTime)
			&& ($dtend instanceof DateTimeImmutable || $dtend instanceof DateTime)) {

			$startHour = (int)$dtstart->format('H');
			$startMinute = (int)$dtstart->format('i');
			$startSecond = (int)$dtstart->format('s');

			$endHour = (int)$dtend->format('H');
			$endMinute = (int)$dtend->format('i');
			$endSecond = (int)$dtend->format('s');

			// Both must be at midnight
			if ($startHour === 0 && $startMinute === 0 && $startSecond === 0
				&& $endHour === 0 && $endMinute === 0 && $endSecond === 0) {

				// Duration must be exactly 24 hours or a multiple
				$diff = $dtend->getTimestamp() - $dtstart->getTimestamp();
				$hours = $diff / 3600;

				if ($hours > 0 && $hours % 24 === 0) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Extract a property value from VEVENT data.
	 *
	 * @param array $vevent
	 * @param string $property
	 * @return mixed
	 */
	private function extractProperty(array $vevent, string $property): mixed {
		$value = $vevent[$property] ?? null;

		if ($value === null) {
			return null;
		}

		// Handle array of values (take first)
		if (is_array($value)) {
			$value = $value[0] ?? null;
		}

		return $value;
	}

	/**
	 * Extract a property value keeping the raw format for type detection.
	 * Same as extractProperty but used for datetime fields where we need to detect all-day events.
	 */
	private function extractPropertyRaw(array $vevent, string $property): mixed {
		return $this->extractProperty($vevent, $property);
	}

	/**
	 * Format DateTime to ISO 8601 string.
	 * For all-day events, format without timezone (local date at midnight).
	 *
	 * @param mixed $dt
	 * @param bool $isAllDay
	 * @return string
	 */
	private function formatDateTime(mixed $dt, bool $isAllDay = false): string {
		if ($dt instanceof DateTimeImmutable || $dt instanceof DateTime) {
			if ($isAllDay) {
				// For all-day events, return date at midnight without Z suffix
				// so frontend treats it as local time
				return $dt->format('Y-m-d\T00:00:00');
			}
			return $dt->format('Y-m-d\TH:i:s\Z');
		}

		if (is_string($dt)) {
			try {
				// Handle date-only strings like "20260107"
				if (preg_match('/^\d{8}$/', $dt)) {
					$date = DateTime::createFromFormat('Ymd', $dt);
					return $date->format('Y-m-d\T00:00:00');
				}

				$date = new DateTime($dt);
				if ($isAllDay) {
					return $date->format('Y-m-d\T00:00:00');
				}
				$date->setTimezone(new DateTimeZone('UTC'));
				return $date->format('Y-m-d\TH:i:s\Z');
			} catch (\Exception $e) {
				return $dt;
			}
		}

		return '';
	}
}
