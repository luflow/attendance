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
					$qb->expr()->gte('end_datetime', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
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
					$qb->expr()->lt('end_datetime', $qb->createNamedParameter(gmdate('Y-m-d H:i:s')))
				)
			)
			->orderBy('start_datetime', 'DESC'); // Newest first for past appointments

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
	 * Find imported calendar event identifiers for a given calendar.
	 * Returns calendarEventUid + startDatetime pairs for building occurrence IDs.
	 *
	 * @param string $calendarUri Calendar URI to filter by
	 * @return list<array{calendar_event_uid: string, start_datetime: string}>
	 */
	public function findImportedByCalendarUri(string $calendarUri): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('calendar_event_uid', 'start_datetime')
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)),
					$qb->expr()->eq('calendar_uri', $qb->createNamedParameter($calendarUri)),
					$qb->expr()->isNotNull('calendar_event_uid'),
					$qb->expr()->neq('calendar_event_uid', $qb->createNamedParameter(''))
				)
			);

		return $qb->executeQuery()->fetchAll();
	}

	/**
	 * Find active appointments within a time window around now.
	 * Used for self-check-in: returns appointments that are currently happening
	 * or about to start within the given window.
	 *
	 * @param int $windowMinutes Minutes before start_datetime to include (default 30)
	 * @return array<Appointment>
	 */
	public function findActiveInWindow(int $windowMinutes = 30): array {
		$qb = $this->db->getQueryBuilder();

		$now = new \DateTime('now', new \DateTimeZone('UTC'));
		$windowStart = (clone $now)->modify("-{$windowMinutes} minutes");

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)),
					// start_datetime <= NOW + windowMinutes (appointment has started or starts soon)
					$qb->expr()->lte('start_datetime', $qb->createNamedParameter($now->modify("+{$windowMinutes} minutes")->format('Y-m-d H:i:s'))),
					// end_datetime >= NOW (appointment hasn't ended yet)
					$qb->expr()->gte('end_datetime', $qb->createNamedParameter((new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')))
				)
			)
			->orderBy('start_datetime', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find all active appointments in a series.
	 *
	 * @param string $seriesId The series UUID
	 * @return array<Appointment>
	 */
	public function findBySeriesId(string $seriesId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)),
					$qb->expr()->eq('series_id', $qb->createNamedParameter($seriesId))
				)
			)
			->orderBy('series_position', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find active appointments in a series from a given position onward.
	 *
	 * @param string $seriesId The series UUID
	 * @param int $fromPosition The minimum series_position (inclusive)
	 * @return array<Appointment>
	 */
	public function findBySeriesIdFromPosition(string $seriesId, int $fromPosition): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)),
					$qb->expr()->eq('series_id', $qb->createNamedParameter($seriesId)),
					$qb->expr()->gte('series_position', $qb->createNamedParameter($fromPosition, IQueryBuilder::PARAM_INT))
				)
			)
			->orderBy('series_position', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find appointments eligible for reminders within the given date range:
	 * active, not closed, and either anchored on response_deadline (when set)
	 * or on start_datetime (otherwise). Single OR-query — one round-trip.
	 *
	 * @param string $startDate Y-m-d (inclusive)
	 * @param string $endDate Y-m-d (inclusive)
	 * @return array<Appointment>
	 */
	public function findRemindable(string $startDate, string $endDate): array {
		$qb = $this->db->getQueryBuilder();

		$startDateTime = $startDate . ' 00:00:00';
		$endDateTime = $endDate . ' 23:59:59';

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)),
					$qb->expr()->isNull('closed_at'),
					$qb->expr()->orX(
						$qb->expr()->andX(
							$qb->expr()->isNotNull('response_deadline'),
							$qb->expr()->gte('response_deadline', $qb->createNamedParameter($startDateTime)),
							$qb->expr()->lte('response_deadline', $qb->createNamedParameter($endDateTime)),
						),
						$qb->expr()->andX(
							$qb->expr()->isNull('response_deadline'),
							$qb->expr()->gte('start_datetime', $qb->createNamedParameter($startDateTime)),
							$qb->expr()->lte('start_datetime', $qb->createNamedParameter($endDateTime)),
						),
					),
				)
			)
			->orderBy('start_datetime', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Bulk-close all active inquiries whose response_deadline or start_datetime
	 * is at or before the given timestamp. Once an appointment has started,
	 * further responses are pointless, so it gets closed regardless of any
	 * configured deadline. Returns the affected row count.
	 */
	public function autoCloseExpired(string $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('closed_at', $qb->createNamedParameter($now))
			->set('updated_at', $qb->createNamedParameter($now))
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)),
					$qb->expr()->isNull('closed_at'),
					$qb->expr()->orX(
						$qb->expr()->andX(
							$qb->expr()->isNotNull('response_deadline'),
							$qb->expr()->lte('response_deadline', $qb->createNamedParameter($now)),
						),
						$qb->expr()->lte('start_datetime', $qb->createNamedParameter($now)),
					),
				)
			);
		return (int)$qb->executeStatement();
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
