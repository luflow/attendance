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
	 * @return array
	 */
	public function findAll(): array {
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

	/**
	 * Find appointments starting within a date range (for reminders)
	 * @param string $startDate Start of range (Y-m-d)
	 * @param string $endDate End of range (Y-m-d)
	 * @return array
	 */
	public function findStartingBetween(string $startDate, string $endDate): array {
		$qb = $this->db->getQueryBuilder();

		// Normalize to full day range: start at 00:00:00, end at 23:59:59
		$startDateTime = $startDate . ' 00:00:00';
		$endDateTime = $endDate . ' 23:59:59';

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)),
					$qb->expr()->gte('start_datetime', $qb->createNamedParameter($startDateTime)),
					$qb->expr()->lte('start_datetime', $qb->createNamedParameter($endDateTime))
				)
			)
			->orderBy('start_datetime', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find appointments linked to a specific calendar event
	 * @param string $calendarEventUid The iCal UID of the calendar event
	 * @param string|null $calendarUri Optional calendar URI to narrow search
	 * @return array<Appointment>
	 */
	public function findByCalendarEventUid(string $calendarEventUid, ?string $calendarUri = null): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)),
					$qb->expr()->eq('calendar_event_uid', $qb->createNamedParameter($calendarEventUid))
				)
			);

		if ($calendarUri !== null) {
			$qb->andWhere(
				$qb->expr()->eq('calendar_uri', $qb->createNamedParameter($calendarUri))
			);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Find appointments with flexible filtering for export functionality
	 *
	 * @param array|null $appointmentIds Specific appointment IDs to export (null for all)
	 * @param string|null $startDate Start date filter (Y-m-d format, inclusive)
	 * @param string|null $endDate End date filter (Y-m-d format, inclusive)
	 * @return array<Appointment>
	 */
	public function findForExport(?array $appointmentIds = null, ?string $startDate = null, ?string $endDate = null): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			);

		// Filter by specific appointment IDs
		if ($appointmentIds !== null && !empty($appointmentIds)) {
			$qb->andWhere(
				$qb->expr()->in('id', $qb->createNamedParameter($appointmentIds, IQueryBuilder::PARAM_INT_ARRAY))
			);
		}

		// Filter by date range (based on start_datetime)
		if ($startDate !== null) {
			$startDateTime = $startDate . ' 00:00:00';
			$qb->andWhere(
				$qb->expr()->gte('start_datetime', $qb->createNamedParameter($startDateTime))
			);
		}

		if ($endDate !== null) {
			$endDateTime = $endDate . ' 23:59:59';
			$qb->andWhere(
				$qb->expr()->lte('start_datetime', $qb->createNamedParameter($endDateTime))
			);
		}

		$qb->orderBy('start_datetime', 'ASC');

		return $this->findEntities($qb);
	}
}
