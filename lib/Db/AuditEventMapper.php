<?php

declare(strict_types=1);

namespace OCA\Attendance\Db;

use OCA\Attendance\Audit\Verb;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<AuditEvent>
 */
class AuditEventMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'att_audit_event', AuditEvent::class);
	}

	/**
	 * @return list<AuditEvent>
	 */
	public function findByAppointment(
		int $appointmentId,
		?string $verbPrefix = null,
		?string $subjectId = null,
		int $limit = 100,
		int $offset = 0,
	): array {
		$qb = $this->buildAppointmentQuery($appointmentId, $verbPrefix, $subjectId);
		$qb->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		return array_values($this->findEntities($qb));
	}

	public function countByAppointment(
		int $appointmentId,
		?string $verbPrefix = null,
		?string $subjectId = null,
	): int {
		$qb = $this->buildAppointmentQuery($appointmentId, $verbPrefix, $subjectId, true);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int)($row['cnt'] ?? 0);
	}

	private function buildAppointmentQuery(
		int $appointmentId,
		?string $verbPrefix,
		?string $subjectId,
		bool $countOnly = false,
	): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		if ($countOnly) {
			$qb->select($qb->func()->count('*', 'cnt'));
		} else {
			$qb->select('*');
		}
		$qb->from($this->getTableName())
			->where($qb->expr()->eq('appointment_id', $qb->createNamedParameter($appointmentId, IQueryBuilder::PARAM_INT)));

		if ($verbPrefix !== null && $verbPrefix !== '') {
			if (str_ends_with($verbPrefix, '.*')) {
				$prefix = substr($verbPrefix, 0, -1);
				$qb->andWhere($qb->expr()->like('verb', $qb->createNamedParameter($prefix . '%')));
			} else {
				$qb->andWhere($qb->expr()->eq('verb', $qb->createNamedParameter($verbPrefix)));
			}
		}

		if ($subjectId !== null && $subjectId !== '') {
			$qb->andWhere($qb->expr()->eq('subject_id', $qb->createNamedParameter($subjectId)));
		}

		return $qb;
	}

	/**
	 * Anonymise events that reference a deleted user, both in actor / subject
	 * columns and inside the meta JSON. We rewrite in-place so the audit chain
	 * stays intact but no longer carries the user's identifier or comments.
	 */
	public function anonymiseUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'actor_id', 'subject_id', 'meta')
			->from($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->eq('actor_id', $qb->createNamedParameter($userId)),
				$qb->expr()->eq('subject_id', $qb->createNamedParameter($userId)),
			));
		$result = $qb->executeQuery();
		// Buffer the rows before issuing UPDATEs — some DB drivers refuse
		// statements while a SELECT cursor is still open on the same connection.
		$rows = $result->fetchAll();
		$result->closeCursor();

		foreach ($rows as $row) {
			$meta = json_decode((string)($row['meta'] ?? ''), true);
			$cleanedMeta = $this->stripPiiFromMeta(is_array($meta) ? $meta : []);
			$encodedMeta = $cleanedMeta === [] ? null : json_encode($cleanedMeta, JSON_UNESCAPED_UNICODE);

			$update = $this->db->getQueryBuilder();
			$update->update($this->getTableName())
				->set('actor_id', $update->createNamedParameter(
					$row['actor_id'] === $userId ? Verb::ANONYMISED_USER : $row['actor_id'],
				))
				->set('subject_id', $update->createNamedParameter(
					$row['subject_id'] === $userId ? Verb::ANONYMISED_USER : $row['subject_id'],
				))
				->set('meta', $update->createNamedParameter($encodedMeta))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
			$update->executeStatement();
		}
		return count($rows);
	}

	private function stripPiiFromMeta(array $meta): array {
		foreach (['comment', 'comment_from', 'comment_to', 'checkinComment'] as $key) {
			if (array_key_exists($key, $meta)) {
				unset($meta[$key]);
			}
		}
		return $meta;
	}
}
