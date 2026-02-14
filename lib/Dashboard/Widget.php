<?php

declare(strict_types=1);

namespace OCA\Attendance\Dashboard;

use OCA\Attendance\AppInfo\Application;
use OCA\Attendance\Service\AppointmentService;
use OCA\Attendance\Service\ConfigService;
use OCP\AppFramework\Services\IInitialState;
use OCP\Dashboard\IAPIWidget;
use OCP\IL10N;
use OCP\Util;

class Widget implements IAPIWidget {

	/** @var IL10N */
	private $l10n;
	/**
	 * @var AppointmentService
	 */
	private $appointmentService;
	/**
	 * @var ConfigService
	 */
	private $configService;
	/**
	 * @var IInitialState
	 */
	private $initialStateService;
	/**
	 * @var string|null
	 */
	private $userId;

	public function __construct(IL10N $l10n,
		AppointmentService $appointmentService,
		ConfigService $configService,
		IInitialState $initialStateService,
		?string $userId) {
		$this->l10n = $l10n;
		$this->appointmentService = $appointmentService;
		$this->configService = $configService;
		$this->initialStateService = $initialStateService;
		$this->userId = $userId;
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string {
		return 'attendance-vue-widget';
	}

	/**
	 * @inheritDoc
	 */
	public function getTitle(): string {
		return $this->l10n->t('Attendance');
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(): int {
		return 10;
	}

	/**
	 * @inheritDoc
	 */
	public function getIconClass(): string {
		return 'icon-category-organization-dark';
	}

	/**
	 * @inheritDoc
	 */
	public function getUrl(): ?string {
		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function load(): void {
		if ($this->userId !== null) {
			$items = $this->getItems($this->userId);
			$this->initialStateService->provideInitialState('dashboard-widget-items', $items);
		}

		// Get Nextcloud major version for CSS compatibility
		$ncVersion = Util::getVersion();
		$ncMajorVersion = $ncVersion[0];
		$this->initialStateService->provideInitialState('nc-version', $ncMajorVersion);

		$this->initialStateService->provideInitialState('display-order', $this->configService->getDisplayOrder());

		Util::addScript(Application::APP_ID, Application::APP_ID . '-dashboard');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-dashboard');
	}

	/**
	 * @inheritDoc
	 */
	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		return $this->appointmentService->getUpcomingAppointmentsForWidget($userId, 10);
	}
}
