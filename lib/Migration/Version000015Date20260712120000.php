<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add a nullable cancelled_at column to att_appointments.
 * Marks an appointment as cancelled (the event does NOT take place), a separate
 * state next to closed_at (the event happens, no more responses needed).
 * Nullable and purely additive so older mobile clients keep working.
 */
class Version000015Date20260712120000 extends SimpleMigrationStep {
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

			if (!$table->hasColumn('cancelled_at')) {
				$table->addColumn('cancelled_at', 'datetime', [
					'notnull' => false,
				]);
			}

			if (!$table->hasIndex('att_appt_cancelled')) {
				$table->addIndex(['cancelled_at'], 'att_appt_cancelled');
			}
		}

		return $schema;
	}
}
