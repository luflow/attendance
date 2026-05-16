<?php

declare(strict_types=1);

namespace OCA\Attendance\BackgroundJob;

use OCA\Attendance\Audit\Verb;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Service\AuditEventService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class AutoCloseJob extends TimedJob {
	/** @var int Interval in seconds (5 minutes) */
	private const INTERVAL = 300;

	private AppointmentMapper $appointmentMapper;
	private AuditEventService $auditEventService;
	private LoggerInterface $logger;

	public function __construct(
		ITimeFactory $time,
		AppointmentMapper $appointmentMapper,
		AuditEventService $auditEventService,
		LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->appointmentMapper = $appointmentMapper;
		$this->auditEventService = $auditEventService;
		$this->logger = $logger;

		$this->setInterval(self::INTERVAL);
	}

	protected function run($argument): void {
		$now = gmdate('Y-m-d H:i:s');
		$closedIds = $this->appointmentMapper->autoCloseExpired($now);
		if (empty($closedIds)) {
			return;
		}

		foreach ($closedIds as $id) {
			$this->auditEventService->recordAppointmentLifecycle(
				Verb::APPOINTMENT_CLOSED,
				$id,
				Verb::SOURCE_AUTO_CLOSE,
			);
		}

		$this->logger->info('Auto-closed appointments past their deadline or start time', [
			'count' => count($closedIds),
			'now' => $now,
		]);
	}
}
