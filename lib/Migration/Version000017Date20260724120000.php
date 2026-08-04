<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Server;

/**
 * Migration for the organizer feature (issue #73).
 *
 * organizers: JSON list of user IDs who co-manage this appointment (same
 * storage pattern as visible_users). Backfilled with [created_by] so the
 * pre-existing "creator may manage their appointment" special cases keep
 * working through the generalized organizer checks.
 *
 * No constructor DI: MigrationService falls back to `new $class()` without
 * arguments when service resolution fails during an upgrade.
 */
class Version000017Date20260724120000 extends SimpleMigrationStep {
	private ?IDBConnection $db = null;

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('att_appointments')) {
			$table = $schema->getTable('att_appointments');

			if (!$table->hasColumn('organizers')) {
				$table->addColumn('organizers', 'text', [
					'notnull' => false,
				]);
			}
		}

		return $schema;
	}

	/**
	 * Backfill organizers with the creator for all existing appointments.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->db()->getQueryBuilder();
		$select->select('id', 'created_by')
			->from('att_appointments')
			->where($select->expr()->isNull('organizers'));
		$result = $select->executeQuery();

		$update = $this->db()->getQueryBuilder();
		$update->update('att_appointments')
			->set('organizers', $update->createParameter('organizers'))
			->where($update->expr()->eq('id', $update->createParameter('id')));

		while ($row = $result->fetch()) {
			$createdBy = (string)($row['created_by'] ?? '');
			if ($createdBy === '') {
				continue;
			}
			$update->setParameter('organizers', json_encode([$createdBy]));
			$update->setParameter('id', (int)$row['id']);
			$update->executeStatement();
		}
		$result->closeCursor();
	}

	private function db(): IDBConnection {
		return $this->db ??= Server::get(IDBConnection::class);
	}
}
