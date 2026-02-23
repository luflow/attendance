<?php

declare(strict_types=1);

namespace OCA\Attendance\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<AttendanceResponse>
 */
class AttendanceResponseMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'att_responses', AttendanceResponse::class);
	}

	/**
	 * @param int $id
	 * @return AttendanceResponse
	 * @throws DoesNotExistException
	 */
	public function find(int $id): AttendanceResponse {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id))
			);

		return $this->findEntity($qb);
	}

	/**
	 * @param int $appointmentId
	 * @param string $userId
	 * @return AttendanceResponse
	 * @throws DoesNotExistException
	 */
	public function findByAppointmentAndUser(int $appointmentId, string $userId): AttendanceResponse {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('appointment_id', $qb->createNamedParameter($appointmentId)),
					$qb->expr()->eq('user_id', $qb->createNamedParameter($userId))
				)
			);

		return $this->findEntity($qb);
	}

	/**
	 * @param int $appointmentId
	 * @return array
	 */
	public function findByAppointment(int $appointmentId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('appointment_id', $qb->createNamedParameter($appointmentId))
			)
			->orderBy('responded_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function findByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId))
			)
			->orderBy('responded_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * @param int $appointmentId
	 * @return array
	 */
	public function getResponseSummary(int $appointmentId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('response')
			->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('appointment_id', $qb->createNamedParameter($appointmentId))
			)
			->groupBy('response');

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();

		$summary = ['yes' => 0, 'no' => 0, 'maybe' => 0];
		foreach ($rows as $row) {
			$summary[$row['response']] = (int)$row['count'];
		}

		return $summary;
	}

	/**
	 * Reset all checkin fields for a given appointment.
	 *
	 * @param int $appointmentId
	 */
	public function resetCheckinByAppointment(int $appointmentId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('checkin_state', $qb->createNamedParameter(null, \Doctrine\DBAL\ParameterType::NULL))
			->set('checkin_comment', $qb->createNamedParameter(null, \Doctrine\DBAL\ParameterType::NULL))
			->set('checkin_by', $qb->createNamedParameter(null, \Doctrine\DBAL\ParameterType::NULL))
			->set('checkin_at', $qb->createNamedParameter(null, \Doctrine\DBAL\ParameterType::NULL))
			->where($qb->expr()->eq('appointment_id', $qb->createNamedParameter($appointmentId)));
		$qb->executeStatement();
	}
}
