<?php

declare(strict_types=1);

namespace OCA\Attendance\Repair;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Defensive repair step that re-applies the additive schema changes from
 * Version000012Date20260425120000 (closed_at, response_deadline + indexes)
 * if they are missing. Works around the Nextcloud bug reported in
 * https://github.com/luflow/attendance/issues/68 where the migration is
 * recorded in oc_migrations without its DDL actually being executed.
 *
 * Idempotent: runs on every post-migration cycle and only touches the
 * database when columns or indexes are missing.
 */
class EnsureAppointmentSchema implements IRepairStep {
	public function __construct(
		private IDBConnection $connection,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	public function getName(): string {
		return 'Ensure Attendance appointment schema integrity';
	}

	public function run(IOutput $output): void {
		$prefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');
		$tableName = $prefix . 'att_appointments';

		$schema = $this->connection->createSchema();
		if (!$schema->hasTable($tableName)) {
			return;
		}

		$table = $schema->getTable($tableName);
		$applied = [];

		if (!$table->hasColumn('closed_at')) {
			$table->addColumn('closed_at', 'datetime', ['notnull' => false]);
			$applied[] = 'column closed_at';
		}

		if (!$table->hasColumn('response_deadline')) {
			$table->addColumn('response_deadline', 'datetime', ['notnull' => false]);
			$applied[] = 'column response_deadline';
		}

		if ($table->hasColumn('closed_at') && !$table->hasIndex('att_appt_closed')) {
			$table->addIndex(['closed_at'], 'att_appt_closed');
			$applied[] = 'index att_appt_closed';
		}

		if ($table->hasColumn('response_deadline') && !$table->hasIndex('att_appt_deadline')) {
			$table->addIndex(['response_deadline'], 'att_appt_deadline');
			$applied[] = 'index att_appt_deadline';
		}

		if ($applied === []) {
			return;
		}

		$this->connection->migrateToSchema($schema);

		$message = sprintf(
			'Repaired Attendance schema on %s: applied missing %s.',
			$tableName,
			implode(', ', $applied),
		);
		$output->info($message);
		$this->logger->warning($message, [
			'app' => 'attendance',
			'issue' => 'https://github.com/luflow/attendance/issues/68',
		]);
	}
}
