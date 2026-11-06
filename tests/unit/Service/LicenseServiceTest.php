<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\LicenseService;
use OCA\Attendance\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LicenseServiceTest extends TestCase {
	private const NONCE = 'nonce0123456789abcdefghijklmnopqrstuvwxyzABCDEFGH';

	/** @var array<string, string> */
	private array $appValues = [];
	private int $now = 1_800_000_000;
	/** @var NotificationService|MockObject */
	private $notificationService;
	/** @var IClient|MockObject */
	private $client;
	private LicenseService $service;

	protected function setUp(): void {
		$configService = $this->createMock(ConfigService::class);
		$configService->method('getPushProxyServer')->willReturn('https://push.example.org/');
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->client = $this->createMock(IClient::class);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $this->appValues[$key] ?? $default,
		);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->appValues[$key] = $value;
			return true;
		});
		$appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->appValues[$key]);
		});

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(self::NONCE);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturnCallback(fn () => $this->now);

		$this->service = new LicenseService(
			$configService,
			$this->notificationService,
			$clientService,
			$appConfig,
			$random,
			$timeFactory,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function relayAnswers(int $code, string $body): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($code);
		$response->method('getBody')->willReturn($body);
		$this->client->method('post')->willReturn($response);
	}

	public function testNoDevicesSkipsTheRelay(): void {
		$this->notificationService->method('getPushDeviceIdentifiers')->willReturn([]);
		$this->client->expects($this->never())->method('post');

		$status = $this->service->getStatus();

		$this->assertSame('no_devices', $status['state']);
		$this->assertNull($status['license']);
	}

	public function testRelayIsAskedWithDevicesAndAProofThatHoldsDuringTheCall(): void {
		$this->notificationService->expects($this->once())
			->method('getPushDeviceIdentifiers')
			->with('https://push.example.org', 20)
			->willReturn(['dev-a', 'dev-b']);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(json_encode([
			'status' => 'trial',
			'deviceCount' => 2,
			'trialEndsAt' => '2027-01-10T12:00:00Z',
			'groupTrialAvailable' => true,
			'groupTrialDevices' => 3,
			'purchaseInterestAt' => '2026-11-01T09:30:00Z',
		]));
		$this->client->expects($this->once())
			->method('post')
			->with('https://push.example.org/license/server-status', $this->callback(function (array $options): bool {
				// The relay fetches the proof while this request is still open.
				$this->assertTrue($this->service->isValidProof(self::NONCE));
				$this->assertFalse($this->service->isValidProof('something-else'));
				return $options['json'] === ['deviceIdentifiers' => ['dev-a', 'dev-b'], 'nonce' => self::NONCE];
			}))
			->willReturn($response);

		$status = $this->service->getStatus();

		$this->assertSame('ok', $status['state']);
		$this->assertSame([
			'status' => 'trial',
			'tier' => null,
			'memberLimit' => null,
			'deviceCount' => 2,
			'trialEndsAt' => '2027-01-10T12:00:00Z',
			'expiresAt' => null,
			'groupTrialAvailable' => true,
			'groupTrialDevices' => 3,
			'trialExtensionAvailable' => false,
			'purchaseInterestAt' => '2026-11-01T09:30:00Z',
		], $status['license']);
		$this->assertNotNull($status['checkedAt']);
		// Single use: once the relay has answered the proof is gone.
		$this->assertFalse($this->service->isValidProof(self::NONCE));
	}

	public function testPaidLicenseIsParsed(): void {
		$this->notificationService->method('getPushDeviceIdentifiers')->willReturn(['dev-a']);
		$this->relayAnswers(200, json_encode([
			'status' => 'active',
			'tier' => 'standard',
			'memberLimit' => 100,
			'deviceCount' => 25,
			'expiresAt' => '2027-09-11T00:00:00Z',
		]));

		$license = $this->service->getStatus()['license'];

		$this->assertSame('active', $license['status']);
		$this->assertSame('standard', $license['tier']);
		$this->assertSame(100, $license['memberLimit']);
		$this->assertSame('2027-09-11T00:00:00Z', $license['expiresAt']);
	}

	public function testFailedProofMeansTheRelayCannotReachTheServer(): void {
		$this->notificationService->method('getPushDeviceIdentifiers')->willReturn(['dev-a']);
		$this->relayAnswers(403, '{"error":"proof failed"}');

		$this->assertSame('unreachable', $this->service->getStatus()['state']);
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function unusableAnswers(): array {
		return [
			'relay without the endpoint' => [404, '404 page not found'],
			'server error' => [500, ''],
			'unknown status value' => [200, '{"status":"paused"}'],
			'not JSON' => [200, '<html>'],
		];
	}

	/**
	 * @dataProvider unusableAnswers
	 */
	public function testUnusableAnswerIsUnavailable(int $code, string $body): void {
		$this->notificationService->method('getPushDeviceIdentifiers')->willReturn(['dev-a']);
		$this->relayAnswers($code, $body);

		$this->assertSame('unavailable', $this->service->getStatus()['state']);
	}

	public function testUnreachableRelayIsUnavailable(): void {
		$this->notificationService->method('getPushDeviceIdentifiers')->willReturn(['dev-a']);
		$this->client->method('post')->willThrowException(new \RuntimeException('timeout'));

		$this->assertSame('unavailable', $this->service->getStatus()['state']);
		$this->assertFalse($this->service->isValidProof(self::NONCE));
	}

	public function testStatusIsCachedUntilRefreshOrExpiry(): void {
		$this->notificationService->method('getPushDeviceIdentifiers')->willReturn(['dev-a']);
		$this->relayAnswers(200, '{"status":"none","deviceCount":1}');
		$this->client->expects($this->exactly(3))->method('post');

		$this->service->getStatus();
		$this->service->getStatus();
		$this->service->getStatus(true);
		$this->now += 601;
		$this->service->getStatus();
	}

	public function testThrottledRefreshKeepsTheGoodAnswer(): void {
		$this->notificationService->method('getPushDeviceIdentifiers')->willReturn(['dev-a']);
		$ok = $this->createMock(IResponse::class);
		$ok->method('getStatusCode')->willReturn(200);
		$ok->method('getBody')->willReturn('{"status":"active","tier":"basic","deviceCount":4}');
		$throttled = $this->createMock(IResponse::class);
		$throttled->method('getStatusCode')->willReturn(429);
		$throttled->method('getBody')->willReturn('{"error":"too many requests"}');
		$this->client->method('post')->willReturnOnConsecutiveCalls($ok, $throttled);

		$first = $this->service->getStatus();
		$second = $this->service->getStatus(true);

		$this->assertSame('ok', $second['state']);
		$this->assertSame($first, $second);
		$this->assertSame($first, $this->service->getStatus());
	}

	public function testFailureIsCachedBriefly(): void {
		$this->notificationService->method('getPushDeviceIdentifiers')->willReturn(['dev-a']);
		$this->relayAnswers(403, '');
		$this->client->expects($this->exactly(2))->method('post');

		$this->service->getStatus();
		$this->now += 60;
		$this->service->getStatus();
		$this->now += 61;
		$this->service->getStatus();
	}

	public function testExpiredProofIsRejected(): void {
		$this->appValues['license_proof_nonce'] = json_encode(['nonce' => self::NONCE, 'until' => $this->now - 1]);

		$this->assertFalse($this->service->isValidProof(self::NONCE));
	}

	public function testNoProofWithoutAPendingRequest(): void {
		$this->assertFalse($this->service->isValidProof(self::NONCE));
		$this->assertFalse($this->service->isValidProof(''));
	}
}
