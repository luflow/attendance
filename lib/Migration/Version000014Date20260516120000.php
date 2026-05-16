<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCA\Attendance\Audit\Verb;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Backfills appointment.created and appointment.closed audit events for
 * appointments that already exist when the timeline-lifecycle feature ships.
 * Idempotent: only inserts rows that are not already present (matched by
 * appointment_id + verb).
 */
class Version000014Date20260516120000 extends SimpleMigrationStep {
	private IDBConnection $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->connection->beginTransaction();
		try {
			$this->backfillCreated($output);
			$this->backfillClosed($output);
			$this->connection->commit();
		} catch (\Throwable $e) {
			$this->connection->rollBack();
			throw $e;
		}
	}

	private function backfillCreated(IOutput $output): void {
		$existing = $this->existingAppointmentIds(Verb::APPOINTMENT_CREATED);

		$select = $this->connection->getQueryBuilder();
		$select->select('id', 'created_by', 'created_at')
			->from('att_appointments');
		$cursor = $select->executeQuery();

		$count = 0;
		while ($row = $cursor->fetch()) {
			$id = (int)$row['id'];
			if (isset($existing[$id])) {
				continue;
			}
			$this->insertEvent(
				Verb::APPOINTMENT_CREATED,
				$id,
				(string)($row['created_by'] ?? '') ?: null,
				Verb::SOURCE_LEGACY_BACKFILL,
				(string)($row['created_at'] ?? gmdate('Y-m-d H:i:s')),
			);
			$count++;
		}
		$cursor->closeCursor();
		$output->info(sprintf('Backfilled %d appointment.created events.', $count));
	}

	private function backfillClosed(IOutput $output): void {
		$existing = $this->existingAppointmentIds(Verb::APPOINTMENT_CLOSED);

		$select = $this->connection->getQueryBuilder();
		$select->select('id', 'closed_at')
			->from('att_appointments')
			->where($select->expr()->isNotNull('closed_at'));
		$cursor = $select->executeQuery();

		$count = 0;
		while ($row = $cursor->fetch()) {
			$id = (int)$row['id'];
			if (isset($existing[$id])) {
				continue;
			}
			// Historic close events: no actor recorded — we don't know whether a
			// manager closed it manually or the auto-close job fired.
			$this->insertEvent(
				Verb::APPOINTMENT_CLOSED,
				$id,
				null,
				Verb::SOURCE_LEGACY_BACKFILL,
				(string)$row['closed_at'],
			);
			$count++;
		}
		$cursor->closeCursor();
		$output->info(sprintf('Backfilled %d appointment.closed events.', $count));
	}

	/**
	 * @return array<int, true>
	 */
	private function existingAppointmentIds(string $verb): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('appointment_id')
			->from('att_audit_event')
			->where($qb->expr()->eq('verb', $qb->createNamedParameter($verb)));
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[(int)$row['appointment_id']] = true;
		}
		$result->closeCursor();
		return $ids;
	}

	private function insertEvent(
		string $verb,
		int $appointmentId,
		?string $actorId,
		string $source,
		string $createdAt,
	): void {
		$insert = $this->connection->getQueryBuilder();
		$insert->insert('att_audit_event')
			->values([
				'appointment_id' => $insert->createNamedParameter($appointmentId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				'verb' => $insert->createNamedParameter($verb),
				'actor_id' => $insert->createNamedParameter($actorId),
				'subject_id' => $insert->createNamedParameter(null),
				'meta' => $insert->createNamedParameter(null),
				'source' => $insert->createNamedParameter($source),
				'created_at' => $insert->createNamedParameter($createdAt),
			]);
		$insert->executeStatement();
	}
}
