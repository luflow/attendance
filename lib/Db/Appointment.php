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
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
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
 * @method int getIsActive()
 * @method void setIsActive(int $isActive)
 * @method string getVisibleUsers()
 * @method void setVisibleUsers(string $visibleUsers)
 * @method string getVisibleGroups()
 * @method void setVisibleGroups(string $visibleGroups)
 * @method string getVisibleTeams()
 * @method void setVisibleTeams(string $visibleTeams)
 * @method string|null getOrganizers()
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
 * @method string|null getClosedAt()
 * @method void setClosedAt(?string $closedAt)
 * @method string|null getCancelledAt()
 * @method void setCancelledAt(?string $cancelledAt)
 * @method string|null getResponseDeadline()
 * @method void setResponseDeadline(?string $responseDeadline)
 * @method string|null getLocation()
 * @method void setLocation(?string $location)
 * @method int|null getCategoryId()
 * @method void setCategoryId(?int $categoryId)
 * @method bool getCreateTalkRoom()
 * @method void setCreateTalkRoom(bool $createTalkRoom)
 * @method string|null getTalkRoomToken()
 * @method void setTalkRoomToken(?string $talkRoomToken)
 * @method bool|null getAllowMaybe()
 * @method void setAllowMaybe(?bool $allowMaybe)
 * @method int|null getMaxAttendees()
 * @method void setMaxAttendees(?int $maxAttendees)
 * @method bool getWaitlistEnabled()
 * @method void setWaitlistEnabled(bool $waitlistEnabled)
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
	protected $organizers = null;
	protected $calendarUri = null;
	protected $calendarEventUid = null;
	protected $seriesId = null;
	protected $seriesPosition = null;
	protected $sendNotification = false;
	protected $closedAt = null;
	protected $cancelledAt = null;
	protected $responseDeadline = null;
	protected $location = null;
	protected $categoryId = null;
	protected $createTalkRoom = false;
	protected $talkRoomToken = null;
	protected $allowMaybe = null;
	protected $maxAttendees = null;
	protected $waitlistEnabled = true;

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
		$this->addType('organizers', 'string');
		$this->addType('calendarUri', 'string');
		$this->addType('calendarEventUid', 'string');
		$this->addType('seriesId', 'string');
		$this->addType('seriesPosition', 'integer');
		$this->addType('sendNotification', 'boolean');
		$this->addType('closedAt', 'string');
		$this->addType('cancelledAt', 'string');
		$this->addType('responseDeadline', 'string');
		$this->addType('location', 'string');
		$this->addType('categoryId', 'integer');
		$this->addType('createTalkRoom', 'boolean');
		$this->addType('talkRoomToken', 'string');
		$this->addType('allowMaybe', 'boolean');
		$this->addType('maxAttendees', 'integer');
		$this->addType('waitlistEnabled', 'boolean');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'name' => $this->getName(),
			// Nullable column, but AttendanceAppointmentData promises a string.
			'description' => $this->getDescription() ?? '',
			'startDatetime' => $this->formatDatetimeToUtc($this->getStartDatetime()),
			'endDatetime' => $this->formatDatetimeToUtc($this->getEndDatetime()),
			'createdBy' => $this->getCreatedBy(),
			'createdAt' => $this->formatDatetimeToUtc($this->getCreatedAt()),
			'updatedAt' => $this->formatDatetimeToUtc($this->getUpdatedAt()),
			'isActive' => $this->getIsActive(),
			'visibleUsers' => $this->parseJsonField($this->getVisibleUsers()),
			'visibleGroups' => $this->parseJsonField($this->getVisibleGroups()),
			'visibleTeams' => $this->parseJsonField($this->getVisibleTeams()),
			'organizers' => $this->getOrganizersList(),
			'calendarUri' => $this->getCalendarUri(),
			'calendarEventUid' => $this->getCalendarEventUid(),
			'seriesId' => $this->getSeriesId(),
			'seriesPosition' => $this->getSeriesPosition(),
			'sendNotification' => (bool)$this->getSendNotification(),
			'closedAt' => $this->formatDatetimeToUtc($this->getClosedAt()),
			'cancelledAt' => $this->formatDatetimeToUtc($this->getCancelledAt()),
			'responseDeadline' => $this->formatDatetimeToUtc($this->getResponseDeadline()),
			'location' => $this->getLocation(),
			'categoryId' => $this->getCategoryId(),
			'createTalkRoom' => $this->getCreateTalkRoom(),
			'talkRoomToken' => $this->getTalkRoomToken(),
			// Tri-state in the column, resolved against the instance default
			// before it reaches a client — see AppointmentService::serializeAppointment().
			'allowMaybe' => $this->getAllowMaybe(),
			'maxAttendees' => $this->getMaxAttendees(),
			'waitlistEnabled' => $this->getWaitlistEnabled(),
		];
	}

	/** @var list<string>|null memoized decoded organizers — the list endpoints
	 * call getOrganizersList() several times per appointment (visibility,
	 * myPermissions, serialization); decode once per entity. */
	private ?array $organizersListCache = null;

	public function setOrganizers(?string $organizers): void {
		$this->organizersListCache = null;
		$this->setter('organizers', [$organizers]);
	}

	/**
	 * Organizer user IDs of this appointment.
	 *
	 * @return list<string>
	 */
	public function getOrganizersList(): array {
		return $this->organizersListCache
			??= array_values(array_map('strval', $this->parseJsonField($this->getOrganizers())));
	}

	public function isClosed(): bool {
		return $this->getClosedAt() !== null;
	}

	public function isCancelled(): bool {
		return $this->getCancelledAt() !== null;
	}

	/**
	 * Over, i.e. past its end — the same cut AppointmentMapper::findPast()
	 * makes in SQL. An appointment that is currently running is not past.
	 */
	public function isPast(): bool {
		return $this->getEndDatetime() < gmdate('Y-m-d H:i:s');
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
