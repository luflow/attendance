<?php

declare(strict_types=1);

namespace OCA\Attendance\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getStartDate()
 * @method void setStartDate(string $startDate)
 * @method string getEndDate()
 * @method void setEndDate(string $endDate)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class Vacation extends Entity implements JsonSerializable {
	protected $userId = '';
	// Date-only (Y-m-d) on purpose: a vacation is a set of whole days, not a
	// timed window, so there is no timezone conversion to do at read time.
	protected $startDate = '';
	protected $endDate = '';
	protected $note = null;
	protected $createdAt = '';
	protected $updatedAt = '';

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('userId', 'string');
		$this->addType('startDate', 'string');
		$this->addType('endDate', 'string');
		$this->addType('note', 'string');
		$this->addType('createdAt', 'string');
		$this->addType('updatedAt', 'string');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'startDate' => $this->getStartDate(),
			'endDate' => $this->getEndDate(),
			'note' => $this->getNote(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
