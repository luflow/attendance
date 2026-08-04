<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add a nullable location column to att_appointments.
 * Free-text location, synced with calendar integrations (ICS LOCATION).
 * Nullable and purely additive so older mobile clients keep working.
 */
class Version000019Date20260804120000 extends SimpleMigrationStep {
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

			if (!$table->hasColumn('location')) {
				$table->addColumn('location', 'string', [
					'notnull' => false,
					'length' => 255,
				]);
			}
		}

		return $schema;
	}
}
