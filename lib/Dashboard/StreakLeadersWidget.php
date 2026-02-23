<?php

declare(strict_types=1);

namespace OCA\Attendance\Dashboard;

use OCA\Attendance\AppInfo\Application;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\StreakService;
use OCP\AppFramework\Services\IInitialState;
use OCP\Dashboard\IAPIWidget;
use OCP\IL10N;
use OCP\Util;

class StreakLeadersWidget implements IAPIWidget {
	private IL10N $l10n;
	private StreakService $streakService;
	private ConfigService $configService;
	private IInitialState $initialStateService;
	private ?string $userId;

	public function __construct(
		IL10N $l10n,
		StreakService $streakService,
		ConfigService $configService,
		IInitialState $initialStateService,
		?string $userId,
	) {
		$this->l10n = $l10n;
		$this->streakService = $streakService;
		$this->configService = $configService;
		$this->initialStateService = $initialStateService;
		$this->userId = $userId;
	}

	public function getId(): string {
		return 'attendance-streak-leaders';
	}

	public function getTitle(): string {
		return $this->l10n->t('Streak leaders');
	}

	public function getOrder(): int {
		return 11;
	}

	public function getIconClass(): string {
		return 'icon-category-organization-dark';
	}

	public function getUrl(): ?string {
		return null;
	}

	public function load(): void {
		$items = $this->configService->isStreaksEnabled()
			? $this->streakService->getTopStreaks(10)
			: [];
		$this->initialStateService->provideInitialState('streak-leaders-items', $items);

		Util::addScript(Application::APP_ID, Application::APP_ID . '-streakleaders');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-streakleaders');
	}

	public function getItems(string $userId, ?string $since = null, int $limit = 10): array {
		if (!$this->configService->isStreaksEnabled()) {
			return [];
		}
		return $this->streakService->getTopStreaks($limit);
	}
}
