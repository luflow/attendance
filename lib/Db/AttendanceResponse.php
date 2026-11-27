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
 * @method string|null getResponse()
 * @method void setResponse(?string $response)
 * @method string|null getComment()
 * @method void setComment(?string $comment)
 * @method string|null getRespondedAt()
 * @method void setRespondedAt(?string $respondedAt)
 * @method string|null getCheckinState()
 * @method void setCheckinState(?string $checkinState)
 * @method string|null getCheckinComment()
 * @method void setCheckinComment(?string $checkinComment)
 * @method string|null getCheckinBy()
 * @method void setCheckinBy(?string $checkinBy)
 * @method string|null getCheckinAt()
 * @method void setCheckinAt(?string $checkinAt)
 * @method string|null getResponseSource()
 * @method void setResponseSource(?string $responseSource)
 * @method string|null getCheckinSource()
 * @method void setCheckinSource(?string $checkinSource)
 * @method string|null getBookingStatus()
 * @method void setBookingStatus(?string $bookingStatus)
 * @method string|null getBookingNotifiedStatus()
 * @method void setBookingNotifiedStatus(?string $bookingNotifiedStatus)
 * @method string|null getBookingNotifiedAt()
 * @method void setBookingNotifiedAt(?string $bookingNotifiedAt)
 * @method string|null getSpotClaimedAt()
 * @method void setSpotClaimedAt(?string $spotClaimedAt)
 * @method string|null getWaitlistNotifiedStatus()
 * @method void setWaitlistNotifiedStatus(?string $waitlistNotifiedStatus)
 * @method string|null getWaitlistNotifiedAt()
 * @method void setWaitlistNotifiedAt(?string $waitlistNotifiedAt)
 */
class AttendanceResponse extends Entity implements JsonSerializable {
	use DatetimeFormatTrait;
	protected int $appointmentId = 0;
	protected string $userId = '';
	protected ?string $response = '';
	protected ?string $comment = '';
	protected ?string $respondedAt = '';
	protected ?string $checkinState = '';
	protected ?string $checkinComment = '';
	protected ?string $checkinBy = '';
	protected ?string $checkinAt = '';
	protected ?string $responseSource = null;
	protected ?string $checkinSource = null;
	protected ?string $bookingStatus = null;
	protected ?string $bookingNotifiedStatus = null;
	protected ?string $bookingNotifiedAt = null;
	protected ?string $spotClaimedAt = null;
	protected ?string $waitlistNotifiedStatus = null;
	protected ?string $waitlistNotifiedAt = null;

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
		$this->addType('checkinSource', 'string');
		$this->addType('bookingStatus', 'string');
		$this->addType('bookingNotifiedStatus', 'string');
		$this->addType('bookingNotifiedAt', 'string');
		$this->addType('spotClaimedAt', 'string');
		$this->addType('waitlistNotifiedStatus', 'string');
		$this->addType('waitlistNotifiedAt', 'string');
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appointmentId' => $this->getAppointmentId(),
			'userId' => $this->getUserId(),
			'response' => $this->getResponse(),
			'comment' => $this->getComment(),
			'respondedAt' => $this->formatDatetimeToUtc($this->getRespondedAt()),
			'checkinState' => $this->getCheckinState(),
			'checkinComment' => $this->getCheckinComment(),
			'checkinBy' => $this->getCheckinBy(),
			'checkinAt' => $this->formatDatetimeToUtc($this->getCheckinAt()),
			'isCheckedIn' => $this->isCheckedIn(),
			'responseSource' => $this->getResponseSource(),
			'checkinSource' => $this->getCheckinSource(),
			'bookingStatus' => $this->getBookingStatus(),
		];
	}

	/**
	 * Check if this response has been checked in by an admin
	 */
	public function isCheckedIn(): bool {
		$state = $this->getCheckinState();
		return $state !== null && $state !== '';
	}

}
