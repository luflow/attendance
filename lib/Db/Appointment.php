<?php

declare(strict_types=1);

namespace OCA\Attendance\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getDescription()
 * @method void setDescription(string $description)
 * @method string getStartDatetime()
 * @method void setStartDatetime(string $startDatetime)
 * @method string getEndDatetime()
 * @method void setEndDatetime(string $endDatetime)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 * @method bool getIsActive()
 * @method void setIsActive(bool $isActive)
 * @method string getVisibleUsers()
 * @method void setVisibleUsers(string $visibleUsers)
 * @method string getVisibleGroups()
 * @method void setVisibleGroups(string $visibleGroups)
 */
class Appointment extends Entity implements JsonSerializable {
	protected $name = '';
	protected $description = '';
	protected $startDatetime = '';
	protected $endDatetime = '';
	protected $createdBy = '';
	protected $createdAt = '';
	protected $updatedAt = '';
	protected $isActive = 1;
	protected $visibleUsers = null;
	protected $visibleGroups = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('name', 'string');
		$this->addType('description', 'string');
		$this->addType('startDatetime', 'string');
		$this->addType('endDatetime', 'string');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'string');
		$this->addType('updatedAt', 'string');
		$this->addType('isActive', 'integer');
		$this->addType('visibleUsers', 'string');
		$this->addType('visibleGroups', 'string');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'name' => $this->getName(),
			'description' => $this->getDescription(),
			'startDatetime' => $this->formatDatetimeToUtc($this->getStartDatetime()),
			'endDatetime' => $this->formatDatetimeToUtc($this->getEndDatetime()),
			'createdBy' => $this->getCreatedBy(),
			'createdAt' => $this->formatDatetimeToUtc($this->getCreatedAt()),
			'updatedAt' => $this->formatDatetimeToUtc($this->getUpdatedAt()),
			'isActive' => $this->getIsActive(),
			'visibleUsers' => $this->parseJsonField($this->getVisibleUsers()),
			'visibleGroups' => $this->parseJsonField($this->getVisibleGroups()),
		];
	}

	/**
	 * Parse JSON field to array, return empty array if null or invalid
	 */
	private function parseJsonField(?string $field): array {
		if ($field === null || $field === '') {
			return [];
		}
		$decoded = json_decode($field, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Format datetime to UTC ISO 8601 format
	 */
	private function formatDatetimeToUtc(string $datetime): string {
		try {
			// Database stores datetime in UTC, so create DateTime object with UTC timezone
			$utcTimezone = new \DateTimeZone('UTC');
			$date = new \DateTime($datetime, $utcTimezone);

			// Return in ISO 8601 format with UTC timezone indicator
			return $date->format('Y-m-d\TH:i:s\Z');
		} catch (\Exception $e) {
			// If parsing fails, return the original value
			return $datetime;
		}
	}
}
