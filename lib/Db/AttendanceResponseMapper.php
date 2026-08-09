<?php

declare(strict_types=1);

namespace OCA\Attendance\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
	 * The few columns the statistics evaluate, for a set of appointments, in
	 * one query. Deliberately not entities: at the 1000-appointment cap this
	 * can be hundreds of thousands of rows, and hydrating all 15 columns of
	 * each costs far more than the evaluation itself.
	 *
	 * @param list<int> $appointmentIds
	 * @param ?string $userId Restrict to one person, for the drill-down
	 * @param bool $withComments Read the comment column too — only the drill-down can afford to, it being one person's rows
	 * @return list<array{appointmentId: int, userId: string, response: ?string, checkinState: ?string, comment: ?string}>
	 */
	public function findStatisticsRows(array $appointmentIds, ?string $userId = null, bool $withComments = false): array {
		if ($appointmentIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('appointment_id', 'user_id', 'response', 'checkin_state')
			->from($this->getTableName())
			->where(
				$qb->expr()->in('appointment_id', $qb->createNamedParameter($appointmentIds, IQueryBuilder::PARAM_INT_ARRAY))
			);

		if ($withComments) {
			$qb->addSelect('comment');
		}

		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}

		$result = $qb->executeQuery();
		/** @var list<array<string, mixed>> $raw */
		$raw = $result->fetchAll();
		$rows = [];
		foreach ($raw as $row) {
			$rows[] = [
				'appointmentId' => (int)$row['appointment_id'],
				'userId' => (string)$row['user_id'],
				'response' => $row['response'] !== null ? (string)$row['response'] : null,
				'checkinState' => $row['checkin_state'] !== null ? (string)$row['checkin_state'] : null,
				'comment' => isset($row['comment']) ? (string)$row['comment'] : null,
			];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Which of these appointments had their check-in list worked at all.
	 *
	 * @param list<int> $appointmentIds
	 * @return list<int>
	 */
	public function findAppointmentIdsWithCheckins(array $appointmentIds): array {
		if ($appointmentIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('appointment_id')
			->from($this->getTableName())
			->where(
				$qb->expr()->in('appointment_id', $qb->createNamedParameter($appointmentIds, IQueryBuilder::PARAM_INT_ARRAY))
			)
			->andWhere(
				$qb->expr()->in('checkin_state', $qb->createNamedParameter(['yes', 'no'], IQueryBuilder::PARAM_STR_ARRAY))
			);

		$result = $qb->executeQuery();
		/** @var list<array<string, mixed>> $raw */
		$raw = $result->fetchAll();
		$result->closeCursor();

		return array_map(static fn (array $row): int => (int)$row['appointment_id'], $raw);
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
			->set('checkin_source', $qb->createNamedParameter(null, \Doctrine\DBAL\ParameterType::NULL))
			->where($qb->expr()->eq('appointment_id', $qb->createNamedParameter($appointmentId)));
		$qb->executeStatement();
	}
}
