<?php

declare(strict_types=1);

namespace OCA\Attendance\Db;

/**
 * Trait for formatting datetime strings to UTC ISO 8601 format.
 * Used by entity classes to ensure consistent date serialization in API responses.
 */
trait DatetimeFormatTrait {
	/**
	 * Format a datetime string (stored in UTC) to ISO 8601 format with Z suffix.
	 *
	 * @param ?string $datetime The datetime string to format (MySQL format, stored in UTC)
	 * @return ?string The formatted datetime in 'Y-m-d\TH:i:s\Z' format, or null if empty
	 */
	protected function formatDatetimeToUtc(?string $datetime): ?string {
		if (empty($datetime)) {
			return null;
		}
		try {
			$utcTimezone = new \DateTimeZone('UTC');
			$date = new \DateTime($datetime, $utcTimezone);
			return $date->format('Y-m-d\TH:i:s\Z');
		} catch (\Exception $e) {
			return $datetime;
		}
	}
}
