<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\AppInfo\Application;
use OCA\Attendance\Notification\LicenseNotifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Tells the Nextcloud admins when a member said in the mobile app that buying
 * is somebody else's call, since the admin is usually that somebody.
 *
 * Nothing calls in from outside for this: the server reads the push relay's
 * license status, which carries when that last happened.
 */
final class PurchaseInterestService {
	private const SEEN_KEY = 'license_interest_seen';
	private const NOTIFIED_KEY = 'license_interest_notified';
	/** A member can answer the feedback again and again; the admins hear about it this often at most. */
	private const MIN_INTERVAL = 30 * 86400;

	public function __construct(
		private LicenseService $licenseService,
		private INotificationManager $notificationManager,
		private IGroupManager $groupManager,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	public function check(): void {
		$status = $this->licenseService->getStatus(true);
		$license = $status['license'];
		if ($status['state'] !== 'ok' || $license === null) {
			return;
		}
		if ($license['status'] === 'active') {
			$this->withdraw();
			return;
		}

		$interestAt = $license['purchaseInterestAt'] === null ? false : strtotime($license['purchaseInterestAt']);
		if ($interestAt === false || $interestAt <= $this->appConfig->getValueInt(Application::APP_ID, self::SEEN_KEY, 0, lazy: true)) {
			return;
		}
		$now = $this->timeFactory->getTime();
		if ($now - $this->appConfig->getValueInt(Application::APP_ID, self::NOTIFIED_KEY, 0, lazy: true) < self::MIN_INTERVAL) {
			return;
		}

		$this->withdraw();
		foreach ($this->groupManager->get('admin')?->getUsers() ?? [] as $admin) {
			if (!$admin->isEnabled()) {
				continue;
			}
			try {
				$notification = $this->notificationManager->createNotification();
				$notification->setApp(LicenseNotifier::APP)
					->setUser($admin->getUID())
					->setDateTime($this->timeFactory->getDateTime())
					->setObject(LicenseNotifier::OBJECT_TYPE, LicenseNotifier::SUBJECT_PURCHASE_INTEREST)
					->setSubject(LicenseNotifier::SUBJECT_PURCHASE_INTEREST);
				$this->notificationManager->notify($notification);
			} catch (\Exception $e) {
				$this->logger->error('Failed to send the purchase interest notification', [
					'userId' => $admin->getUID(),
					'error' => $e->getMessage(),
				]);
			}
		}
		$this->appConfig->setValueInt(Application::APP_ID, self::SEEN_KEY, $interestAt, lazy: true);
		$this->appConfig->setValueInt(Application::APP_ID, self::NOTIFIED_KEY, $now, lazy: true);
	}

	/**
	 * Without a user set this clears the notification for everyone.
	 */
	private function withdraw(): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(LicenseNotifier::APP)
				->setObject(LicenseNotifier::OBJECT_TYPE, LicenseNotifier::SUBJECT_PURCHASE_INTEREST);
			$this->notificationManager->markProcessed($notification);
		} catch (\Exception $e) {
			$this->logger->error('Failed to withdraw the purchase interest notification', ['error' => $e->getMessage()]);
		}
	}
}
