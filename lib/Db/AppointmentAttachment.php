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
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method string getFilePath()
 * @method void setFilePath(string $filePath)
 * @method string getAddedBy()
 * @method void setAddedBy(string $addedBy)
 * @method string getAddedAt()
 * @method void setAddedAt(string $addedAt)
 */
class AppointmentAttachment extends Entity implements JsonSerializable {
	protected $appointmentId;
	protected $fileId;
	protected $fileName = '';
	protected $filePath = '';
	protected $addedBy = '';
	protected $addedAt = '';

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('appointmentId', 'integer');
		$this->addType('fileId', 'integer');
		$this->addType('fileName', 'string');
		$this->addType('filePath', 'string');
		$this->addType('addedBy', 'string');
		$this->addType('addedAt', 'string');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appointmentId' => $this->getAppointmentId(),
			'fileId' => $this->getFileId(),
			'fileName' => $this->getFileName(),
			'filePath' => $this->getFilePath(),
			'addedBy' => $this->getAddedBy(),
			'addedAt' => $this->formatDatetimeToUtc($this->getAddedAt()),
		];
	}

	/**
	 * Format datetime to UTC ISO 8601 format
	 */
	private function formatDatetimeToUtc(string $datetime): string {
		try {
			$utcTimezone = new \DateTimeZone('UTC');
			$date = new \DateTime($datetime, $utcTimezone);
			return $date->format('Y-m-d\TH:i:s\Z');
		} catch (\Exception $e) {
			return $datetime;
		}
	}
}
