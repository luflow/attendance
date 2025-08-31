<?php

declare(strict_types=1);

namespace OCA\Attendance\AppInfo;

use OCA\Attendance\Dashboard\Widget;
use OCA\Attendance\Settings\AdminSection;
use OCA\Attendance\Settings\AdminSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'attendance';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Routes are automatically loaded from appinfo/routes.php
		$context->registerDashboardWidget(Widget::class);
		
		// Register admin settings
		$context->registerSettingsSection('admin', AdminSection::class);
		$context->registerSettings('admin', AdminSettings::class);
	}

	public function boot(IBootContext $context): void {
	}
}
