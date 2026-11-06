<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * The mobile-app license of this instance, as the push relay knows it.
 *
 * The relay cannot take a signature from the server, so the server proves it
 * owns its URL: it hands the relay a nonce, and the relay fetches it back from
 * the public proof endpoint before it answers.
 *
 * @psalm-import-type AttendanceLicenseStatus from \OCA\Attendance\ResponseDefinitions
 * @psalm-import-type AttendanceLicenseInfo from \OCA\Attendance\ResponseDefinitions
 */
final class LicenseService {
	private const NONCE_KEY = 'license_proof_nonce';
	private const CACHE_KEY = 'license_status_cache';
	private const NONCE_TTL = 120;
	private const STATUS_TTL = 600;
	private const FAILURE_TTL = 120;
	private const MAX_DEVICES = 20;

	public function __construct(
		private ConfigService $configService,
		private NotificationService $notificationService,
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private ISecureRandom $secureRandom,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return AttendanceLicenseStatus
	 */
	public function getStatus(bool $refresh = false): array {
		$now = $this->timeFactory->getTime();
		$cached = $this->readCache($now);
		if (!$refresh && $cached !== null) {
			return $cached;
		}

		$status = $this->fetchStatus();
		// A refresh the relay turns away (it throttles per server) must not wipe a good answer.
		if ($status['state'] === 'unavailable' && $cached !== null && $cached['state'] === 'ok') {
			return $cached;
		}
		$status['checkedAt'] = gmdate('Y-m-d\TH:i:s\Z', $now);
		$ttl = $status['state'] === 'ok' || $status['state'] === 'no_devices' ? self::STATUS_TTL : self::FAILURE_TTL;
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CACHE_KEY,
			json_encode(['until' => $now + $ttl, 'status' => $status], JSON_THROW_ON_ERROR),
			lazy: true,
		);
		return $status;
	}

	/**
	 * Whether the relay presents the nonce this server handed out last.
	 */
	public function isValidProof(string $nonce): bool {
		$stored = (array)json_decode($this->appConfig->getValueString(Application::APP_ID, self::NONCE_KEY, '', lazy: true), true);
		if (!isset($stored['nonce'], $stored['until']) || !is_string($stored['nonce']) || !is_int($stored['until']) || $nonce === '') {
			return false;
		}
		return $stored['until'] >= $this->timeFactory->getTime() && hash_equals($stored['nonce'], $nonce);
	}

	/**
	 * @return AttendanceLicenseStatus
	 */
	private function fetchStatus(): array {
		$proxy = rtrim($this->configService->getPushProxyServer(), '/');
		$devices = $this->notificationService->getPushDeviceIdentifiers($proxy, self::MAX_DEVICES);
		if ($devices === []) {
			return ['state' => 'no_devices', 'license' => null, 'checkedAt' => null];
		}

		$nonce = $this->secureRandom->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::NONCE_KEY,
			json_encode(['nonce' => $nonce, 'until' => $this->timeFactory->getTime() + self::NONCE_TTL], JSON_THROW_ON_ERROR),
			lazy: true,
		);

		try {
			$response = $this->clientService->newClient()->post($proxy . '/license/server-status', [
				'json' => ['deviceIdentifiers' => $devices, 'nonce' => $nonce],
				'timeout' => 15,
				'http_errors' => false,
			]);
			$code = $response->getStatusCode();
			$body = (string)$response->getBody();
		} catch (\Exception $e) {
			$this->logger->info('License status: relay not reachable: ' . $e->getMessage());
			return ['state' => 'unavailable', 'license' => null, 'checkedAt' => null];
		} finally {
			$this->appConfig->deleteKey(Application::APP_ID, self::NONCE_KEY);
		}

		// 403: the relay could not fetch the proof, so it cannot reach this server.
		if ($code === 403) {
			return ['state' => 'unreachable', 'license' => null, 'checkedAt' => null];
		}
		$license = $code === 200 ? $this->parseLicense($body) : null;
		if ($license === null) {
			$this->logger->info('License status: relay answered ' . $code);
			return ['state' => 'unavailable', 'license' => null, 'checkedAt' => null];
		}
		return ['state' => 'ok', 'license' => $license, 'checkedAt' => null];
	}

	/**
	 * @return AttendanceLicenseInfo|null
	 */
	private function parseLicense(string $body): ?array {
		$data = json_decode($body, true);
		if (!is_array($data)) {
			return null;
		}
		$status = match ($data['status'] ?? null) {
			'trial' => 'trial',
			'active' => 'active',
			'expired' => 'expired',
			'none' => 'none',
			default => null,
		};
		if ($status === null) {
			return null;
		}
		return [
			'status' => $status,
			'tier' => self::nonEmptyString($data['tier'] ?? null),
			'memberLimit' => self::intOrZero($data['memberLimit'] ?? null) ?: null,
			'deviceCount' => self::intOrZero($data['deviceCount'] ?? null),
			'trialEndsAt' => self::nonEmptyString($data['trialEndsAt'] ?? null),
			'expiresAt' => self::nonEmptyString($data['expiresAt'] ?? null),
			'groupTrialAvailable' => ($data['groupTrialAvailable'] ?? false) === true,
			'groupTrialDevices' => self::intOrZero($data['groupTrialDevices'] ?? null),
			'trialExtensionAvailable' => ($data['trialExtensionAvailable'] ?? false) === true,
		];
	}

	private static function nonEmptyString(mixed $value): ?string {
		return is_string($value) && $value !== '' ? $value : null;
	}

	private static function intOrZero(mixed $value): int {
		return is_int($value) ? $value : 0;
	}

	/**
	 * @return AttendanceLicenseStatus|null
	 */
	private function readCache(int $now): ?array {
		$cached = json_decode($this->appConfig->getValueString(Application::APP_ID, self::CACHE_KEY, '', lazy: true), true);
		if (!is_array($cached) || !is_int($cached['until'] ?? null) || $cached['until'] < $now || !is_array($cached['status'] ?? null)) {
			return null;
		}
		/** @var AttendanceLicenseStatus $status */
		$status = $cached['status'];
		return $status;
	}
}
