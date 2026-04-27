<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Integration helpers for the Nextcloud Guests app (`nextcloud/guests`).
 *
 * The Guests app is optional. All accessors degrade gracefully to a "no guest
 * support" state when the Guests app is missing or its API surface changed.
 */
class GuestService {
	private const GUESTS_APP_ID = 'guests';
	private const ATTENDANCE_APP_ID = 'attendance';

	/**
	 * Group ID the Guests app puts every guest user into. Treated as a system
	 * group: hidden from response-summary sections and check-in filters
	 * unless explicitly whitelisted.
	 */
	public const GUESTS_SYSTEM_GROUP = 'guest_app';

	/**
	 * Whether a group ID identifies the Guests app's auto-membership group.
	 */
	public static function isGuestsSystemGroup(string $groupId): bool {
		return strtolower($groupId) === self::GUESTS_SYSTEM_GROUP;
	}

	/** @var array<string, bool> */
	private array $isGuestCache = [];

	private ?bool $guestsAppEnabled = null;

	public function __construct(
		private IAppManager $appManager,
		private IAppConfig $appConfig,
		private IUserManager $userManager,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether the Guests app is enabled.
	 */
	public function isGuestsAppEnabled(): bool {
		if ($this->guestsAppEnabled === null) {
			$this->guestsAppEnabled = $this->appManager->isEnabledForUser(self::GUESTS_APP_ID);
		}
		return $this->guestsAppEnabled;
	}

	/**
	 * Whether a given user is a guest account from the Guests app.
	 *
	 * Returns false (i.e. "treat as full user") whenever the Guests app is
	 * absent or its `GuestManager` cannot be resolved — the safer default for
	 * downstream permission gating is "fail open as non-guest" so we never
	 * mis-classify a real user as restricted.
	 */
	public function isGuestUser(string $userId): bool {
		if ($userId === '') {
			return false;
		}
		if (isset($this->isGuestCache[$userId])) {
			return $this->isGuestCache[$userId];
		}

		$result = false;
		if ($this->isGuestsAppEnabled()) {
			$manager = $this->resolveGuestManager();
			if ($manager !== null) {
				try {
					$result = (bool)$manager->isGuest($userId);
				} catch (\Throwable $e) {
					$this->logger->debug('GuestManager::isGuest failed', [
						'app' => self::ATTENDANCE_APP_ID,
						'exception' => $e,
					]);
					$result = false;
				}
			}
		}

		$this->isGuestCache[$userId] = $result;
		return $result;
	}

	/**
	 * Whether the Guests app's app whitelist is active. When false, guests can
	 * use any installed app and no special whitelist entry is required.
	 */
	public function isGuestsWhitelistEnabled(): bool {
		if (!$this->isGuestsAppEnabled()) {
			return false;
		}
		try {
			return $this->appConfig->getValueBool(self::GUESTS_APP_ID, 'usewhitelist', true);
		} catch (\Throwable $e) {
			$this->logger->debug('Reading guests usewhitelist failed', [
				'app' => self::ATTENDANCE_APP_ID,
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * Whether `attendance` is included in the Guests app's whitelist of apps
	 * that guest users may access. Returns true when the whitelist is disabled
	 * (in which case all apps are accessible).
	 */
	public function isAttendanceInGuestsWhitelist(): bool {
		if (!$this->isGuestsAppEnabled()) {
			return false;
		}
		if (!$this->isGuestsWhitelistEnabled()) {
			return true;
		}
		$raw = $this->appConfig->getValueString(self::GUESTS_APP_ID, 'whitelist', '');
		if ($raw === '') {
			return false;
		}
		$entries = array_map('trim', explode(',', $raw));
		return in_array(self::ATTENDANCE_APP_ID, $entries, true);
	}

	/**
	 * Create a new guest account using the Guests app's GuestManager.
	 *
	 * The Guests app must be enabled. The new account uses the email as its
	 * UID (matching the convention enforced by `occ guests:add`). When a user
	 * with that UID already exists, that user is returned instead of failing —
	 * this makes the endpoint idempotent for organizers who re-add a known
	 * email.
	 *
	 * @return array{userId: string, displayName: string, email: string, isGuest: bool, alreadyExisted: bool}
	 * @throws \RuntimeException When the Guests app is unavailable or creation fails
	 * @throws \InvalidArgumentException When the email is invalid
	 */
	public function createGuest(IUser $createdBy, string $email, string $displayName = ''): array {
		$email = trim($email);
		if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new \InvalidArgumentException('A valid email address is required to create a guest account.');
		}

		if (!$this->isGuestsAppEnabled()) {
			throw new \RuntimeException('The Nextcloud Guests app is not enabled.');
		}

		$existing = $this->userManager->get($email);
		if ($existing instanceof IUser) {
			return [
				'userId' => $existing->getUID(),
				'displayName' => $existing->getDisplayName(),
				'email' => $existing->getSystemEMailAddress() ?? $email,
				'isGuest' => $this->isGuestUser($existing->getUID()),
				'alreadyExisted' => true,
			];
		}

		$manager = $this->resolveGuestManager();
		if ($manager === null || !method_exists($manager, 'createGuest')) {
			throw new \RuntimeException('The Guests app does not expose the expected createGuest API.');
		}

		try {
			$created = $manager->createGuest($createdBy, $email, $email, $displayName);
		} catch (\Throwable $e) {
			$this->logger->warning('Creating guest account failed', [
				'app' => self::ATTENDANCE_APP_ID,
				'exception' => $e,
			]);
			throw new \RuntimeException('Failed to create guest account: ' . $e->getMessage(), 0, $e);
		}

		if (!$created instanceof IUser) {
			throw new \RuntimeException('The Guests app did not return a usable user account.');
		}

		// Invalidate the per-request cache so subsequent isGuestUser() calls
		// for this UID return true rather than the cached "not yet known".
		unset($this->isGuestCache[$created->getUID()]);

		return [
			'userId' => $created->getUID(),
			'displayName' => $created->getDisplayName(),
			'email' => $created->getSystemEMailAddress() ?? $email,
			'isGuest' => true,
			'alreadyExisted' => false,
		];
	}

	/**
	 * Reset the per-request cache. Mainly useful in long-running processes or
	 * tests where the underlying user state may change mid-request.
	 */
	public function resetCache(): void {
		$this->isGuestCache = [];
		$this->guestsAppEnabled = null;
	}

	/**
	 * Resolve the GuestManager from the DI container without a hard import.
	 * Returns null if the class is unavailable or cannot be constructed.
	 */
	private function resolveGuestManager(): ?object {
		$class = '\\OCA\\Guests\\GuestManager';
		if (!class_exists($class)) {
			return null;
		}
		try {
			$instance = $this->container->get($class);
			return is_object($instance) && method_exists($instance, 'isGuest') ? $instance : null;
		} catch (\Throwable $e) {
			$this->logger->debug('Resolving GuestManager failed', [
				'app' => self::ATTENDANCE_APP_ID,
				'exception' => $e,
			]);
			return null;
		}
	}
}
