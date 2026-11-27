<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\Attendance\Migration\Version000028Date20261127120000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version000028Date20261127120000Test extends TestCase {
	/** The table as Version000004 created it. */
	private function tokensTable(): Table {
		$table = new Table('oc_att_ical_tokens');
		$table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
		$table->addColumn('token', 'string', ['notnull' => true, 'length' => 64]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['user_id'], 'att_ical_tokens_user_idx');
		$table->addUniqueIndex(['token'], 'att_ical_tokens_token_idx');
		return $table;
	}

	private function migrate(Table $table): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('att_ical_tokens')->willReturn(true);
		$schema->method('getTable')->with('att_ical_tokens')->willReturn($table);

		(new Version000028Date20261127120000())->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);
	}

	public function testAUserMayHoldOneTokenPerFeed(): void {
		$table = $this->tokensTable();

		$this->migrate($table);

		$kind = $table->getColumn('kind');
		$this->assertTrue($kind->getNotnull());
		$this->assertSame('personal', $kind->getDefault());
		$this->assertFalse($table->hasIndex('att_ical_tokens_user_idx'));
		$index = $table->getIndex('att_ical_tokens_user_kind');
		$this->assertTrue($index->isUnique());
		$this->assertSame(['user_id', 'kind'], $index->getColumns());
		$this->assertTrue($table->hasIndex('att_ical_tokens_token_idx'));
	}

	/** After a failed update, app:enable runs pending steps again. */
	public function testASecondRunChangesNothing(): void {
		$table = $this->tokensTable();

		$this->migrate($table);
		$this->migrate($table);

		$this->assertCount(4, $table->getColumns());
		$this->assertCount(3, $table->getIndexes());
	}

	public function testAMissingTableIsLeftToTheStepThatCreatesIt(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$result = (new Version000028Date20261127120000())->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);

		$this->assertNull($result);
	}
}
