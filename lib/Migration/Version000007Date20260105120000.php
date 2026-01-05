<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000007Date20260105120000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Add visible_teams column to att_appointments table
		if ($schema->hasTable('att_appointments')) {
			$table = $schema->getTable('att_appointments');

			// JSON array of team/circle IDs whose members can see this appointment
			// Empty/null means no team restriction (existing visibility rules apply)
			if (!$table->hasColumn('visible_teams')) {
				$table->addColumn('visible_teams', Types::TEXT, [
					'notnull' => false,
					'default' => null,
				]);
			}
		}

		return $schema;
	}
}
