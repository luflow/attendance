<?php

declare(strict_types=1);

namespace OCA\Attendance\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getAppointmentId()
 * @method void setAppointmentId(int $appointmentId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getRemindedAt()
 * @method void setRemindedAt(string $remindedAt)
 */
class ReminderLog extends Entity implements JsonSerializable {
	protected $appointmentId = 0;
	protected $userId = '';
	protected $remindedAt = '';

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('appointmentId', 'integer');
		$this->addType('userId', 'string');
		$this->addType('remindedAt', 'string');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appointmentId' => $this->getAppointmentId(),
			'userId' => $this->getUserId(),
			'remindedAt' => $this->getRemindedAt(),
		];
	}
}
