<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Controller;

use OCA\Attendance\Controller\LicenseController;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\LicenseService;
use OCA\Attendance\Service\NotificationService;
use OCA\Attendance\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LicenseControllerTest extends TestCase {
	private const NOW = 1_800_000_000;

	/** @var array<string, string> */
	private array $appValues = [];
	/** @var PermissionService|MockObject */
	private $permissionService;
	/** @var IUserSession|MockObject */
	private $userSession;
	/** @var IClientService|MockObject */
	private $clientService;
	private LicenseController $controller;

	protected function setUp(): void {
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->clientService = $this->createMock(IClientService::class);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $this->appValues[$key] ?? $default,
		);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->appValues[$key] = $value;
			return true;
		});
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(self::NOW);
		// No push devices: the service answers without asking the relay.
		$notificationService = $this->createMock(NotificationService::class);
		$notificationService->method('getPushDeviceIdentifiers')->willReturn([]);

		$licenseService = new LicenseService(
			$this->createMock(ConfigService::class),
			$notificationService,
			$this->clientService,
			$appConfig,
			$this->createMock(ISecureRandom::class),
			$timeFactory,
			$this->createMock(LoggerInterface::class),
		);
		$this->controller = new LicenseController(
			'attendance',
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->permissionService,
			$licenseService,
		);
	}

	private function signIn(string $uid, bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('isAdmin')->with($uid)->willReturn($admin);
	}

	public function testStatusNeedsAnAdmin(): void {
		$this->signIn('member', false);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->status()->getStatus());
		$this->assertArrayNotHasKey('license_status_cache', $this->appValues);
	}

	public function testStatusNeedsALogin(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->status()->getStatus());
	}

	public function testStatusForAnAdmin(): void {
		$this->signIn('admin', true);

		$response = $this->controller->status(true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('no_devices', $response->getData()['state']);
	}

	public function testProofEchoesThePendingNonce(): void {
		$this->appValues['license_proof_nonce'] = json_encode(['nonce' => 'abc', 'until' => self::NOW + 60]);

		$response = $this->controller->proof('abc');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['nonce' => 'abc'], $response->getData());
	}

	public function testProofHidesAnyOtherNonce(): void {
		$this->appValues['license_proof_nonce'] = json_encode(['nonce' => 'abc', 'until' => self::NOW + 60]);

		$response = $this->controller->proof('abd');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $response->getData());
	}
}
