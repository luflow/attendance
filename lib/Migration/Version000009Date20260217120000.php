<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to create the att_streaks table for attendance streak tracking.
 */
class Version000009Date20260217120000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('att_streaks')) {
			$table = $schema->createTable('att_streaks');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('current_streak', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('longest_streak', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('streak_start_date', Types::DATETIME, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addColumn('longest_streak_date', Types::DATETIME, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addColumn('last_calculated_at', Types::DATETIME, [
				'notnull' => false,
				'default' => null,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id'], 'att_streaks_user_id_idx');
		}

		return $schema;
	}
}
