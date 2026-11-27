<?php

declare(strict_types=1);

namespace OCA\Attendance\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings {

	#[\Override]
	public function getForm() {
		return new TemplateResponse('attendance', 'personal-settings', []);
	}

	#[\Override]
	public function getSection(): string {
		return 'attendance';
	}

	#[\Override]
	public function getPriority(): int {
		return 50;
	}
}
