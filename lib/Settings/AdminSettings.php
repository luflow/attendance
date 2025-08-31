<?php

declare(strict_types=1);

namespace OCA\Attendance\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
	private IConfig $config;
	private IGroupManager $groupManager;

	public function __construct(IConfig $config, IGroupManager $groupManager) {
		$this->config = $config;
		$this->groupManager = $groupManager;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm() {
		// No need to pass data to template anymore - Vue will fetch via API
		return new TemplateResponse('attendance', 'admin-settings', []);
	}

	/**
	 * @return string the section ID
	 */
	public function getSection(): string {
		return 'attendance';
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of
	 * the admin section. The forms are arranged in ascending order of the
	 * priority values. It is required to return a value between 0 and 100.
	 */
	public function getPriority(): int {
		return 50;
	}

	/**
	 * Get whitelisted groups from app config
	 * @return array
	 */
	public function getWhitelistedGroups(): array {
		$groupsJson = $this->config->getAppValue('attendance', 'whitelisted_groups', '[]');
		return json_decode($groupsJson, true) ?: [];
	}

	/**
	 * Set whitelisted groups in app config
	 * @param array $groups
	 */
	public function setWhitelistedGroups(array $groups): void {
		$this->config->setAppValue('attendance', 'whitelisted_groups', json_encode($groups));
	}
}
