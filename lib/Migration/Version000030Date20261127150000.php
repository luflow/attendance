<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The reason an organizer gave when calling an appointment off.
 */
final class Version000030Date20261127150000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('att_appointments')) {
			return null;
		}

		$table = $schema->getTable('att_appointments');
		if ($table->hasColumn('cancel_reason')) {
			return null;
		}

		$table->addColumn('cancel_reason', Types::STRING, [
			'notnull' => false,
			'length' => 500,
		]);

		return $schema;
	}
}
