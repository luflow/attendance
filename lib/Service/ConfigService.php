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
	public const DEFAULT_PUSH_PROXY_SERVER = 'https://push.anwesenheit.app';
	public const VALID_REMINDER_TARGETS = ['non_responders', 'maybe', 'both'];

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
	 * Get the reminder target: who should receive reminders.
	 *
	 * @return string One of 'non_responders', 'maybe', 'both'
	 */
	public function getReminderTarget(): string {
		$target = $this->config->getAppValue(self::APP_ID, 'reminder_target', 'non_responders');
		if (!in_array($target, self::VALID_REMINDER_TARGETS, true)) {
			return 'non_responders';
		}
		return $target;
	}

	/**
	 * Set the reminder target.
	 *
	 * @param string $target One of 'non_responders', 'maybe', 'both'
	 */
	public function setReminderTarget(string $target): void {
		if (!in_array($target, self::VALID_REMINDER_TARGETS, true)) {
			$target = 'non_responders';
		}
		$this->config->setAppValue(self::APP_ID, 'reminder_target', $target);
	}

	/**
	 * Get all reminder settings at once.
	 *
	 * @return array{enabled: bool, reminderDays: int, reminderFrequency: int, reminderTarget: string}
	 */
	public function getReminderSettings(): array {
		return [
			'enabled' => $this->areRemindersEnabled(),
			'reminderDays' => $this->getReminderDays(),
			'reminderFrequency' => $this->getReminderFrequency(),
			'reminderTarget' => $this->getReminderTarget(),
		];
	}

	/**
	 * Set all reminder settings at once.
	 *
	 * @param array{enabled?: bool, reminderDays?: int, reminderFrequency?: int, reminderTarget?: string} $settings
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
		if (isset($settings['reminderTarget'])) {
			$this->setReminderTarget($settings['reminderTarget']);
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
	 * Check if push notifications are enabled.
	 *
	 * @return bool True if push notifications are enabled
	 */
	public function isPushEnabled(): bool {
		return $this->config->getAppValue(self::APP_ID, 'push_enabled', 'yes') === 'yes';
	}

	/**
	 * Set push notifications enabled status.
	 *
	 * @param bool $enabled Whether push notifications should be enabled
	 */
	public function setPushEnabled(bool $enabled): void {
		$this->config->setAppValue(self::APP_ID, 'push_enabled', $enabled ? 'yes' : 'no');
	}

	/**
	 * Check if the booking / planning feature is enabled instance-wide. When
	 * off, no booking UI is shown anywhere (the bookingEnabled capability is
	 * false). Defaults to off so plain yes/no users never see it.
	 *
	 * @return bool True if booking is enabled
	 */
	public function isBookingEnabled(): bool {
		return $this->config->getAppValue(self::APP_ID, 'booking_enabled', 'no') === 'yes';
	}

	/**
	 * Set the booking / planning feature enabled status.
	 *
	 * @param bool $enabled Whether booking should be enabled
	 */
	public function setBookingEnabled(bool $enabled): void {
		$this->config->setAppValue(self::APP_ID, 'booking_enabled', $enabled ? 'yes' : 'no');
	}

	/**
	 * Minutes before an appointment starts that self-check-in opens.
	 * The window always closes at the appointment end.
	 */
	public function getSelfCheckinWindowMinutes(): int {
		$value = (int)$this->config->getAppValue(self::APP_ID, 'self_checkin_window_minutes', '30');
		return max(0, min(1440, $value));
	}

	public function setSelfCheckinWindowMinutes(int $minutes): void {
		$minutes = max(0, min(1440, $minutes));
		$this->config->setAppValue(self::APP_ID, 'self_checkin_window_minutes', (string)$minutes);
	}

	/**
	 * Get the push proxy server URL.
	 * Configurable via occ: occ config:app:set attendance push_proxy_server --value="https://your-proxy.example.com"
	 *
	 * @return string The push proxy server URL
	 */
	public function getPushProxyServer(): string {
		return $this->config->getAppValue(self::APP_ID, 'push_proxy_server', self::DEFAULT_PUSH_PROXY_SERVER);
	}

	/**
	 * Set the push proxy server URL.
	 *
	 * @param string $url The push proxy server URL
	 */
	public function setPushProxyServer(string $url): void {
		$this->config->setAppValue(self::APP_ID, 'push_proxy_server', $url);
	}

	/**
	 * Check if the mobile app promotion banner is enabled.
	 *
	 * @return bool True if the banner should be shown to users
	 */
	public function isMobileAppBannerEnabled(): bool {
		return $this->config->getAppValue(self::APP_ID, 'mobile_app_banner_enabled', 'yes') === 'yes';
	}

	/**
	 * Set the mobile app promotion banner enabled status.
	 *
	 * @param bool $enabled Whether the banner should be shown to users
	 */
	public function setMobileAppBannerEnabled(bool $enabled): void {
		$this->config->setAppValue(self::APP_ID, 'mobile_app_banner_enabled', $enabled ? 'yes' : 'no');
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

	public const AUDIT_LOG_VISIBILITY_MANAGERS = 'managers';
	public const AUDIT_LOG_VISIBILITY_ALL_WITH_OVERVIEW = 'all_with_response_overview';
	public const VALID_AUDIT_LOG_VISIBILITIES = [
		self::AUDIT_LOG_VISIBILITY_MANAGERS,
		self::AUDIT_LOG_VISIBILITY_ALL_WITH_OVERVIEW,
	];

	/**
	 * Master kill switch for the audit log + response-change notifications.
	 * When disabled, no events are recorded and no pushes fire.
	 */
	public function isAuditLogEnabled(): bool {
		return $this->config->getAppValue(self::APP_ID, 'audit_log_enabled', 'yes') === 'yes';
	}

	public function setAuditLogEnabled(bool $enabled): void {
		$this->config->setAppValue(self::APP_ID, 'audit_log_enabled', $enabled ? 'yes' : 'no');
	}

	/**
	 * Visibility scope for the audit-log read endpoint. 'managers' restricts
	 * to manage_appointments users; 'all_with_response_overview' opens it up
	 * to anyone who can already see the response summary.
	 */
	public function getAuditLogVisibility(): string {
		$value = $this->config->getAppValue(self::APP_ID, 'audit_log_visibility', self::AUDIT_LOG_VISIBILITY_MANAGERS);
		if (!in_array($value, self::VALID_AUDIT_LOG_VISIBILITIES, true)) {
			return self::AUDIT_LOG_VISIBILITY_MANAGERS;
		}
		return $value;
	}

	public function setAuditLogVisibility(string $visibility): void {
		if (!in_array($visibility, self::VALID_AUDIT_LOG_VISIBILITIES, true)) {
			$visibility = self::AUDIT_LOG_VISIBILITY_MANAGERS;
		}
		$this->config->setAppValue(self::APP_ID, 'audit_log_visibility', $visibility);
	}

	// --- User-level settings ---

	private const ALLOWED_ICAL_TRIGGERS = ['PT15M', 'PT30M', 'PT1H', 'PT2H', 'P1D', 'P2D'];

	/**
	 * Get the user's iCal reminder trigger durations.
	 *
	 * @param string $userId The user ID
	 * @return list<string> List of duration strings (e.g. ['P1D', 'PT1H'])
	 */
	public function getUserIcalReminderTriggers(string $userId): array {
		$json = $this->config->getUserValue($userId, self::APP_ID, 'ical_reminder_triggers', '["PT1H"]');
		$values = json_decode($json, true);
		if (!is_array($values)) {
			return ['PT1H'];
		}
		return array_values(array_filter($values, function (string $v): bool {
			return in_array($v, self::ALLOWED_ICAL_TRIGGERS, true);
		}));
	}

	/**
	 * Set the user's iCal reminder trigger durations.
	 *
	 * @param string $userId The user ID
	 * @param list<string> $values List of duration strings (e.g. ['P1D', 'PT1H'])
	 */
	public function setUserIcalReminderTriggers(string $userId, array $values): void {
		$filtered = array_values(array_unique(array_filter($values, function (string $v): bool {
			return in_array($v, self::ALLOWED_ICAL_TRIGGERS, true);
		})));
		$this->config->setUserValue($userId, self::APP_ID, 'ical_reminder_triggers', json_encode($filtered));
	}

	/**
	 * Per-user opt-in for response-change push notifications. Default is off
	 * so users only receive these notifications after explicitly enabling them.
	 */
	public function wantsResponseChangeNotifications(string $userId): bool {
		return $this->config->getUserValue($userId, self::APP_ID, 'notify_response_changes', 'no') === 'yes';
	}

	public function setWantsResponseChangeNotifications(string $userId, bool $enabled): void {
		$this->config->setUserValue($userId, self::APP_ID, 'notify_response_changes', $enabled ? 'yes' : 'no');
	}
}
