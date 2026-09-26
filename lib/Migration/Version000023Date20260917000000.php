<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Per-user vacation/absence periods: date-only ranges with an optional
 * free-text reason. Backs the personal vacation settings, the "who can't
 * attend" hint and auto-"no" response on appointment creation, and the
 * team-wide vacation calendar overview.
 */
final class Version000023Date20260917000000 extends SimpleMigrationStep {
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

		if ($schema->hasTable('att_vacations')) {
			return null;
		}

		$table = $schema->createTable('att_vacations');
		$table->addColumn('id', 'integer', [
			'autoincrement' => true,
			'notnull' => true,
		]);
		$table->addColumn('user_id', 'string', [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('start_date', 'string', [
			'notnull' => true,
			'length' => 10,
		]);
		$table->addColumn('end_date', 'string', [
			'notnull' => true,
			'length' => 10,
		]);
		$table->addColumn('note', 'string', [
			'notnull' => false,
			'length' => 500,
		]);
		$table->addColumn('created_at', 'string', [
			'notnull' => true,
			'length' => 19,
		]);
		$table->addColumn('updated_at', 'string', [
			'notnull' => true,
			'length' => 19,
		]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['user_id'], 'att_vacations_user_idx');
		// The overlap check filters on both bounds together, for every
		// affected user, on every appointment create — worth its own index
		// rather than leaning on the user_id one alone.
		$table->addIndex(['start_date', 'end_date'], 'att_vacations_range_idx');

		return $schema;
	}
}
