<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000001Date20250829212000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Add response checkin columns to att_responses table
		if ($schema->hasTable('att_responses')) {
			$table = $schema->getTable('att_responses');
			
			if (!$table->hasColumn('checkin_state')) {
				$table->addColumn('checkin_state', Types::STRING, [
					'notnull' => false,
					'length' => 10,
					'default' => '',
				]);
			}

			if (!$table->hasColumn('checkin_comment')) {
				$table->addColumn('checkin_comment', Types::TEXT, [
					'notnull' => false,
					'default' => '',
				]);
			}

			if (!$table->hasColumn('checkin_by')) {
				$table->addColumn('checkin_by', Types::STRING, [
					'notnull' => false,
					'length' => 64,
					'default' => '',
				]);
			}

			if (!$table->hasColumn('checkin_at')) {
				$table->addColumn('checkin_at', Types::DATETIME, [
					'notnull' => false,
				]);
			}

			// Make response and responded_at fields nullable to allow overrides for users without responses
			if ($table->hasColumn('response')) {
				$responseColumn = $table->getColumn('response');
				$responseColumn->setNotnull(false);
			}
			
			if ($table->hasColumn('responded_at')) {
				$respondedAtColumn = $table->getColumn('responded_at');
				$respondedAtColumn->setNotnull(false);
			}
		}

		return $schema;
	}
}
