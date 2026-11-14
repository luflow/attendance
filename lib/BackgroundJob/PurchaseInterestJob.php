<?php

declare(strict_types=1);

namespace OCA\Attendance\BackgroundJob;

use OCA\Attendance\Service\PurchaseInterestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Asks the push relay once a day whether a member wants the mobile app but
 * cannot buy it, and lets the admins know.
 */
final class PurchaseInterestJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private PurchaseInterestService $purchaseInterestService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(86400);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		try {
			$this->purchaseInterestService->check();
		} catch (\Throwable $e) {
			$this->logger->error('Checking for purchase interest failed', ['exception' => $e]);
		}
	}
}
