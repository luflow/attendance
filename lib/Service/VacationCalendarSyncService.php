<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Vacation;
use OCA\Attendance\Db\VacationMapper;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory as IL10NFactory;
use Psr\Log\LoggerInterface;

/**
 * Writes every member's vacation periods as whole-day events into a single
 * admin-configured calendar, so everybody that calendar is shared with sees who
 * is away. App → calendar only: the admin shares the calendar read-only, and
 * anything edited in the Calendar app is overwritten by the next write.
 *
 * Same plumbing as the organization calendar (CalDavBackend because OCP has no
 * update/delete API for calendar objects), and the same rule: a broken calendar
 * must never break recording a vacation, so every entry point swallows failures
 * with a warning.
 *
 * @psalm-suppress MixedMethodCall The CalDAV backend is intentionally untyped
 * so the app keeps loading without the dav app.
 */
class VacationCalendarSyncService {
	private const UID_PREFIX = 'attendance-vacation-';

	/** @var array{0: int, 1: string}|false|null Memoized target; false = resolution failed */
	private array|false|null $resolvedTarget = null;
	private ?object $calDavBackend = null;
	private bool $backendResolved = false;
	private ?string $uidDomain = null;
	private ?IL10N $l10n = null;

	public function __construct(
		private CalendarService $calendarService,
		private VacationMapper $vacationMapper,
		private ConfigService $configService,
		private IUserManager $userManager,
		private IURLGenerator $urlGenerator,
		private IL10NFactory $l10nFactory,
		private IConfig $config,
		private IcalService $icalService,
		private LoggerInterface $logger,
	) {
	}

	public function isEnabled(): bool {
		return $this->configService->isVacationCalendarEnabled()
			&& $this->configService->getVacationCalendarUri() !== ''
			&& $this->configService->getVacationCalendarUserId() !== '';
	}

	/**
	 * Apply an admin settings change and backfill when the feature was enabled
	 * or re-pointed.
	 *
	 * @param array{enabled?: bool, calendarUri?: string} $settings
	 * @param string $actingUserId The admin performing the change
	 */
	public function applySettings(array $settings, string $actingUserId): void {
		$changed = false;

		if (isset($settings['enabled'])) {
			$changed = $this->configService->isVacationCalendarEnabled() !== $settings['enabled'];
			$this->configService->setVacationCalendarEnabled($settings['enabled']);
		}

		if (isset($settings['calendarUri']) && $settings['calendarUri'] !== '') {
			$oldUri = $this->configService->getVacationCalendarUri();
			$this->configService->setVacationCalendarUri($settings['calendarUri']);
			if ($oldUri !== $settings['calendarUri'] || $this->configService->getVacationCalendarUserId() === '') {
				$this->configService->setVacationCalendarUserId($actingUserId);
				$changed = true;
			}
		}

		if ($changed) {
			$this->resolvedTarget = null;
			$this->syncAll();
		}
	}

