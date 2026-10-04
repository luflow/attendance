<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * All-day appointments (issue #269): `all_day` marks an appointment whose
 * start and end are whole days rather than times. Nullable and purely
 * additive, so older mobile clients keep working.
 */
final class Version000026Date20261004140000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('att_appointments')) {
			return null;
		}

		$table = $schema->getTable('att_appointments');
		if ($table->hasColumn('all_day')) {
			return null;
		}

		$table->addColumn('all_day', 'boolean', [
			'notnull' => false,
			'default' => false,
		]);

		return $schema;
	}
}
