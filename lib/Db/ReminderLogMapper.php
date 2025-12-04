<?php

declare(strict_types=1);

namespace OCA\Attendance\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ReminderLog>
 */
class ReminderLogMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'att_reminder_log', ReminderLog::class);
	}

	/**
	 * Find the most recent reminder for a user and appointment
	 * 
	 * @param int $appointmentId
	 * @param string $userId
	 * @return ReminderLog|null
	 */
	public function findLatestForUser(int $appointmentId, string $userId): ?ReminderLog {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('appointment_id', $qb->createNamedParameter($appointmentId)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('reminded_at', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Find all reminders for an appointment
	 * 
	 * @param int $appointmentId
	 * @return ReminderLog[]
	 */
	public function findByAppointment(int $appointmentId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('appointment_id', $qb->createNamedParameter($appointmentId)))
			->orderBy('reminded_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Delete all reminders for an appointment (useful for cleanup)
	 * 
	 * @param int $appointmentId
	 * @return int Number of deleted rows
	 */
	public function deleteByAppointment(int $appointmentId): int {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('appointment_id', $qb->createNamedParameter($appointmentId)));

		return $qb->executeStatement();
	}

	/**
	 * Delete old reminder logs (older than a certain date)
	 * 
	 * @param string $beforeDate Date in 'Y-m-d H:i:s' format
	 * @return int Number of deleted rows
	 */
	public function deleteOldReminders(string $beforeDate): int {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('reminded_at', $qb->createNamedParameter($beforeDate)));

		return $qb->executeStatement();
	}
}