	/**
	 * Create or update the event of one vacation.
	 */
	public function syncVacation(Vacation $vacation): bool {
		try {
			if (!$this->isEnabled()) {
				return false;
			}
			$resolved = $this->resolveTargetCalendar();
			$backend = $this->getCalDavBackend();
			if ($resolved === null || $backend === null) {
				return false;
			}
			[$calendarId] = $resolved;

			$objectUri = $this->buildEventUid($vacation->getId()) . '.ics';
			$ics = $this->buildIcs($vacation);
			if ($backend->getCalendarObject($calendarId, $objectUri) !== null) {
				$backend->updateCalendarObject($calendarId, $objectUri, $ics);
			} else {
				$backend->createCalendarObject($calendarId, $objectUri, $ics);
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Attendance: failed to sync vacation {id} to the vacation calendar: {message}', [
				'id' => $vacation->getId(),
				'message' => $e->getMessage(),
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * Remove the event of a deleted vacation (to the calendar trash bin).
	 */
	public function removeVacation(int $vacationId): void {
		try {
			if (!$this->isEnabled()) {
				return;
			}
			$resolved = $this->resolveTargetCalendar();
			$backend = $this->getCalDavBackend();
			if ($resolved === null || $backend === null) {
				return;
			}
			[$calendarId] = $resolved;

			$objectUri = $this->buildEventUid($vacationId) . '.ics';
			if ($backend->getCalendarObject($calendarId, $objectUri) !== null) {
				$backend->deleteCalendarObject($calendarId, $objectUri);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Attendance: failed to remove the vacation calendar event of vacation {id}: {message}', [
				'id' => $vacationId,
				'message' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * Bring the calendar in line with the vacation table: every vacation that
	 * has not ended yet gets its event, and events of vacations that are gone
	 * (deleted users, rows removed behind our back) are dropped.
	 *
	 * @return int Number of vacations in the calendar afterwards
	 */
	public function syncAll(): int {
		$count = 0;
		try {
			if (!$this->isEnabled()) {
				return 0;
			}
			$wanted = [];
			foreach ($this->vacationMapper->findOverlapping(gmdate('Y-m-d'), '9999-12-31') as $vacation) {
				if ($this->userManager->userExists($vacation->getUserId()) && $this->syncVacation($vacation)) {
					$wanted[$this->buildEventUid($vacation->getId()) . '.ics'] = true;
					$count++;
				}
			}
			$this->removeStaleEvents($wanted);
		} catch (\Throwable $e) {
			$this->logger->warning('Attendance: vacation calendar backfill failed: {message}', [
				'message' => $e->getMessage(),
				'exception' => $e,
			]);
		}
		return $count;
	}

	/**
	 * @param array<string, true> $keep Object URIs that stay
	 */
	private function removeStaleEvents(array $keep): void {
		$resolved = $this->resolveTargetCalendar();
		$backend = $this->getCalDavBackend();
		if ($resolved === null || $backend === null) {
			return;
		}
		[$calendarId] = $resolved;
		/** @var list<array{uri?: string}> $objects */
		$objects = $backend->getCalendarObjects($calendarId);
		foreach ($objects as $object) {
			$uri = $object['uri'] ?? '';
			if (str_starts_with($uri, self::UID_PREFIX) && !isset($keep[$uri])) {
				$backend->deleteCalendarObject($calendarId, $uri);
			}
		}
	}

	public function buildEventUid(int $vacationId): string {
		if ($this->uidDomain === null) {
			$host = parse_url($this->urlGenerator->getAbsoluteURL('/'), PHP_URL_HOST);
			$this->uidDomain = is_string($host) ? $host : 'nextcloud';
		}
		return self::UID_PREFIX . $vacationId . '@' . $this->uidDomain;
	}

	/**
	 * A whole-day, transparent event: "Name – Vacation", the note as description.
	 * The end of a whole-day event is exclusive, so it is the day after the last.
	 */
	private function buildIcs(Vacation $vacation): string {
		$user = $this->userManager->get($vacation->getUserId());
		$name = $user !== null ? $user->getDisplayName() : $vacation->getUserId();
		$summary = $this->getL10N()->t('%1$s – Vacation', [$name]);

		$first = new \DateTimeImmutable($vacation->getStartDate(), new \DateTimeZone('UTC'));
		$endExclusive = (new \DateTimeImmutable($vacation->getEndDate(), new \DateTimeZone('UTC')))->modify('+1 day');
		$stamp = gmdate('Ymd\THis\Z');

		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Nextcloud//Attendance App//EN',
			'CALSCALE:GREGORIAN',
			'BEGIN:VEVENT',
			'UID:' . $this->buildEventUid($vacation->getId()),
			'DTSTAMP:' . $stamp,
			'DTSTART;VALUE=DATE:' . $first->format('Ymd'),
			'DTEND;VALUE=DATE:' . $endExclusive->format('Ymd'),
			'SUMMARY:' . IcalService::escapeIcalText($summary),
			'TRANSP:TRANSPARENT',
			'STATUS:CONFIRMED',
		];
		$note = $vacation->getNote();
		if ($note !== null && $note !== '') {
			$lines[] = 'DESCRIPTION:' . IcalService::escapeIcalText($note);
		}
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return $this->icalService->foldIcalContent(implode("\r\n", $lines) . "\r\n");
	}

	/**
	 * Instance default language — the calendar is shared org-wide, so its text
	 * must not depend on whoever triggered the write.
	 */
	private function getL10N(): IL10N {
		if ($this->l10n === null) {
			$lang = $this->config->getSystemValueString('default_language', 'en');
			$this->l10n = $this->l10nFactory->get('attendance', $lang);
		}
		return $this->l10n;
	}

	/**
	 * @return array{0: int, 1: string}|null
	 */
	private function resolveTargetCalendar(): ?array {
		if ($this->resolvedTarget !== null) {
			return $this->resolvedTarget ?: null;
		}

		$this->resolvedTarget = false;
		$userId = $this->configService->getVacationCalendarUserId();
		$uri = $this->configService->getVacationCalendarUri();

		$calendar = $this->calendarService->findWritableCalendar($userId, $uri);
		if ($calendar === null) {
			$this->logger->warning('Attendance: configured vacation calendar {uri} not found or not writable for user {userId}', [
				'uri' => $uri,
				'userId' => $userId,
			]);
			return null;
		}

		$calendarId = (int)$calendar->getKey();
		$backend = $this->getCalDavBackend();
		if ($backend === null) {
			return null;
		}
		/** @var array{uri?: string}|null $row */
		$row = $backend->getCalendarById($calendarId);
		if ($row === null) {
			return null;
		}

		$this->resolvedTarget = [$calendarId, $row['uri'] ?? $uri];
		return $this->resolvedTarget;
	}

	protected function getCalDavBackend(): ?object {
		if ($this->backendResolved) {
			return $this->calDavBackend;
		}
		$this->backendResolved = true;
		try {
			/** @var object $backend */
			$backend = \OCP\Server::get('OCA\DAV\CalDAV\CalDavBackend');
			$this->calDavBackend = $backend;
		} catch (\Throwable $e) {
			$this->logger->warning('Attendance: CalDAV backend unavailable, vacation calendar sync skipped: {message}', [
				'message' => $e->getMessage(),
			]);
			$this->calDavBackend = null;
		}
		return $this->calDavBackend;
	}
}
