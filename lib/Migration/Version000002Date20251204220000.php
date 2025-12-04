<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000002Date20251204220000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Create reminder log table to track when users were reminded
		if (!$schema->hasTable('att_reminder_log')) {
			$table = $schema->createTable('att_reminder_log');
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('appointment_id', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('reminded_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['appointment_id'], 'att_reminder_appt');
			$table->addIndex(['user_id'], 'att_reminder_user');
			$table->addIndex(['reminded_at'], 'att_reminder_date');
			// Composite index for efficient lookups
			$table->addIndex(['appointment_id', 'user_id'], 'att_reminder_appt_user');
		}

		return $schema;
	}
}
