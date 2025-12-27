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
 * @method string getCheckinState()
 * @method void setCheckinState(string $checkinState)
 * @method string getCheckinComment()
 * @method void setCheckinComment(string $checkinComment)
 * @method string getCheckinBy()
 * @method void setCheckinBy(string $checkinBy)
 * @method string getCheckinAt()
 * @method void setCheckinAt(string $checkinAt)
 * @method string|null getResponseSource()
 * @method void setResponseSource(?string $responseSource)
 */
class AttendanceResponse extends Entity implements JsonSerializable {
	protected $appointmentId = 0;
	protected $userId = '';
	protected $response = '';
	protected $comment = '';
	protected $respondedAt = '';
	protected $checkinState = '';
	protected $checkinComment = '';
	protected $checkinBy = '';
	protected $checkinAt = '';
	protected $responseSource = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('appointmentId', 'integer');
		$this->addType('userId', 'string');
		$this->addType('response', 'string');
		$this->addType('comment', 'string');
		$this->addType('respondedAt', 'string');
		$this->addType('checkinState', 'string');
		$this->addType('checkinComment', 'string');
		$this->addType('checkinBy', 'string');
		$this->addType('checkinAt', 'string');
		$this->addType('responseSource', 'string');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appointmentId' => $this->getAppointmentId(),
			'userId' => $this->getUserId(),
			'response' => $this->getResponse(),
			'comment' => $this->getComment(),
			'respondedAt' => $this->getRespondedAt(),
			'checkinState' => $this->getCheckinState(),
			'checkinComment' => $this->getCheckinComment(),
			'checkinBy' => $this->getCheckinBy(),
			'checkinAt' => $this->getCheckinAt(),
			'isCheckedIn' => $this->isCheckedIn(),
			'responseSource' => $this->getResponseSource(),
		];
	}


	/**
	 * Check if this response has been checked in by an admin
	 */
	public function isCheckedIn(): bool {
		return !empty($this->getCheckinState());
	}
}
