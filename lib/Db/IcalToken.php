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
 * @method string getToken()
 * @method void setToken(string $token)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getLastUsedAt()
 * @method void setLastUsedAt(?string $lastUsedAt)
 */
class IcalToken extends Entity implements JsonSerializable {
	protected $userId = '';
	protected $token = '';
	protected $createdAt = '';
	protected $lastUsedAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('userId', 'string');
		$this->addType('token', 'string');
		$this->addType('createdAt', 'string');
		$this->addType('lastUsedAt', 'string');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'createdAt' => $this->getCreatedAt(),
			'lastUsedAt' => $this->getLastUsedAt(),
		];
	}
}
