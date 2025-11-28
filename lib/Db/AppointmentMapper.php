<?php

declare(strict_types=1);

namespace OCA\Attendance\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Appointment>
 */
class AppointmentMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'att_appointments', Appointment::class);
	}

	/**
	 * @param int $id
	 * @return Appointment
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Appointment {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id))
			);

		return $this->findEntity($qb);
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function findAll(string $userId = ''): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			)
			->orderBy('start_datetime', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @param string $createdBy
	 * @return array
	 */
	public function findByCreatedBy(string $createdBy): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('created_by', $qb->createNamedParameter($createdBy))
			)
			->orderBy('start_datetime', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @return array
	 */
	public function findUpcoming(): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)),
					$qb->expr()->gte('end_datetime', $qb->createNamedParameter(date('Y-m-d H:i:s')))
				)
			)
			->orderBy('start_datetime', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find past appointments (end_datetime < now)
	 * @return array
	 */
	public function findPast(): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)),
					$qb->expr()->lt('end_datetime', $qb->createNamedParameter(date('Y-m-d H:i:s')))
				)
			)
			->orderBy('start_datetime', 'DESC'); // Newest first for past appointments

		return $this->findEntities($qb);
	}
}
