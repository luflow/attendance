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

	protected int $appointmentId = 0;
	protected string $verb = '';
	protected ?string $actorId = null;
	protected ?string $subjectId = null;
	protected ?string $meta = null;
	protected ?string $source = null;
	protected string $createdAt = '';

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

	/**
	 * @return array<string, mixed>
	 */
	public function getMetaArray(): array {
		$raw = $this->getMeta();
		if ($raw === null || $raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		return array_combine(array_map('strval', array_keys($decoded)), $decoded);
	}

	public function setMetaArray(array $meta): void {
		$encoded = $meta === [] ? false : json_encode($meta, JSON_UNESCAPED_UNICODE);
		// Unencodable meta (e.g. invalid UTF-8) drops the meta, not the whole event.
		$this->setMeta($encoded === false ? null : $encoded);
	}

	/**
	 * @return array{id: int, appointmentId: int, verb: string, actorId: ?string, subjectId: ?string, meta: array<string, mixed>, source: ?string, createdAt: ?string}
	 */
	#[\Override]
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
