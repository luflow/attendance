<?php

declare(strict_types=1);

namespace OCA\Attendance\AppInfo;

use OCA\Attendance\BackgroundJob\ReminderJob;
use OCA\Attendance\Dashboard\StreakLeadersWidget;
use OCA\Attendance\Dashboard\Widget;
use OCA\Attendance\Listener\CalendarObjectUpdateListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'attendance';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(Widget::class);
		$context->registerDashboardWidget(StreakLeadersWidget::class);
		$context->registerNotifierService(\OCA\Attendance\Notification\Notifier::class);

		// Register calendar event listeners (NC 32+ only)
		// These event classes only exist in Nextcloud 32 and later
		$this->registerCalendarListeners($context);
	}

	public function boot(IBootContext $context): void {
		$container = $context->getAppContainer();

		// Register background job for reminders
		$jobList = $container->get(\OCP\BackgroundJob\IJobList::class);
		if (!$jobList->has(ReminderJob::class, null)) {
			$jobList->add(ReminderJob::class);
		}
	}

	/**
	 * Register calendar event listeners for automatic sync.
	 * These are only available in Nextcloud 32+.
	 */
	private function registerCalendarListeners(IRegistrationContext $context): void {
		// Check if calendar events exist (NC 32+)
		if (class_exists(\OCP\Calendar\Events\CalendarObjectUpdatedEvent::class)) {
			$context->registerEventListener(
				\OCP\Calendar\Events\CalendarObjectUpdatedEvent::class,
				CalendarObjectUpdateListener::class
			);
		}

		if (class_exists(\OCP\Calendar\Events\CalendarObjectDeletedEvent::class)) {
			$context->registerEventListener(
				\OCP\Calendar\Events\CalendarObjectDeletedEvent::class,
				CalendarObjectUpdateListener::class
			);
		}
	}
}
