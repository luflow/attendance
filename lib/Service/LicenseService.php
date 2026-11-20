<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\AppInfo\Application;
use OCA\Attendance\Db\DatetimeFormatTrait;
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
class LicenseService {
	use DatetimeFormatTrait;

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
		/** @var AttendanceLicenseStatus|null $cached */
		$cached = $this->recall(self::CACHE_KEY);
		if (!$refresh && $cached !== null) {
			return $cached;
		}

		$status = $this->fetchStatus();
		// A refresh the relay turns away (it throttles per server) must not wipe a good answer.
		if ($status['state'] === 'unavailable' && $cached !== null && $cached['state'] === 'ok') {
			return $cached;
		}
		$status['checkedAt'] = $this->formatUtcDatetime(new \DateTimeImmutable('@' . $now));
		$ttl = $status['state'] === 'ok' || $status['state'] === 'no_devices' ? self::STATUS_TTL : self::FAILURE_TTL;
		$this->remember(self::CACHE_KEY, $status, $ttl);
		return $status;
	}

	/**
	 * Whether the relay presents the nonce this server handed out last.
	 */
	public function isValidProof(string $nonce): bool {
		$pending = $this->recall(self::NONCE_KEY);
		return $nonce !== '' && isset($pending['nonce']) && is_string($pending['nonce']) && hash_equals($pending['nonce'], $nonce);
	}

	/**
	 * @return AttendanceLicenseStatus
	 */
	private function fetchStatus(): array {
		$proxy = $this->configService->getPushProxyServer();
		$devices = $this->notificationService->getPushDeviceIdentifiers($proxy, self::MAX_DEVICES);
		if ($devices === []) {
			return self::status('no_devices');
		}

		$nonce = $this->secureRandom->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->remember(self::NONCE_KEY, ['nonce' => $nonce], self::NONCE_TTL);

		try {
			$response = $this->clientService->newClient()->post(rtrim($proxy, '/') . '/license/server-status', [
				'json' => ['deviceIdentifiers' => $devices, 'nonce' => $nonce],
				'timeout' => 15,
				'http_errors' => false,
			]);
			$code = $response->getStatusCode();
			$body = (string)$response->getBody();
		} catch (\Exception $e) {
			$this->logger->info('License status: relay not reachable: ' . $e->getMessage());
			return self::status('unavailable');
		} finally {
			$this->appConfig->deleteKey(Application::APP_ID, self::NONCE_KEY);
		}

		// 403: the relay could not fetch the proof, so it cannot reach this server.
		if ($code === 403) {
			return self::status('unreachable');
		}
		$license = $code === 200 ? $this->parseLicense($body) : null;
		if ($license === null) {
			$this->logger->info('License status: relay answered ' . $code);
			return self::status('unavailable');
		}
		return self::status('ok', $license);
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
			'purchaseInterestAt' => self::nonEmptyString($data['purchaseInterestAt'] ?? null),
		];
	}

	private static function nonEmptyString(mixed $value): ?string {
		return is_string($value) && $value !== '' ? $value : null;
	}

	private static function intOrZero(mixed $value): int {
		return is_int($value) ? $value : 0;
	}

	/**
	 * @param 'ok'|'no_devices'|'unreachable'|'unavailable' $state
	 * @param AttendanceLicenseInfo|null $license
	 * @return AttendanceLicenseStatus
	 */
	private static function status(string $state, ?array $license = null): array {
		return ['state' => $state, 'license' => $license, 'checkedAt' => null];
	}

	/**
	 * App config as a store with expiry, so the nonce outlives the request the
	 * relay answers in and the status survives between page loads.
	 */
	private function remember(string $key, array $value, int $ttl): void {
		$this->appConfig->setValueString(
			Application::APP_ID,
			$key,
			json_encode(['until' => $this->timeFactory->getTime() + $ttl, 'value' => $value], JSON_THROW_ON_ERROR),
			lazy: true,
		);
	}

	/**
	 * @return array<array-key, mixed>|null
	 */
	private function recall(string $key): ?array {
		$stored = json_decode($this->appConfig->getValueString(Application::APP_ID, $key, '', lazy: true), true);
		if (!is_array($stored) || !is_int($stored['until'] ?? null) || $stored['until'] < $this->timeFactory->getTime()
			|| !isset($stored['value']) || !is_array($stored['value'])) {
			return null;
		}
		return $stored['value'];
	}
}
