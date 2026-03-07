<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add checkin_source column to att_responses.
 * Tracks how a check-in happened: 'manual' (admin) or 'nfc' (self-check-in).
 * Backfills existing check-ins as 'manual' since that was the only option before.
 */
class Version000009Date20260307120000 extends SimpleMigrationStep {
	private IDBConnection $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('att_responses')) {
			$table = $schema->getTable('att_responses');

			// Source of the check-in: 'manual' (admin), 'nfc' (self-check-in via NFC/deep link)
			if (!$table->hasColumn('checkin_source')) {
				$table->addColumn('checkin_source', Types::STRING, [
					'notnull' => false,
					'default' => null,
					'length' => 16,
				]);
			}
		}

		return $schema;
	}

	/**
	 * Backfill existing check-ins as 'manual' since that was the only method before this migration.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->db->getQueryBuilder();

		$qb->update('att_responses')
			->set('checkin_source', $qb->createNamedParameter('manual'))
			->where($qb->expr()->isNotNull('checkin_state'))
			->andWhere($qb->expr()->neq('checkin_state', $qb->createNamedParameter('')));

		$updated = $qb->executeStatement();
		$output->info("Backfilled {$updated} existing check-ins with source 'manual'.");
	}
}
