<?php

declare(strict_types=1);

namespace OCA\Attendance\AppInfo;

use OCA\Attendance\BackgroundJob\ReminderJob;
use OCA\Attendance\Dashboard\Widget;
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
		$context->registerNotifierService(\OCA\Attendance\Notification\Notifier::class);
	}

	public function boot(IBootContext $context): void {
		$container = $context->getAppContainer();
		
		// Register background job for reminders
		$jobList = $container->get(\OCP\BackgroundJob\IJobList::class);
		if (!$jobList->has(ReminderJob::class, null)) {
			$jobList->add(ReminderJob::class);
		}
	}
}
