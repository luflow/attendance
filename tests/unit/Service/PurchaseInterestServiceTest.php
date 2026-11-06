<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Notification\LicenseNotifier;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\LicenseService;
use OCA\Attendance\Service\NotificationService;
use OCA\Attendance\Service\PurchaseInterestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PurchaseInterestServiceTest extends TestCase {
	private const DAY = 86400;

	/** @var array<string, string|int> */
	private array $appValues = [];
	private int $now = 1_800_000_000;
	/** @var array<string, mixed> What the relay answers */
	private array $relay = [];
	/** @var list<string> Users a notification was sent to */
	private array $notified = [];
	private int $withdrawn = 0;
	/** @var IGroupManager|MockObject */
	private $groupManager;
	private PurchaseInterestService $service;

	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => (string)($this->appValues[$key] ?? $default),
		);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->appValues[$key] = $value;
			return true;
		});
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0) => (int)($this->appValues[$key] ?? $default),
		);
		$appConfig->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->appValues[$key] = $value;
			return true;
		});
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturnCallback(fn () => $this->now);
		$timeFactory->method('getDateTime')->willReturnCallback(fn () => new \DateTime('@' . $this->now));

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturnCallback(fn () => json_encode($this->relay));
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$notificationService = $this->createMock(NotificationService::class);
		$notificationService->method('getPushDeviceIdentifiers')->willReturn(['dev-a']);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('n', 48));
		$configService = $this->createMock(ConfigService::class);
		$configService->method('getPushProxyServer')->willReturn('https://push.example.org');

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

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->admins(['alice' => true, 'bob' => true, 'former' => false]);

		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new PurchaseInterestService(
			new LicenseService($configService, $notificationService, $clientService, $appConfig, $random, $timeFactory, $logger),
			$notificationManager,
			$this->groupManager,
			$appConfig,
			$timeFactory,
			$logger,
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

	/**
	 * @param array<string, bool> $admins uid => enabled
	 */
	private function admins(array $admins): void {
		$users = [];
		foreach ($admins as $uid => $enabled) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('isEnabled')->willReturn($enabled);
			$users[] = $user;
		}
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$this->groupManager->method('get')->with('admin')->willReturn($group);
	}

	private function relayReports(string $status, ?int $interestAt): void {
		$this->relay = ['status' => $status, 'deviceCount' => 3];
		if ($interestAt !== null) {
			$this->relay['purchaseInterestAt'] = gmdate('Y-m-d\TH:i:s\Z', $interestAt);
		}
	}

	public function testNewInterestNotifiesEveryEnabledAdmin(): void {
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
		$this->relay = ['status' => 'bogus'];

		$this->service->check();

		$this->assertSame([], $this->notified);
		$this->assertSame(0, $this->withdrawn);
	}
}
