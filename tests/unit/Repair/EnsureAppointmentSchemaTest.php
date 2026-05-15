<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Repair;

use Doctrine\DBAL\Schema\Schema;
use OCA\Attendance\Repair\EnsureAppointmentSchema;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
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

	/**
	 * @param array{closedAt?: bool, deadline?: bool, closedIdx?: bool, deadlineIdx?: bool} $present
	 */
	private function makeAppointmentsSchema(string $prefix = 'oc_', array $present = []): Schema {
		$flags = $present + [
			'closedAt' => true,
			'deadline' => true,
			'closedIdx' => true,
			'deadlineIdx' => true,
		];

		$schema = new Schema();
		$table = $schema->createTable($prefix . 'att_appointments');
		$table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
		$table->setPrimaryKey(['id']);
		if ($flags['closedAt']) {
			$table->addColumn('closed_at', 'datetime', ['notnull' => false]);
		}
		if ($flags['deadline']) {
			$table->addColumn('response_deadline', 'datetime', ['notnull' => false]);
		}
		if ($flags['closedIdx'] && $flags['closedAt']) {
			$table->addIndex(['closed_at'], 'att_appt_closed');
		}
		if ($flags['deadlineIdx'] && $flags['deadline']) {
			$table->addIndex(['response_deadline'], 'att_appt_deadline');
		}
		return $schema;
	}

	private function expectColumnProbe(bool $columnsExist): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		if ($columnsExist) {
			$result = $this->createMock(\OCP\DB\IResult::class);
			$qb->method('executeQuery')->willReturn($result);
		} else {
			$qb->method('executeQuery')->willThrowException(new Exception('Unknown column closed_at'));
		}
		$this->connection->method('getQueryBuilder')->willReturn($qb);
	}

	public function testNoOpWhenTableMissing(): void {
		$this->connection->method('tableExists')->with('att_appointments')->willReturn(false);
		$this->connection->expects($this->never())->method('createSchema');
		$this->connection->expects($this->never())->method('migrateToSchema');

		$this->repair->run($this->output);
	}

	public function testSkipsSchemaIntrospectionWhenColumnProbeSucceeds(): void {
		$this->connection->method('tableExists')->willReturn(true);
		$this->expectColumnProbe(columnsExist: true);

		$this->connection->expects($this->never())->method('createSchema');
		$this->connection->expects($this->never())->method('migrateToSchema');
		$this->logger->expects($this->never())->method('warning');

		$this->repair->run($this->output);
	}

	public function testAddsMissingColumnsAndIndexes(): void {
		$this->connection->method('tableExists')->willReturn(true);
		$this->expectColumnProbe(columnsExist: false);

		$schema = $this->makeAppointmentsSchema(present: [
			'closedAt' => false,
			'deadline' => false,
			'closedIdx' => false,
			'deadlineIdx' => false,
		]);
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

	public function testRepairsOnlyMissingDeadlinePieces(): void {
		$this->connection->method('tableExists')->willReturn(true);
		$this->expectColumnProbe(columnsExist: false);

		// closed_at + its index are present, but response_deadline is missing entirely.
		$schema = $this->makeAppointmentsSchema(present: [
			'deadline' => false,
			'deadlineIdx' => false,
		]);
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

		$this->connection->method('tableExists')->willReturn(true);
		$this->expectColumnProbe(columnsExist: false);
		$this->connection->method('createSchema')->willReturn($this->makeAppointmentsSchema('nc_', [
			'closedAt' => false,
			'deadline' => false,
			'closedIdx' => false,
			'deadlineIdx' => false,
		]));
		$this->connection->expects($this->once())->method('migrateToSchema');

		$repair->run($this->output);
	}
}
