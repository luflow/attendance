<?php

declare(strict_types=1);

namespace OCA\Attendance\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
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
			->orderBy('added_at', 'ASC');

		return $this->findEntities($qb);
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
