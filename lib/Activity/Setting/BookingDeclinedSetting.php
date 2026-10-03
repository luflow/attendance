<?php

declare(strict_types=1);

namespace OCA\Attendance\Activity\Setting;

final class BookingDeclinedSetting extends AttendanceActivitySetting {
	#[\Override]
	public function getIdentifier(): string {
		return 'booking_declined';
	}

	#[\Override]
	public function getName(): string {
		return $this->l->t('You did not get a place in an appointment');
	}
}
