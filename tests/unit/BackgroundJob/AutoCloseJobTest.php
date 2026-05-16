<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\BackgroundJob;

use OCA\Attendance\Audit\Verb;
use OCA\Attendance\BackgroundJob\AutoCloseJob;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Service\AuditEventService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AutoCloseJobTest extends TestCase {
	private AppointmentMapper|MockObject $appointmentMapper;
	private AuditEventService|MockObject $auditEventService;
	private LoggerInterface|MockObject $logger;
	private ITimeFactory|MockObject $timeFactory;
	private AutoCloseJob $job;

	protected function setUp(): void {
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->auditEventService = $this->createMock(AuditEventService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);

		$this->job = new AutoCloseJob(
			$this->timeFactory,
			$this->appointmentMapper,
			$this->auditEventService,
			$this->logger,
		);
	}

	private function runJob(): void {
		$reflection = new \ReflectionMethod($this->job, 'run');
		$reflection->invoke($this->job, null);
	}

	public function testCallsAutoCloseExpiredWithUtcNow(): void {
		$this->appointmentMapper->expects($this->once())
			->method('autoCloseExpired')
			->with($this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/'))
			->willReturn([]);

		$this->runJob();
	}

	public function testLogsAndRecordsAuditWhenAppointmentsClosed(): void {
		$ids = [11, 22, 33];
		$this->appointmentMapper->method('autoCloseExpired')->willReturn($ids);

		$this->auditEventService->expects($this->exactly(3))
			->method('recordAppointmentLifecycle')
			->with(
				Verb::APPOINTMENT_CLOSED,
				$this->callback(fn ($id) => in_array($id, $ids, true)),
				Verb::SOURCE_AUTO_CLOSE,
			);

		$this->logger->expects($this->once())
			->method('info')
			->with(
				'Auto-closed appointments past their deadline or start time',
				$this->callback(fn ($ctx) => ($ctx['count'] ?? null) === 3 && isset($ctx['now'])),
			);

		$this->runJob();
	}

	public function testDoesNotLogOrAuditWhenNothingClosed(): void {
		$this->appointmentMapper->method('autoCloseExpired')->willReturn([]);

		$this->auditEventService->expects($this->never())->method('recordAppointmentLifecycle');
		$this->logger->expects($this->never())->method('info');

		$this->runJob();
	}
}
