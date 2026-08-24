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
	 * Whether the instance holds any active appointment at all. Drives the
	 * onboarding entry point, so it must not be narrowed by visibility — a
	 * restricted appointment still means the instance is in use.
	 */
	public function hasAny(): bool {
		$qb = $this->db->getQueryBuilder();

		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		/** @var array<string, mixed>|false $row IResult::fetch() is declared mixed */
		$row = $result->fetch();
		$result->closeCursor();

		return $row !== false;
	}

	/**
	 * Whether the user is listed as organizer on at least one active
	 * appointment. LIKE on the JSON column is good enough here: user IDs are
	 * stored JSON-encoded in double quotes, so matching `"uid"` cannot hit a
	 * partial ID, and the query only runs on low-frequency paths (personal
	 * settings, search gate).
	 */
	public function existsWithOrganizer(string $userId): bool {
		$qb = $this->db->getQueryBuilder();

		$needle = '%' . $this->db->escapeLikeParameter(json_encode($userId)) . '%';
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->like('organizers', $qb->createNamedParameter($needle)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row !== false;
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
					$qb->expr()->isNull('cancelled_at'),
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
	 * Find the next active, non-cancelled appointments whose self-check-in
	 * window has not opened yet (start_datetime > now + window). Limited,
	 * because the caller only needs the first one visible to a user.
	 *
	 * @return array<Appointment>
	 */
	public function findUpcomingOutsideWindow(int $windowMinutes, int $limit = 25): array {
		$qb = $this->db->getQueryBuilder();

		$windowEdge = (new \DateTime('now', new \DateTimeZone('UTC')))
			->modify("+{$windowMinutes} minutes");

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)),
					$qb->expr()->isNull('cancelled_at'),
					$qb->expr()->gt('start_datetime', $qb->createNamedParameter($windowEdge->format('Y-m-d H:i:s')))
				)
			)
			->orderBy('start_datetime', 'ASC')
			->setMaxResults($limit);

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
					$qb->expr()->isNull('cancelled_at'),
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
	 * Active appointments with a Talk room that ended no earlier than the given
	 * timestamp. Bounded on purpose: the reconcile sweep would otherwise re-walk
	 * the instance's entire history every hour, and once an appointment is over
	 * its membership cannot change any more.
	 *
	 * @return list<Appointment>
	 */
	public function findWithTalkRoom(string $endedAfter): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('talk_room_token'))
			->andWhere($qb->expr()->gte('end_datetime', $qb->createNamedParameter($endedAfter)));

		return $this->findEntities($qb);
	}

	/**
	 * Active appointments linked to this Talk room token. Normally at most
	 * one — a list defends against the token somehow surviving on more than
	 * one row rather than assuming it can't.
	 *
	 * @return list<Appointment>
	 */
	public function findByTalkRoomToken(string $token): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('talk_room_token', $qb->createNamedParameter($token)));

		return $this->findEntities($qb);
	}

	/**
	 * Active inquiries whose response_deadline or start_datetime is at or
	 * before the given timestamp. Once an appointment has started, further
	 * responses are pointless, so it qualifies regardless of any configured
	 * deadline. Selects only — see AutoCloseJob for why closing is per row.
	 *
	 * @return list<int>
	 */
	public function findDueForAutoClose(string $now): array {
		$select = $this->db->getQueryBuilder();
		$select->select('id')
			->from($this->getTableName())
			->where(
				$select->expr()->andX(
					$select->expr()->eq('is_active', $select->createNamedParameter(1, IQueryBuilder::PARAM_INT)),
					$select->expr()->isNull('closed_at'),
					$select->expr()->isNull('cancelled_at'),
					$select->expr()->orX(
						$select->expr()->andX(
							$select->expr()->isNotNull('response_deadline'),
							$select->expr()->lte('response_deadline', $select->createNamedParameter($now)),
						),
						$select->expr()->lte('start_datetime', $select->createNamedParameter($now)),
					),
				)
			);
		$result = $select->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * Find active appointments that have a location set, most recent first.
	 * Feeds the location-suggestion endpoint; visibility is filtered by the
	 * caller since it depends on the requesting user. Bounded well above the
	 * suggestion count the caller dedupes down to, so a long-running instance
	 * with years of history never triggers an unbounded table scan.
	 *
	 * @return array<Appointment>
	 */
	public function findWithLocation(int $limit = 300): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)),
					$qb->expr()->isNotNull('location'),
					$qb->expr()->neq('location', $qb->createNamedParameter(''))
				)
			)
			->orderBy('start_datetime', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Clear a deleted category from every appointment that referenced it, so
	 * no appointment is left pointing at a category that no longer exists.
	 */
	public function clearCategory(int $categoryId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->update($this->getTableName())
			->set('category_id', $qb->createNamedParameter(null))
			->where(
				$qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT))
			);

		$qb->executeStatement();
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

	/**
	 * Appointments the statistics evaluate: active, not cancelled, within the
	 * date range and matching the category filter.
	 *
	 * @param list<int> $categoryIds Categories to include; empty means no category filter
	 * @param bool $includeUncategorized Also include appointments without a category
	 * @param ?int $limit Stop after this many rows, so an over-large range is
	 *                    detected without hydrating everything behind it
	 * @return list<Appointment>
	 */
	public function findForStatistics(
		?string $startDate,
		?string $endDate,
		array $categoryIds = [],
		bool $includeUncategorized = false,
		?int $limit = null,
	): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('cancelled_at'));

		if ($startDate !== null) {
			$qb->andWhere($qb->expr()->gte('start_datetime', $qb->createNamedParameter($startDate . ' 00:00:00')));
		}

		if ($endDate !== null) {
			$qb->andWhere($qb->expr()->lte('start_datetime', $qb->createNamedParameter($endDate . ' 23:59:59')));
		}

		$categoryFilters = [];
		if ($categoryIds !== []) {
			$categoryFilters[] = $qb->expr()->in('category_id', $qb->createNamedParameter($categoryIds, IQueryBuilder::PARAM_INT_ARRAY));
		}
		if ($includeUncategorized) {
			$categoryFilters[] = $qb->expr()->isNull('category_id');
		}
		if ($categoryFilters !== []) {
			$qb->andWhere($qb->expr()->orX(...$categoryFilters));
		}

		$qb->orderBy('start_datetime', 'ASC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		return $this->findEntities($qb);
	}
}
