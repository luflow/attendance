<?php

declare(strict_types=1);

namespace OCA\Attendance\Activity\Setting;

final class BookingConfirmedSetting extends AttendanceActivitySetting {
	#[\Override]
	public function getIdentifier(): string {
		return 'booking_confirmed';
	}

	#[\Override]
	public function getName(): string {
		return $this->l->t('You got a place in an appointment');
	}
}
