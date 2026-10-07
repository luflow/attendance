<?php

declare(strict_types=1);

namespace OCA\Attendance\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<AppointmentAttachment>
 */
class AppointmentAttachmentMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'att_attachments', AppointmentAttachment::class);
	}

	/**
	 * @param int $id
	 * @return AppointmentAttachment
	 * @throws DoesNotExistException
	 */
	public function find(int $id): AppointmentAttachment {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id))
			);

		return $this->findEntity($qb);
	}

	/**
	 * @return list<AppointmentAttachment>
	 */
	public function findByAppointment(int $appointmentId): array {
		return $this->findByAppointments([$appointmentId])[$appointmentId] ?? [];
	}

	/**
	 * The attachments of these appointments, bucketed by appointment, oldest first.
	 *
	 * @param list<int> $appointmentIds
	 * @return array<int, list<AppointmentAttachment>>
	 */
	public function findByAppointments(array $appointmentIds): array {
		$byAppointment = [];

		foreach (array_chunk($appointmentIds, 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->in('appointment_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('added_at', 'ASC')
				->addOrderBy('id', 'ASC');

			foreach ($this->findEntities($qb) as $attachment) {
				$byAppointment[$attachment->getAppointmentId()][] = $attachment;
			}
		}

		return $byAppointment;
	}

	/**
	 * @param int $appointmentId
	 * @param int $fileId
	 * @return AppointmentAttachment
	 * @throws DoesNotExistException
	 */
	public function findByAppointmentAndFile(int $appointmentId, int $fileId): AppointmentAttachment {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('appointment_id', $qb->createNamedParameter($appointmentId)),
					$qb->expr()->eq('file_id', $qb->createNamedParameter($fileId))
				)
			);

		return $this->findEntity($qb);
	}

	/**
	 * Delete all attachments for an appointment
	 * @param int $appointmentId
	 */
	public function deleteByAppointment(int $appointmentId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->eq('appointment_id', $qb->createNamedParameter($appointmentId))
			);

		$qb->executeStatement();
	}
}
