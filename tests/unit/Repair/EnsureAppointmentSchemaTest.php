<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Repair;

use Doctrine\DBAL\Schema\Schema;
use OCA\Attendance\Repair\EnsureAppointmentSchema;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EnsureAppointmentSchemaTest extends TestCase {
	private IDBConnection|MockObject $connection;
	private IConfig|MockObject $config;
	private LoggerInterface|MockObject $logger;
	private IOutput|MockObject $output;
	private EnsureAppointmentSchema $repair;

	protected function setUp(): void {
		$this->connection = $this->createMock(IDBConnection::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);

		$this->config->method('getSystemValueString')
			->with('dbtableprefix', 'oc_')
			->willReturn('oc_');

		$this->repair = new EnsureAppointmentSchema(
			$this->connection,
			$this->config,
			$this->logger,
		);
	}

	private function makeSchemaWithAppointments(bool $closedAt, bool $deadline, bool $closedIdx, bool $deadlineIdx): Schema {
		$schema = new Schema();
		$table = $schema->createTable('oc_att_appointments');
		$table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
		$table->setPrimaryKey(['id']);
		if ($closedAt) {
			$table->addColumn('closed_at', 'datetime', ['notnull' => false]);
		}
		if ($deadline) {
			$table->addColumn('response_deadline', 'datetime', ['notnull' => false]);
		}
		if ($closedIdx && $closedAt) {
			$table->addIndex(['closed_at'], 'att_appt_closed');
		}
		if ($deadlineIdx && $deadline) {
			$table->addIndex(['response_deadline'], 'att_appt_deadline');
		}
		return $schema;
	}

	public function testNoOpWhenTableMissing(): void {
		$this->connection->method('createSchema')->willReturn(new Schema());
		$this->connection->expects($this->never())->method('migrateToSchema');
		$this->logger->expects($this->never())->method('warning');

		$this->repair->run($this->output);
	}

	public function testNoOpWhenSchemaAlreadyComplete(): void {
		$schema = $this->makeSchemaWithAppointments(true, true, true, true);
		$this->connection->method('createSchema')->willReturn($schema);
		$this->connection->expects($this->never())->method('migrateToSchema');
		$this->logger->expects($this->never())->method('warning');

		$this->repair->run($this->output);
	}

	public function testAddsMissingColumnsAndIndexes(): void {
		$schema = $this->makeSchemaWithAppointments(false, false, false, false);
		$this->connection->method('createSchema')->willReturn($schema);

		$this->connection->expects($this->once())
			->method('migrateToSchema')
			->with($this->callback(function (Schema $applied): bool {
				$table = $applied->getTable('oc_att_appointments');
				return $table->hasColumn('closed_at')
					&& $table->hasColumn('response_deadline')
					&& $table->hasIndex('att_appt_closed')
					&& $table->hasIndex('att_appt_deadline');
			}));

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('column closed_at'),
				$this->callback(fn ($ctx) => ($ctx['app'] ?? null) === 'attendance'),
			);

		$this->output->expects($this->once())
			->method('info')
			->with($this->stringContains('Repaired Attendance schema'));

		$this->repair->run($this->output);
	}

	public function testOnlyAddsMissingPieces(): void {
		// closed_at exists with index, response_deadline column missing.
		$schema = $this->makeSchemaWithAppointments(true, false, true, false);
		$this->connection->method('createSchema')->willReturn($schema);

		$this->connection->expects($this->once())
			->method('migrateToSchema')
			->with($this->callback(function (Schema $applied): bool {
				$table = $applied->getTable('oc_att_appointments');
				return $table->hasColumn('response_deadline')
					&& $table->hasIndex('att_appt_deadline');
			}));

		$this->repair->run($this->output);
	}

	public function testHonoursCustomTablePrefix(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')
			->with('dbtableprefix', 'oc_')
			->willReturn('nc_');

		$repair = new EnsureAppointmentSchema($this->connection, $config, $this->logger);

		$schema = new Schema();
		$table = $schema->createTable('nc_att_appointments');
		$table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
		$table->setPrimaryKey(['id']);

		$this->connection->method('createSchema')->willReturn($schema);
		$this->connection->expects($this->once())->method('migrateToSchema');

		$repair->run($this->output);
	}

	public function testGetNameIsHumanReadable(): void {
		$this->assertNotEmpty($this->repair->getName());
	}
}
