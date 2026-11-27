<?php

declare(strict_types=1);

namespace OCA\Attendance\Dashboard;

use OCA\Attendance\Activity\EventMessages;
use OCA\Attendance\AppInfo\Application;
use OCA\Attendance\ResponseDefinitions;
use OCA\Attendance\Service\AppointmentService;
use OCA\Attendance\Service\ConfigService;
use OCP\AppFramework\Services\IInitialState;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * @psalm-import-type AttendanceAppointmentData from ResponseDefinitions
 */
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

	public function __construct(
		IL10N $l10n,
		AppointmentService $appointmentService,
		ConfigService $configService,
		IInitialState $initialStateService,
		private IURLGenerator $urlGenerator,
		private EventMessages $eventMessages,
		?string $userId,
	) {
		$this->l10n = $l10n;
		$this->appointmentService = $appointmentService;
		$this->configService = $configService;
		$this->initialStateService = $initialStateService;
		$this->userId = $userId;
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getId(): string {
		return 'attendance-vue-widget';
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getTitle(): string {
		return $this->l10n->t('My appointments');
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getOrder(): int {
		return 10;
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getIconClass(): string {
		return 'icon-attendance';
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getUrl(): ?string {
		return null;
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function load(): void {
		if ($this->userId !== null) {
			$items = $this->appointmentService->getUpcomingAppointmentsForWidget($this->userId, 10);
			$this->initialStateService->provideInitialState('dashboard-widget-items', $items);
		}

		$this->initialStateService->provideInitialState('display-order', $this->configService->getDisplayOrder());

		Util::addScript(Application::APP_ID, Application::APP_ID . '-dashboard');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-dashboard');
	}

	/**
	 * @return list<WidgetItem>
	 */
	#[\Override]
	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		$iconUrl = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'));
		/** @var list<AttendanceAppointmentData> $appointments built by AppointmentService::serializeAppointment() */
		$appointments = $this->appointmentService->getUpcomingAppointmentsForWidget($userId, $limit);
		$items = [];
		foreach ($appointments as $appointment) {
			$id = (string)$appointment['id'];
			$items[] = new WidgetItem(
				$appointment['name'],
				$this->eventMessages->formatDateForUser($appointment['startDatetime'], $userId, $appointment['isAllDay']),
				$this->urlGenerator->linkToRouteAbsolute('attendance.page.appointment', ['id' => $id]),
				$iconUrl,
				$id,
			);
		}
		return $items;
	}
}
