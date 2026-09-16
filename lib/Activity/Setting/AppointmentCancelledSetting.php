<?php

declare(strict_types=1);

namespace OCA\Attendance\Activity\Setting;

final class AppointmentCancelledSetting extends AttendanceActivitySetting {
	#[\Override]
	public function getIdentifier(): string {
		return 'appointment_cancelled';
	}

	#[\Override]
	public function getName(): string {
		return $this->l->t('An appointment was cancelled');
	}
}
