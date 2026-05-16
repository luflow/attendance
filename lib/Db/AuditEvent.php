<?php

declare(strict_types=1);

namespace OCA\Attendance\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getAppointmentId()
 * @method void setAppointmentId(int $appointmentId)
 * @method string getVerb()
 * @method void setVerb(string $verb)
 * @method string|null getActorId()
 * @method void setActorId(?string $actorId)
 * @method string|null getSubjectId()
 * @method void setSubjectId(?string $subjectId)
 * @method string|null getMeta()
 * @method void setMeta(?string $meta)
 * @method string|null getSource()
 * @method void setSource(?string $source)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class AuditEvent extends Entity implements JsonSerializable {
	use DatetimeFormatTrait;

	protected $appointmentId = 0;
	protected $verb = '';
	protected $actorId = null;
	protected $subjectId = null;
	protected $meta = null;
	protected $source = null;
	protected $createdAt = '';

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('appointmentId', 'integer');
		$this->addType('verb', 'string');
		$this->addType('actorId', 'string');
		$this->addType('subjectId', 'string');
		$this->addType('meta', 'string');
		$this->addType('source', 'string');
		$this->addType('createdAt', 'string');
	}

	public function getMetaArray(): array {
		$raw = $this->getMeta();
		if ($raw === null || $raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}

	public function setMetaArray(array $meta): void {
		$this->setMeta($meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE));
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appointmentId' => $this->getAppointmentId(),
			'verb' => $this->getVerb(),
			'actorId' => $this->getActorId(),
			'subjectId' => $this->getSubjectId(),
			'meta' => $this->getMetaArray(),
			'source' => $this->getSource(),
			'createdAt' => $this->formatDatetimeToUtc($this->getCreatedAt()),
		];
	}
}
