<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds a nullable template column to att_categories: the description and
 * access restriction new appointments of that category start with, as JSON.
 * Additive, so older mobile clients keep working.
 */
final class Version000025Date20261004120000 extends SimpleMigrationStep {
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

		if ($schema->hasTable('att_categories')) {
			$table = $schema->getTable('att_categories');

			if (!$table->hasColumn('template')) {
				$table->addColumn('template', Types::TEXT, [
					'notnull' => false,
				]);
			}
		}

		return $schema;
	}
}
