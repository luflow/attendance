<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000005Date20251227120000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Add response_source column to att_responses table
		if ($schema->hasTable('att_responses')) {
			$table = $schema->getTable('att_responses');

			if (!$table->hasColumn('response_source')) {
				$table->addColumn('response_source', Types::STRING, [
					'notnull' => false,
					'length' => 20,
					'default' => null,
				]);
			}
		}

		return $schema;
	}
}
