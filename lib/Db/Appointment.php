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
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'name' => $this->getName(),
			'description' => $this->getDescription(),
			'startDatetime' => $this->getStartDatetime(),
			'endDatetime' => $this->getEndDatetime(),
			'createdBy' => $this->getCreatedBy(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
			'isActive' => $this->getIsActive(),
		];
	}
}
