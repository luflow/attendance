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
 * @method string getVisibleTeams()
 * @method void setVisibleTeams(string $visibleTeams)
 * @method string|null getCalendarUri()
 * @method void setCalendarUri(?string $calendarUri)
 * @method string|null getCalendarEventUid()
 * @method void setCalendarEventUid(?string $calendarEventUid)
 * @method string|null getSeriesId()
 * @method void setSeriesId(?string $seriesId)
 * @method int|null getSeriesPosition()
 * @method void setSeriesPosition(?int $seriesPosition)
 * @method bool getSendNotification()
 * @method void setSendNotification(bool $sendNotification)
 */
class Appointment extends Entity implements JsonSerializable {
	use DatetimeFormatTrait;
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
	protected $visibleTeams = null;
	protected $calendarUri = null;
	protected $calendarEventUid = null;
	protected $seriesId = null;
	protected $seriesPosition = null;
	protected $sendNotification = false;

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
		$this->addType('visibleTeams', 'string');
		$this->addType('calendarUri', 'string');
		$this->addType('calendarEventUid', 'string');
		$this->addType('seriesId', 'string');
		$this->addType('seriesPosition', 'integer');
		$this->addType('sendNotification', 'boolean');
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
			'visibleTeams' => $this->parseJsonField($this->getVisibleTeams()),
			'calendarUri' => $this->getCalendarUri(),
			'calendarEventUid' => $this->getCalendarEventUid(),
			'seriesId' => $this->getSeriesId(),
			'seriesPosition' => $this->getSeriesPosition(),
			'sendNotification' => (bool) $this->getSendNotification(),
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

}
