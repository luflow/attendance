<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add closed_at and response_deadline columns to att_appointments.
 * Supports closing an appointment inquiry (no further responses, no reminders)
 * and setting an optional deadline that auto-closes the inquiry via cron.
 */
class Version000012Date20260425120000 extends SimpleMigrationStep {
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

			if (!$table->hasColumn('closed_at')) {
				$table->addColumn('closed_at', 'datetime', [
					'notnull' => false,
				]);
			}

			if (!$table->hasColumn('response_deadline')) {
				$table->addColumn('response_deadline', 'datetime', [
					'notnull' => false,
				]);
			}

			if (!$table->hasIndex('att_appt_closed')) {
				$table->addIndex(['closed_at'], 'att_appt_closed');
			}
			if (!$table->hasIndex('att_appt_deadline')) {
				$table->addIndex(['response_deadline'], 'att_appt_deadline');
			}
		}

		return $schema;
	}
}
