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
 * @method string getResponse()
 * @method void setResponse(string $response)
 * @method string getComment()
 * @method void setComment(string $comment)
 * @method string getRespondedAt()
 * @method void setRespondedAt(string $respondedAt)
 */
class AttendanceResponse extends Entity implements JsonSerializable {
	protected $appointmentId = 0;
	protected $userId = '';
	protected $response = '';
	protected $comment = '';
	protected $respondedAt = '';

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('appointmentId', 'integer');
		$this->addType('userId', 'string');
		$this->addType('response', 'string');
		$this->addType('comment', 'string');
		$this->addType('respondedAt', 'string');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appointmentId' => $this->getAppointmentId(),
			'userId' => $this->getUserId(),
			'response' => $this->getResponse(),
			'comment' => $this->getComment(),
			'respondedAt' => $this->getRespondedAt(),
		];
	}
}
