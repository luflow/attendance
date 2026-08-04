<?php

declare(strict_types=1);

namespace OCA\Attendance\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Category>
 */
class CategoryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'att_categories', Category::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Category {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id))
			);

		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByName(string $name): Category {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('name', $qb->createNamedParameter($name))
			);

		return $this->findEntity($qb);
	}

	/**
	 * @return list<Category>
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->orderBy('name', 'ASC');

		return $this->findEntities($qb);
	}
}
