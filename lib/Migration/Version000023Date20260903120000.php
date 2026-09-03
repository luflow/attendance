<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Per-appointment override for the "Maybe" answer.
 *
 * Tri-state on purpose: NULL follows the instance-wide default, so flipping
 * that setting reaches every appointment nobody has decided about. Nullable
 * and additive, so older mobile clients keep working.
 */
final class Version000023Date20260903120000 extends SimpleMigrationStep {
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

		if (!$table->hasColumn('allow_maybe')) {
			$table->addColumn('allow_maybe', 'boolean', [
				'notnull' => false,
				'default' => null,
			]);
		}

		return $schema;
	}
}
