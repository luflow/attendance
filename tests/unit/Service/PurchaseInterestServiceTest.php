<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Notification\LicenseNotifier;
use OCA\Attendance\Service\LicenseService;
use OCA\Attendance\Service\PermissionService;
use OCA\Attendance\Service\PurchaseInterestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PurchaseInterestServiceTest extends TestCase {
	private const DAY = 86400;

	/** @var array<string, int> */
	private array $appValues = [];
	private int $now = 1_800_000_000;
	/** @var array<string, mixed> What the license service reports */
	private array $status = [];
	/** @var list<string> Users a notification was sent to */
	private array $notified = [];
	private int $withdrawn = 0;
	private PurchaseInterestService $service;

	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0) => $this->appValues[$key] ?? $default,
		);
		$appConfig->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->appValues[$key] = $value;
			return true;
		});
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturnCallback(fn () => $this->now);
		$timeFactory->method('getDateTime')->willReturnCallback(fn () => new \DateTime('@' . $this->now));

		$licenseService = $this->createMock(LicenseService::class);
		$licenseService->method('getStatus')->willReturnCallback(fn () => $this->status);

		$notificationManager = $this->createMock(INotificationManager::class);
		$notificationManager->method('createNotification')->willReturnCallback(fn () => $this->notification());
		$notificationManager->method('notify')->willReturnCallback(function (INotification $notification): void {
			$this->assertSame(LicenseNotifier::APP, $notification->getApp());
			$this->assertSame(LicenseNotifier::SUBJECT_PURCHASE_INTEREST, $notification->getSubject());
			$this->notified[] = $notification->getUser();
		});
		$notificationManager->method('markProcessed')->willReturnCallback(function (INotification $notification): void {
			$this->assertSame(LicenseNotifier::APP, $notification->getApp());
			$this->assertSame('', $notification->getUser());
			$this->withdrawn++;
		});

		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('getAdminUsers')->willReturn([$this->user('alice'), $this->user('bob')]);

		$this->service = new PurchaseInterestService(
			$licenseService,
			$notificationManager,
			$permissionService,
			$appConfig,
			$timeFactory,
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * A notification that remembers what was set on it.
	 */
	private function notification(): INotification {
		$state = ['app' => '', 'user' => '', 'subject' => ''];
		$notification = $this->createMock(INotification::class);
		foreach (['App' => 'app', 'User' => 'user', 'Subject' => 'subject'] as $method => $field) {
			$notification->method('set' . $method)->willReturnCallback(function (string $value) use (&$state, $field, $notification) {
				$state[$field] = $value;
				return $notification;
			});
			$notification->method('get' . $method)->willReturnCallback(function () use (&$state, $field) {
				return $state[$field];
			});
		}
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		return $notification;
	}

	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function relayReports(string $licenseStatus, ?int $interestAt): void {
		$this->status = [
			'state' => 'ok',
			'license' => [
				'status' => $licenseStatus,
				'tier' => null,
				'memberLimit' => null,
				'deviceCount' => 3,
				'trialEndsAt' => null,
				'expiresAt' => null,
				'groupTrialAvailable' => false,
				'groupTrialDevices' => 0,
				'trialExtensionAvailable' => false,
				'purchaseInterestAt' => $interestAt === null ? null : gmdate('Y-m-d\TH:i:s\Z', $interestAt),
			],
			'checkedAt' => gmdate('Y-m-d\TH:i:s\Z', $this->now),
		];
	}

	public function testNewInterestNotifiesEveryAdmin(): void {
		$this->relayReports('trial', $this->now - self::DAY);

		$this->service->check();

		$this->assertSame(['alice', 'bob'], $this->notified);
	}

	public function testTheSameInterestIsReportedOnce(): void {
		$this->relayReports('expired', $this->now - self::DAY);

		$this->service->check();
		$this->now += 40 * self::DAY;
		$this->service->check();

		$this->assertSame(['alice', 'bob'], $this->notified);
	}

	public function testRepeatedInterestWaitsThirtyDays(): void {
		$this->relayReports('trial', $this->now - self::DAY);
		$this->service->check();

		// A member answers the feedback again the next day, and the day after.
		$this->now += self::DAY;
		$this->relayReports('trial', $this->now);
		$this->service->check();
		$this->assertCount(2, $this->notified);

		$this->now += 30 * self::DAY;
		$this->service->check();
		$this->assertSame(['alice', 'bob', 'alice', 'bob'], $this->notified);
	}

	public function testANewNotificationReplacesTheOldOne(): void {
		$this->relayReports('trial', $this->now - self::DAY);

		$this->service->check();

		$this->assertSame(1, $this->withdrawn);
	}

	public function testNoInterestNoNotification(): void {
		$this->relayReports('trial', null);

		$this->service->check();

		$this->assertSame([], $this->notified);
		$this->assertSame(0, $this->withdrawn);
	}

	public function testALicensedInstanceIsLeftAloneAndCleared(): void {
		// The relay never sends the field for a licensed server; even if it did, nobody is waiting.
		$this->relayReports('active', $this->now - self::DAY);

		$this->service->check();

		$this->assertSame([], $this->notified);
		$this->assertSame(1, $this->withdrawn);
	}

	public function testNothingHappensWhenTheStatusIsNotKnown(): void {
		$this->status = ['state' => 'unavailable', 'license' => null, 'checkedAt' => null];

		$this->service->check();

		$this->assertSame([], $this->notified);
		$this->assertSame(0, $this->withdrawn);
	}
}
