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
 * @method ?string getTemplate()
 * @method void setTemplate(?string $template)
 */
class Category extends Entity implements JsonSerializable {
	protected $name = '';
	protected $icon = 'tag';
	/** @var ?string */
	protected $template = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('name', 'string');
		$this->addType('icon', 'string');
		$this->addType('template', 'string');
	}

	/**
	 * @return array{id: int, name: string, icon: string}
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'name' => $this->getName(),
			'icon' => $this->getIcon(),
		];
	}
}
