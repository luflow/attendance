<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000006Date20251229150000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Create att_attachments table
		if (!$schema->hasTable('att_attachments')) {
			$table = $schema->createTable('att_attachments');

			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
			]);

			$table->addColumn('appointment_id', Types::INTEGER, [
				'notnull' => true,
			]);

			$table->addColumn('file_id', Types::INTEGER, [
				'notnull' => true,
			]);

			$table->addColumn('file_name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);

			$table->addColumn('file_path', Types::STRING, [
				'notnull' => true,
				'length' => 4000,
			]);

			$table->addColumn('added_by', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);

			$table->addColumn('added_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['appointment_id'], 'att_attach_appt_idx');
		}

		return $schema;
	}
}
