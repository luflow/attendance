<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Talk conversation for the people scheduled into an appointment.
 *
 * `create_talk_room` is the per-appointment opt-in, mirroring send_notification;
 * `talk_room_token` holds the conversation once it exists. Both nullable and
 * purely additive, so older mobile clients keep working.
 */
final class Version000022Date20260816120000 extends SimpleMigrationStep {
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

		if (!$table->hasColumn('create_talk_room')) {
			$table->addColumn('create_talk_room', 'boolean', [
				'notnull' => false,
				'default' => false,
			]);
		}

		// Talk tokens are short, but the column is sized for room to grow.
		if (!$table->hasColumn('talk_room_token')) {
			$table->addColumn('talk_room_token', 'string', [
				'notnull' => false,
				'length' => 64,
			]);
		}

		return $schema;
	}
}
