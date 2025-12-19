<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000003Date20251218210000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Add visibility settings to att_appointments table
		if ($schema->hasTable('att_appointments')) {
			$table = $schema->getTable('att_appointments');
			
			// JSON array of user IDs who can see this appointment
			// Empty/null means visible to all users
			if (!$table->hasColumn('visible_users')) {
				$table->addColumn('visible_users', Types::TEXT, [
					'notnull' => false,
					'default' => null,
				]);
			}

			// JSON array of group IDs whose members can see this appointment
			// Empty/null means visible to all users
			if (!$table->hasColumn('visible_groups')) {
				$table->addColumn('visible_groups', Types::TEXT, [
					'notnull' => false,
					'default' => null,
				]);
			}
		}

		return $schema;
	}
}
