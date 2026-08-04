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
 * @method string getIcon()
 * @method void setIcon(string $icon)
 */
class Category extends Entity implements JsonSerializable {
	protected $name = '';
	protected $icon = 'tag';

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('name', 'string');
		$this->addType('icon', 'string');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'name' => $this->getName(),
			'icon' => $this->getIcon(),
		];
	}
}
