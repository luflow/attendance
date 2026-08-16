<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\BackgroundJob;

use OCA\Attendance\Audit\Verb;
use OCA\Attendance\BackgroundJob\AutoCloseJob;
use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Service\AppointmentService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AutoCloseJobTest extends TestCase {
	private AppointmentMapper|MockObject $appointmentMapper;
	private AppointmentService|MockObject $appointmentService;
	private LoggerInterface|MockObject $logger;
	private ITimeFactory|MockObject $timeFactory;
	private AutoCloseJob $job;

	protected function setUp(): void {
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->appointmentService = $this->createMock(AppointmentService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);

		$this->job = new AutoCloseJob(
			$this->timeFactory,
			$this->appointmentMapper,
			$this->appointmentService,
			$this->logger,
		);
	}

	private function runJob(): void {
		$reflection = new \ReflectionMethod($this->job, 'run');
		$reflection->invoke($this->job, null);
	}

	public function testQueriesDueAppointmentsWithUtcNow(): void {
		$this->appointmentMapper->expects($this->once())
			->method('findDueForAutoClose')
			->with($this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/'))
			->willReturn([]);

		$this->runJob();
	}

	public function testClosesEachAppointmentThroughTheService(): void {
		$ids = [11, 22, 33];
		$this->appointmentMapper->method('findDueForAutoClose')->willReturn($ids);

		$closed = [];
		$this->appointmentService->expects($this->exactly(3))
			->method('closeAppointment')
			->willReturnCallback(function (int $id, string $source) use (&$closed): Appointment {
				$this->assertSame(Verb::SOURCE_AUTO_CLOSE, $source);
				$closed[] = $id;
				return new Appointment();
			});

		$this->logger->expects($this->once())
			->method('info')
			->with(
				'Auto-closed appointments past their deadline or start time',
				$this->callback(fn ($ctx) => ($ctx['count'] ?? null) === 3 && isset($ctx['now'])),
			);

		$this->runJob();

		$this->assertSame($ids, $closed);
	}

	/**
	 * One appointment blowing up must not strand the rest of the batch.
	 */
	public function testKeepsGoingWhenOneAppointmentFails(): void {
		$this->appointmentMapper->method('findDueForAutoClose')->willReturn([1, 2]);

		$this->appointmentService->method('closeAppointment')
			->willReturnCallback(function (int $id): Appointment {
				if ($id === 1) {
					throw new \RuntimeException('boom');
				}
				return new Appointment();
			});

		$this->logger->expects($this->once())->method('error');
		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->anything(),
				$this->callback(fn ($ctx) => ($ctx['count'] ?? null) === 1),
			);

		$this->runJob();
	}

	public function testDoesNothingWhenNothingIsDue(): void {
		$this->appointmentMapper->method('findDueForAutoClose')->willReturn([]);

		$this->appointmentService->expects($this->never())->method('closeAppointment');
		$this->logger->expects($this->never())->method('info');

		$this->runJob();
	}
}
