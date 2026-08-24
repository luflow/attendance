<?php

declare(strict_types=1);

namespace OCA\Attendance\AppInfo;

use OCA\Attendance\Audit\AuditEventDispatcher;
use OCA\Attendance\BackgroundJob\AutoCloseJob;
use OCA\Attendance\BackgroundJob\ReminderJob;
use OCA\Attendance\BackgroundJob\TalkRoomSyncJob;
use OCA\Attendance\Dashboard\Widget;
use OCA\Attendance\Listener\CalendarObjectUpdateListener;
use OCA\Attendance\Listener\ResponseChangeNotificationListener;
use OCA\Attendance\Listener\TalkRoomDeletedListener;
use OCA\Attendance\Listener\UserDeletedListener;
use OCA\Talk\Events\RoomDeletedEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'attendance';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(Widget::class);
		$context->registerNotifierService(\OCA\Attendance\Notification\Notifier::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		// Talk may not be installed; registering by class name is safe since it
		// never autoloads the class, only Talk actually dispatching it would.
		$context->registerEventListener(RoomDeletedEvent::class, TalkRoomDeletedListener::class);

		$this->registerCalendarListeners($context);
	}

	public function boot(IBootContext $context): void {
		$container = $context->getAppContainer();

		// Wire the audit-event dispatcher to its listeners. Done at boot so
		// container-resolved services are available.
		$dispatcher = $container->get(AuditEventDispatcher::class);
		$listener = $container->get(ResponseChangeNotificationListener::class);
		$dispatcher->register([$listener, 'handle']);

		// Register background job for reminders
		/** @var \OCP\BackgroundJob\IJobList $jobList */
		$jobList = $container->get(\OCP\BackgroundJob\IJobList::class);
		if (!$jobList->has(ReminderJob::class, null)) {
			$jobList->add(ReminderJob::class);
		}
		if (!$jobList->has(AutoCloseJob::class, null)) {
			$jobList->add(AutoCloseJob::class);
		}
		if (!$jobList->has(TalkRoomSyncJob::class, null)) {
			$jobList->add(TalkRoomSyncJob::class);
		}
	}

	/**
	 * Register calendar event listeners for automatic sync.
	 */
	private function registerCalendarListeners(IRegistrationContext $context): void {
		$context->registerEventListener(
			\OCP\Calendar\Events\CalendarObjectUpdatedEvent::class,
			CalendarObjectUpdateListener::class
		);
		$context->registerEventListener(
			\OCP\Calendar\Events\CalendarObjectDeletedEvent::class,
			CalendarObjectUpdateListener::class
		);
		// Also handle "moved to trash" (default behavior when deleting calendar events)
		$context->registerEventListener(
			\OCP\Calendar\Events\CalendarObjectMovedToTrashEvent::class,
			CalendarObjectUpdateListener::class
		);
	}
}
