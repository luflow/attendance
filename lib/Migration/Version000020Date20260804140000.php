<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the att_categories table (admin-managed, name + a fixed-set icon key —
 * no color, badges use the Nextcloud/Flutter theme accent) and a nullable
 * category_id column on att_appointments. Both additive, so older mobile
 * clients keep working.
 */
class Version000020Date20260804140000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('att_categories')) {
			$table = $schema->createTable('att_categories');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('icon', Types::STRING, [
				'notnull' => true,
				'length' => 32,
				'default' => 'tag',
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['name'], 'att_categories_name');
		}

		if ($schema->hasTable('att_appointments')) {
			$table = $schema->getTable('att_appointments');

			if (!$table->hasColumn('category_id')) {
				$table->addColumn('category_id', Types::BIGINT, [
					'notnull' => false,
				]);
				$table->addIndex(['category_id'], 'att_apt_category');
			}
		}

		return $schema;
	}
}
