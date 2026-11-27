<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A user can hold a second feed token, for the organizer calendar. `personal`
 * is a snapshot of IcalToken::KIND_PERSONAL: every existing row is one.
 */
final class Version000028Date20261127120000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('att_ical_tokens')) {
			return null;
		}

		$table = $schema->getTable('att_ical_tokens');

		if (!$table->hasColumn('kind')) {
			$table->addColumn('kind', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'personal',
			]);
		}

		// One token per user becomes one per user and feed.
		if ($table->hasIndex('att_ical_tokens_user_idx')) {
			$table->dropIndex('att_ical_tokens_user_idx');
		}
		if (!$table->hasIndex('att_ical_tokens_user_kind')) {
			$table->addUniqueIndex(['user_id', 'kind'], 'att_ical_tokens_user_kind');
		}

		return $schema;
	}
}
