<?php

declare(strict_types=1);

namespace OCA\Attendance\Activity\Setting;

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

/**
 * Shared shape for the 5 Attendance notification types that route through
 * OCP\Activity\IManager (see NotificationService::sendViaActivityOrDirect) —
 * one concrete class per type is a framework requirement of
 * IManager::registerSetting(), but the group and defaults are identical.
 */
abstract class AttendanceActivitySetting extends ActivitySettings {
	public function __construct(
		protected IL10N $l,
	) {
	}

	#[\Override]
	public function getGroupIdentifier(): string {
		return 'attendance';
	}

	#[\Override]
	public function getGroupName(): string {
		return $this->l->t('Attendance');
	}

	#[\Override]
	public function getPriority(): int {
		return 50;
	}

	/**
	 * Push is how these were delivered before this setting existed —
	 * default it to stay on so nobody silently stops getting them.
	 */
	#[\Override]
	public function isDefaultEnabledNotification(): bool {
		return true;
	}
}
