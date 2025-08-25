<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000000Date20250824002300 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Create appointments table
		if (!$schema->hasTable('att_appointments')) {
			$table = $schema->createTable('att_appointments');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('name', 'string', [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('description', 'text', [
				'notnull' => false,
			]);
			$table->addColumn('start_datetime', 'datetime', [
				'notnull' => true,
			]);
			$table->addColumn('end_datetime', 'datetime', [
				'notnull' => true,
			]);
			$table->addColumn('created_by', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('created_at', 'datetime', [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', 'datetime', [
				'notnull' => true,
			]);
			$table->addColumn('is_active', 'smallint', [
				'notnull' => true,
				'default' => 1,
				'length' => 1,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['created_by'], 'att_appt_created');
			$table->addIndex(['start_datetime'], 'att_appt_start');
			$table->addIndex(['is_active'], 'att_appt_active');
		}

		// Create attendance responses table
		if (!$schema->hasTable('att_responses')) {
			$table = $schema->createTable('att_responses');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('appointment_id', 'integer', [
				'notnull' => true,
			]);
			$table->addColumn('user_id', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('response', 'string', [
				'notnull' => true,
				'length' => 20,
			]); // 'yes', 'no', 'maybe'
			$table->addColumn('comment', 'text', [
				'notnull' => false,
			]);
			$table->addColumn('responded_at', 'datetime', [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['appointment_id'], 'att_resp_appt');
			$table->addIndex(['user_id'], 'att_resp_user');
			$table->addUniqueIndex(['appointment_id', 'user_id'], 'att_resp_unique');
		}

		return $schema;
	}
}
