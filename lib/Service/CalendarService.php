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
	 * @param bool $writableOnly Only calendars the user can write events into
	 * @return list<array{uri: string, displayName: string, color: string}>
	 */
	public function getCalendarsForUser(string $userId, bool $writableOnly = false): array {
		if (!$this->isCalendarAvailable()) {
			return [];
		}

		$principal = 'principals/users/' . $userId;
		$calendars = $this->calendarManager->getCalendarsForPrincipal($principal);

		$result = [];
		foreach ($calendars as $calendar) {
			if ($calendar->isDeleted()) {
				continue;
			}
			if ($writableOnly && !$this->isWritableCalendar($calendar)) {
				continue;
			}
			$result[] = [
				'uri' => $calendar->getUri(),
				'displayName' => $calendar->getDisplayName() ?? $calendar->getUri(),
				'color' => $calendar->getDisplayColor() ?? '#0082c9',
			];
		}

		return $result;
	}

	/**
	 * Whether events can be written into this calendar.
	 * Single source of truth for the admin picker and the org calendar sync.
	 */
	public function isWritableCalendar(\OCP\Calendar\ICalendar $calendar): bool {
		// Only real CalDAV calendars accept new objects
		if (!$calendar instanceof \OCP\Calendar\ICreateFromString) {
			return false;
		}
		return !($calendar instanceof \OCP\Calendar\ICalendarIsWritable) || $calendar->isWritable();
	}

	/**
	 * Resolve a calendar of a user by URI, but only if events can be written
	 * into it. Used by the org calendar sync to resolve its target.
	 */
	public function findWritableCalendar(string $userId, string $uri): ?\OCP\Calendar\ICalendar {
		if (!$this->isCalendarAvailable()) {
			return null;
		}

		$principal = 'principals/users/' . $userId;
		foreach ($this->calendarManager->getCalendarsForPrincipal($principal, [$uri]) as $calendar) {
			if ($calendar->getUri() === $uri && !$calendar->isDeleted() && $this->isWritableCalendar($calendar)) {
				return $calendar;
			}
		}

		return null;
	}

	/**
	 * Get events from a specific calendar within a date range.
	 *
	 * @param string $userId
	 * @param string $calendarUri
	 * @param string $from Start date in Y-m-d format
	 * @param string $to End date in Y-m-d format
	 * @return array Array of events with uid, summary, description, dtstart, dtend
	 */
	public function getEventsFromCalendar(string $userId, string $calendarUri, string $from, string $to): array {
		if (!$this->isCalendarAvailable()) {
			return [];
		}

		$principal = 'principals/users/' . $userId;

		// Build search query for events in the given date range
		$start = new DateTimeImmutable($from . 'T00:00:00', new DateTimeZone('UTC'));
		$end = new DateTimeImmutable($to . 'T23:59:59', new DateTimeZone('UTC'));

		$query = $this->calendarManager->newQuery($principal);
		$query->addSearchCalendar($calendarUri);
		$query->setTimerangeStart($start);
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

			$parsedEvents = $this->parseCalendarObject($result);
			array_push($events, ...$parsedEvents);
		}

		// Sort by start date
		usort($events, function ($a, $b) {
			return strtotime($a['dtstart']) - strtotime($b['dtstart']);
		});

		return $events;
	}

	/**
	 * Parse a calendar search result into an array of simplified events.
	 * For recurring events, this returns one entry per occurrence in the time range.
	 *
	 * @param array $searchResult
	 * @return list<array{id: string, uid: string, uri: ?string, summary: string, description: string, location: string, category: string, dtstart: string, dtend: string, isAllDay: bool}>
	 */
	private function parseCalendarObject(array $searchResult): array {
		// The search result contains objects array with event properties directly
		$objects = $searchResult['objects'] ?? [];
		$uri = $searchResult['uri'] ?? null;
		$events = [];

		foreach ($objects as $object) {
			// Properties are directly on the object (SUMMARY, DTSTART, etc.)
			$uid = $this->extractProperty($object, 'UID');
			$summary = $this->extractProperty($object, 'SUMMARY');
			$description = $this->extractProperty($object, 'DESCRIPTION');
			$location = $this->extractProperty($object, 'LOCATION');
			// CATEGORIES may carry a comma-separated list; we only support one
			// category per appointment, so the first entry wins. Matching it to
			// an existing admin-defined category (or not) happens client-side.
			$categories = $this->extractProperty($object, 'CATEGORIES');
			$category = $categories !== null ? trim(explode(',', $categories)[0]) : null;
			$dtstart = $this->extractPropertyRaw($object, 'DTSTART');
			$dtend = $this->extractPropertyRaw($object, 'DTEND');

			if ($uid === null || $dtstart === null) {
				continue;
			}

			// Detect all-day events by checking if both start/end are at midnight
			// and duration is exactly 24 hours (or multiple)
			$isAllDay = $this->isAllDayEvent($dtstart, $dtend);
			$formattedStart = $this->formatDateTime($dtstart, $isAllDay);

			$occurrenceId = self::buildOccurrenceId($uid, $formattedStart);

			$events[] = [
				'id' => $occurrenceId,
				'uid' => $uid,
				'uri' => $uri,
				'summary' => $summary ?? '',
				'description' => $description ?? '',
				'location' => $location ?? '',
				'category' => $category ?? '',
				'dtstart' => $formattedStart,
				'dtend' => $dtend ? $this->formatDateTime($dtend, $isAllDay) : $formattedStart,
				'isAllDay' => $isAllDay,
			];
		}

		return $events;
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

				// Whole multiple of 24h. In seconds, because dividing by 3600
				// first would hand % a float to truncate.
				$diff = $dtend->getTimestamp() - $dtstart->getTimestamp();

				if ($diff > 0 && $diff % 86400 === 0) {
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

	/**
	 * Build a unique occurrence ID from a VEVENT UID and a datetime string.
	 * Used to match calendar events with imported appointments.
	 */
	public static function buildOccurrenceId(string $uid, string $datetime): string {
		return $uid . '_' . preg_replace('/[^0-9]/', '', $datetime);
	}
}
