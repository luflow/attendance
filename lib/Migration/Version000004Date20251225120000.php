<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000004Date20251225120000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Create att_ical_tokens table for storing user iCal feed tokens
		if (!$schema->hasTable('att_ical_tokens')) {
			$table = $schema->createTable('att_ical_tokens');

			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
			]);

			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);

			$table->addColumn('token', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);

			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->addColumn('last_used_at', Types::DATETIME, [
				'notnull' => false,
				'default' => null,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id'], 'att_ical_tokens_user_idx');
			$table->addUniqueIndex(['token'], 'att_ical_tokens_token_idx');
		}

		return $schema;
	}
}
