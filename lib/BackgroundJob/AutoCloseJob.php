<?php

declare(strict_types=1);

namespace OCA\Attendance\BackgroundJob;

use OCA\Attendance\Audit\Verb;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Service\AppointmentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Closes inquiries once their deadline passed or the appointment started.
 *
 * Goes through AppointmentService per appointment rather than bulk-updating the
 * table: closing has a tail — the booking notification wave and, when opted in,
 * the Talk conversation — and an inquiry that closed by deadline deserves the
 * same treatment as one a manager closed by hand. Bulk-updating used to skip
 * that tail entirely, so scheduled people were never told they had a place.
 */
class AutoCloseJob extends TimedJob {
	/** @var int Interval in seconds (5 minutes) */
	private const INTERVAL = 300;

	private AppointmentMapper $appointmentMapper;
	private AppointmentService $appointmentService;
	private LoggerInterface $logger;

	public function __construct(
		ITimeFactory $time,
		AppointmentMapper $appointmentMapper,
		AppointmentService $appointmentService,
		LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->appointmentMapper = $appointmentMapper;
		$this->appointmentService = $appointmentService;
		$this->logger = $logger;

		$this->setInterval(self::INTERVAL);
	}

	protected function run($argument): void {
		$now = gmdate('Y-m-d H:i:s');
		$dueIds = $this->appointmentMapper->findDueForAutoClose($now);
		if (empty($dueIds)) {
			return;
		}

		$closed = 0;
		foreach ($dueIds as $id) {
			// One failure must not strand the rest of the batch.
			try {
				$this->appointmentService->closeAppointment($id, Verb::SOURCE_AUTO_CLOSE);
				$closed++;
			} catch (\Throwable $e) {
				$this->logger->error('Auto-closing an appointment failed', [
					'app' => 'attendance',
					'appointmentId' => $id,
					'exception' => $e,
				]);
			}
		}

		$this->logger->info('Auto-closed appointments past their deadline or start time', [
			'count' => $closed,
			'now' => $now,
		]);
	}
}
