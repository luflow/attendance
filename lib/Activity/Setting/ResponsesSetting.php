<?php

declare(strict_types=1);

namespace OCA\Attendance\Activity\Setting;

/**
 * Responses and comments in the activity stream of managers and organizers
 * (issue #307). A record to look things up in, so neither push nor mail by
 * default — the opt-in response notification already covers push.
 */
final class ResponsesSetting extends AttendanceActivitySetting {
	/** Also the activity type, published by ResponseChangeNotificationListener. */
	public const IDENTIFIER = 'responses';

	#[\Override]
	public function getIdentifier(): string {
		return self::IDENTIFIER;
	}

	#[\Override]
	public function getName(): string {
		// TRANSLATORS: Activity setting for organizers and managers: someone answered an appointment, changed or withdrew their answer, or changed the comment they left with it.
		return $this->l->t('Someone answered an appointment or changed their response');
	}

	#[\Override]
	public function isDefaultEnabledNotification(): bool {
		return false;
	}
}
