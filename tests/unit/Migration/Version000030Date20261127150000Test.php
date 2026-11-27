<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\Attendance\Migration\Version000030Date20261127150000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version000030Date20261127150000Test extends TestCase {
	private function appointmentsTable(): Table {
		$table = new Table('oc_att_appointments');
		$table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('cancelled_at', 'datetime', ['notnull' => false]);
		$table->setPrimaryKey(['id']);
		return $table;
	}

	private function migrate(Table $table): ?ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('att_appointments')->willReturn(true);
		$schema->method('getTable')->with('att_appointments')->willReturn($table);

		return (new Version000030Date20261127150000())->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);
	}

	public function testAddsANullableReasonColumn(): void {
		$table = $this->appointmentsTable();

		$this->assertNotNull($this->migrate($table));

		$column = $table->getColumn('cancel_reason');
		$this->assertFalse($column->getNotnull());
		$this->assertNull($column->getDefault());
	}

	/** After a failed update, app:enable runs pending steps again. */
	public function testASecondRunChangesNothing(): void {
		$table = $this->appointmentsTable();

		$this->migrate($table);

		$this->assertNull($this->migrate($table));
		$this->assertCount(3, $table->getColumns());
	}

	public function testAMissingTableIsLeftToTheStepThatCreatesIt(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$result = (new Version000030Date20261127150000())->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);

		$this->assertNull($result);
	}
}
