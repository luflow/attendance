<?php

declare(strict_types=1);

namespace OCA\Attendance\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Vacation>
 */
class VacationMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'att_vacations', Vacation::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Vacation {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id))
			);

		return $this->findEntity($qb);
	}

	/**
	 * @return list<Vacation>
	 */
	public function findByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId))
			)
			->orderBy('start_date', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Vacations covering any day of [$startDate, $endDate] (both Y-m-d,
	 * inclusive), optionally restricted to a set of users — the shared query
	 * behind the create-appointment hint, the team overview, and the
	 * auto-"no" response check.
	 *
	 * @param list<string> $userIds Empty means "any user"
	 * @return list<Vacation>
	 */
	public function findOverlapping(string $startDate, string $endDate, array $userIds = []): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->lte('start_date', $qb->createNamedParameter($endDate))
			)
			->andWhere(
				$qb->expr()->gte('end_date', $qb->createNamedParameter($startDate))
			)
			->orderBy('start_date', 'ASC');

		if ($userIds !== []) {
			$qb->andWhere(
				$qb->expr()->in('user_id', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY))
			);
		}

		return $this->findEntities($qb);
	}
}
