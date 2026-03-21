<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add series_id and series_position columns to att_appointments.
 * These columns group recurring appointments into a series for bulk edit/delete.
 */
class Version000010Date20260321120000 extends SimpleMigrationStep {
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

			if (!$table->hasColumn('series_id')) {
				$table->addColumn('series_id', Types::STRING, [
					'notnull' => false,
					'default' => null,
					'length' => 36,
				]);
			}

			if (!$table->hasColumn('series_position')) {
				$table->addColumn('series_position', Types::INTEGER, [
					'notnull' => false,
					'default' => null,
				]);
			}

			if (!$table->hasIndex('att_appt_series_idx')) {
				$table->addIndex(['series_id'], 'att_appt_series_idx');
			}
		}

		return $schema;
	}
}
