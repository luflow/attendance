<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add calendar reference fields for calendar event import feature.
 */
class Version000008Date20260106140000 extends SimpleMigrationStep {

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

			// URI of the calendar from which this appointment was imported
			// Example: "personal", "work"
			if (!$table->hasColumn('calendar_uri')) {
				$table->addColumn('calendar_uri', Types::STRING, [
					'notnull' => false,
					'default' => null,
					'length' => 255,
				]);
			}

			// UID of the source calendar event (from iCal UID field)
			// Used for traceability and potential future linking
			if (!$table->hasColumn('calendar_event_uid')) {
				$table->addColumn('calendar_event_uid', Types::STRING, [
					'notnull' => false,
					'default' => null,
					'length' => 255,
				]);
			}
		}

		return $schema;
	}
}
