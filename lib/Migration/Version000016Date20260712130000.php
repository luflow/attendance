<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add booking columns to att_responses.
 *
 * booking_status: per-response planning state — null (open / undecided),
 * 'booked' (planned in) or 'declined'. Only meaningful for yes-responders.
 * booking_notified_status / booking_notified_at: the last booking state that
 * was communicated to the attendee, so re-closing an appointment doesn't send
 * duplicate notifications (used by the close-time notification wave).
 *
 * All nullable and purely additive so older mobile clients keep working.
 */
class Version000016Date20260712130000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('att_responses')) {
			$table = $schema->getTable('att_responses');

			if (!$table->hasColumn('booking_status')) {
				$table->addColumn('booking_status', 'string', [
					'notnull' => false,
					'length' => 16,
				]);
			}

			if (!$table->hasColumn('booking_notified_status')) {
				$table->addColumn('booking_notified_status', 'string', [
					'notnull' => false,
					'length' => 16,
				]);
			}

			if (!$table->hasColumn('booking_notified_at')) {
				$table->addColumn('booking_notified_at', 'datetime', [
					'notnull' => false,
				]);
			}
		}

		return $schema;
	}
}
