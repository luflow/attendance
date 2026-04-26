<?php

declare(strict_types=1);

namespace OCA\Attendance\BackgroundJob;

use OCA\Attendance\Db\AppointmentMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class AutoCloseJob extends TimedJob {
	/** @var int Interval in seconds (5 minutes) */
	private const INTERVAL = 300;

	private AppointmentMapper $appointmentMapper;
	private LoggerInterface $logger;

	public function __construct(
		ITimeFactory $time,
		AppointmentMapper $appointmentMapper,
		LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->appointmentMapper = $appointmentMapper;
		$this->logger = $logger;

		$this->setInterval(self::INTERVAL);
	}

	protected function run($argument): void {
		$now = gmdate('Y-m-d H:i:s');
		$count = $this->appointmentMapper->autoCloseExpired($now);
		if ($count > 0) {
			$this->logger->info('Auto-closed appointments past their deadline', [
				'count' => $count,
				'now' => $now,
			]);
		}
	}
}
