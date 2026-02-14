<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCP\IConfig;

/**
 * Centralized configuration service for the Attendance app.
 * Handles all app-level settings including whitelisted groups and reminder configuration.
 */
class ConfigService {
	private const APP_ID = 'attendance';

	private IConfig $config;

	public function __construct(IConfig $config) {
		$this->config = $config;
	}

	/**
	 * Get whitelisted groups from app config.
	 * If no groups are configured, returns empty array (meaning all groups are allowed).
	 *
	 * @return array<string> List of group IDs
	 */
	public function getWhitelistedGroups(): array {
		$groupsJson = $this->config->getAppValue(self::APP_ID, 'whitelisted_groups', '[]');
		return json_decode($groupsJson, true) ?: [];
	}

	/**
	 * Set whitelisted groups in app config.
	 *
	 * @param array<string> $groups List of group IDs
	 */
	public function setWhitelistedGroups(array $groups): void {
		$this->config->setAppValue(self::APP_ID, 'whitelisted_groups', json_encode($groups));
	}

	/**
	 * Check if a group is allowed based on whitelist configuration.
	 * If no whitelist is configured, all groups are allowed.
	 *
	 * @param string $groupId The group ID to check
	 * @return bool True if the group is allowed
	 */
	public function isGroupAllowed(string $groupId): bool {
		$whitelistedGroups = $this->getWhitelistedGroups();

		// If no groups are whitelisted, allow all groups
		if (empty($whitelistedGroups)) {
			return true;
		}

		// Check if group is in whitelist (case-insensitive)
		return in_array(strtolower($groupId), array_map('strtolower', $whitelistedGroups));
	}

	/**
	 * Get whitelisted teams from app config.
	 * If no teams are configured, returns empty array.
	 *
	 * @return array<string> List of team/circle IDs
	 */
	public function getWhitelistedTeams(): array {
		$teamsJson = $this->config->getAppValue(self::APP_ID, 'whitelisted_teams', '[]');
		return json_decode($teamsJson, true) ?: [];
	}

	/**
	 * Set whitelisted teams in app config.
	 *
	 * @param array<string> $teams List of team/circle IDs
	 */
	public function setWhitelistedTeams(array $teams): void {
		$this->config->setAppValue(self::APP_ID, 'whitelisted_teams', json_encode($teams));
	}

	/**
	 * Check if reminders are enabled.
	 *
	 * @return bool True if reminders are enabled
	 */
	public function areRemindersEnabled(): bool {
		return $this->config->getAppValue(self::APP_ID, 'reminders_enabled', 'no') === 'yes';
	}

	/**
	 * Set reminders enabled status.
	 *
	 * @param bool $enabled Whether reminders should be enabled
	 */
	public function setRemindersEnabled(bool $enabled): void {
		$this->config->setAppValue(self::APP_ID, 'reminders_enabled', $enabled ? 'yes' : 'no');
	}

	/**
	 * Get the number of days before an appointment to send reminders.
	 *
	 * @return int Number of days (1-30)
	 */
	public function getReminderDays(): int {
		return (int)$this->config->getAppValue(self::APP_ID, 'reminder_days', '7');
	}

	/**
	 * Set the number of days before an appointment to send reminders.
	 *
	 * @param int $days Number of days (will be clamped to 1-30)
	 */
	public function setReminderDays(int $days): void {
		$days = max(1, min(30, $days));
		$this->config->setAppValue(self::APP_ID, 'reminder_days', (string)$days);
	}

	/**
	 * Get the reminder frequency in days.
	 * 0 means only remind once, 1-30 means repeat every N days.
	 *
	 * @return int Frequency in days
	 */
	public function getReminderFrequency(): int {
		return (int)$this->config->getAppValue(self::APP_ID, 'reminder_frequency', '0');
	}

	/**
	 * Set the reminder frequency in days.
	 *
	 * @param int $frequency Frequency in days (will be clamped to 0-30)
	 */
	public function setReminderFrequency(int $frequency): void {
		$frequency = max(0, min(30, $frequency));
		$this->config->setAppValue(self::APP_ID, 'reminder_frequency', (string)$frequency);
	}

	/**
	 * Get all reminder settings at once.
	 *
	 * @return array{enabled: bool, reminderDays: int, reminderFrequency: int}
	 */
	public function getReminderSettings(): array {
		return [
			'enabled' => $this->areRemindersEnabled(),
			'reminderDays' => $this->getReminderDays(),
			'reminderFrequency' => $this->getReminderFrequency(),
		];
	}

	/**
	 * Set all reminder settings at once.
	 *
	 * @param array{enabled?: bool, reminderDays?: int, reminderFrequency?: int} $settings
	 */
	public function setReminderSettings(array $settings): void {
		if (isset($settings['enabled'])) {
			$this->setRemindersEnabled($settings['enabled']);
		}
		if (isset($settings['reminderDays'])) {
			$this->setReminderDays($settings['reminderDays']);
		}
		if (isset($settings['reminderFrequency'])) {
			$this->setReminderFrequency($settings['reminderFrequency']);
		}
	}

	/**
	 * Get a permission setting (list of group IDs that have the permission).
	 *
	 * @param string $permission The permission name
	 * @return array<string> List of group IDs
	 */
	public function getPermissionRoles(string $permission): array {
		$configKey = 'permission_' . $permission;
		$rolesJson = $this->config->getAppValue(self::APP_ID, $configKey, '[]');
		return json_decode($rolesJson, true) ?: [];
	}

	/**
	 * Set a permission setting.
	 *
	 * @param string $permission The permission name
	 * @param array<string> $roles List of group IDs
	 */
	public function setPermissionRoles(string $permission, array $roles): void {
		$configKey = 'permission_' . $permission;
		$this->config->setAppValue(self::APP_ID, $configKey, json_encode($roles));
	}

	/**
	 * Check if calendar sync is enabled.
	 * When enabled, changes to linked calendar events will automatically
	 * update the corresponding attendance appointments.
	 *
	 * @return bool True if calendar sync is enabled
	 */
	public function isCalendarSyncEnabled(): bool {
		return $this->config->getAppValue(self::APP_ID, 'calendar_sync_enabled', 'no') === 'yes';
	}

	/**
	 * Set calendar sync enabled status.
	 *
	 * @param bool $enabled Whether calendar sync should be enabled
	 */
	public function setCalendarSyncEnabled(bool $enabled): void {
		$this->config->setAppValue(self::APP_ID, 'calendar_sync_enabled', $enabled ? 'yes' : 'no');
	}

	/**
	 * Get the display order for appointments.
	 *
	 * @return string 'name_first' or 'date_first'
	 */
	public function getDisplayOrder(): string {
		return $this->config->getAppValue(self::APP_ID, 'display_order', 'name_first');
	}

	/**
	 * Set the display order for appointments.
	 *
	 * @param string $order 'name_first' or 'date_first'
	 */
	public function setDisplayOrder(string $order): void {
		if (!in_array($order, ['name_first', 'date_first'], true)) {
			$order = 'name_first';
		}
		$this->config->setAppValue(self::APP_ID, 'display_order', $order);
	}
}
