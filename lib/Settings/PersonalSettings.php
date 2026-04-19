<?php

declare(strict_types=1);

namespace OCA\Attendance\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings {

	public function getForm() {
		return new TemplateResponse('attendance', 'personal-settings', []);
	}

	public function getSection(): string {
		return 'attendance';
	}

	public function getPriority(): int {
		return 50;
	}
}
