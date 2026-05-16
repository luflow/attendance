<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCA\Attendance\Audit\Verb;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the att_audit_event table for append-only audit events and backfills
 * legacy response/check-in rows so existing data shows up in the new timeline.
 */
class Version000013Date20260515120000 extends SimpleMigrationStep {
	private IDBConnection $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('att_audit_event')) {
			$table = $schema->createTable('att_audit_event');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('appointment_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('verb', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('actor_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('subject_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('meta', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('source', Types::STRING, [
				'notnull' => false,
				'length' => 32,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['appointment_id', 'created_at'], 'att_audit_apt_time');
			$table->addIndex(['actor_id', 'created_at'], 'att_audit_actor');
			$table->addIndex(['appointment_id', 'subject_id', 'verb'], 'att_audit_apt_subj_verb');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from('att_audit_event');
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		// Skip when the table already has rows — re-running the backfill would
		// duplicate every legacy entry.
		if ((int)($row['cnt'] ?? 0) > 0) {
			return;
		}

		$this->connection->beginTransaction();
		try {
			$this->backfillResponses($output);
			$this->backfillCheckins($output);
			$this->connection->commit();
		} catch (\Throwable $e) {
			$this->connection->rollBack();
			throw $e;
		}
	}

	private function backfillResponses(IOutput $output): void {
		$select = $this->connection->getQueryBuilder();
		$select->select('appointment_id', 'user_id', 'response', 'comment', 'responded_at')
			->from('att_responses')
			->where($select->expr()->isNotNull('response'))
			->andWhere($select->expr()->neq('response', $select->createNamedParameter('')));

		$cursor = $select->executeQuery();
		$count = 0;
		while ($row = $cursor->fetch()) {
			$this->insertEvent(
				Verb::RESPONSE_SUBMITTED,
				(int)$row['appointment_id'],
				(string)$row['user_id'],
				(string)$row['user_id'],
				[
					'response' => (string)$row['response'],
					'comment' => (string)($row['comment'] ?? ''),
				],
				Verb::SOURCE_LEGACY_BACKFILL,
				(string)($row['responded_at'] ?? gmdate('Y-m-d H:i:s')),
			);
			$count++;
		}
		$cursor->closeCursor();
		$output->info(sprintf('Backfilled %d response events.', $count));
	}

	private function backfillCheckins(IOutput $output): void {
		$select = $this->connection->getQueryBuilder();
		$select->select('appointment_id', 'user_id', 'checkin_state', 'checkin_comment', 'checkin_by', 'checkin_at')
			->from('att_responses')
			->where($select->expr()->isNotNull('checkin_state'))
			->andWhere($select->expr()->neq('checkin_state', $select->createNamedParameter('')));

		$cursor = $select->executeQuery();
		$count = 0;
		while ($row = $cursor->fetch()) {
			$this->insertEvent(
				Verb::CHECKIN_RECORDED,
				(int)$row['appointment_id'],
				(string)($row['checkin_by'] ?? '') ?: null,
				(string)$row['user_id'],
				[
					'checkinState' => (string)$row['checkin_state'],
					'checkinComment' => (string)($row['checkin_comment'] ?? ''),
				],
				Verb::SOURCE_LEGACY_BACKFILL,
				(string)($row['checkin_at'] ?? gmdate('Y-m-d H:i:s')),
			);
			$count++;
		}
		$cursor->closeCursor();
		$output->info(sprintf('Backfilled %d check-in events.', $count));
	}

	private function insertEvent(
		string $verb,
		int $appointmentId,
		?string $actorId,
		?string $subjectId,
		array $meta,
		string $source,
		string $createdAt,
	): void {
		$insert = $this->connection->getQueryBuilder();
		$insert->insert('att_audit_event')
			->values([
				'appointment_id' => $insert->createNamedParameter($appointmentId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				'verb' => $insert->createNamedParameter($verb),
				'actor_id' => $insert->createNamedParameter($actorId),
				'subject_id' => $insert->createNamedParameter($subjectId),
				'meta' => $insert->createNamedParameter(json_encode($meta, JSON_UNESCAPED_UNICODE)),
				'source' => $insert->createNamedParameter($source),
				'created_at' => $insert->createNamedParameter($createdAt),
			]);
		$insert->executeStatement();
	}
}
