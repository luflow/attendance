<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCP\Config\IUserConfig;
use OCP\IDateTimeZone;

/**
 * Which zone a user's wall-clock times and calendar days are meant in.
 */
final class TimezoneService {
	/** @var array<string, \DateTimeZone> feeds and lists ask once per appointment, for few creators */
	private array $zones = [];

	public function __construct(
		private IUserConfig $userConfig,
		private IDateTimeZone $dateTimeZone,
	) {
	}

	/**
	 * The user's own zone, else the instance's, else UTC.
	 */
	public function ofUser(string $userId): \DateTimeZone {
		return $this->zones[$userId] ??= $this->resolve($userId);
	}

	/**
	 * The zone an appointment's whole days and wall-clock times were entered in.
	 */
	public function ofCreator(Appointment $appointment): \DateTimeZone {
		return $this->ofUser($appointment->getCreatedBy());
	}

	private function resolve(string $userId): \DateTimeZone {
		try {
			$own = $userId !== '' ? $this->userConfig->getValueString($userId, 'core', 'timezone') : '';
		} catch (\Exception) {
			$own = '';
		}

		return self::locationZone($own)
			?? self::locationZone($this->dateTimeZone->getDefaultTimeZone()->getName())
			?? new \DateTimeZone('UTC');
	}

	/**
	 * A zone PHP knows the clock changes of. Abbreviations and bare offsets are
	 * turned down: PHP reads them as fixed, which would drop daylight saving.
	 */
	public static function locationZone(string $name): ?\DateTimeZone {
		if ($name === '') {
			return null;
		}
		try {
			$zone = new \DateTimeZone($name);
		} catch (\Exception) {
			return null;
		}

		return $zone->getLocation() !== false ? $zone : null;
	}
}
