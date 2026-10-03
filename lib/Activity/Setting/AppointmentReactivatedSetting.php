<?php

declare(strict_types=1);

namespace OCA\Attendance\Activity\Setting;

final class AppointmentReactivatedSetting extends AttendanceActivitySetting {
	#[\Override]
	public function getIdentifier(): string {
		return 'appointment_reactivated';
	}

	#[\Override]
	public function getName(): string {
		return $this->l->t('A cancelled appointment takes place after all');
	}
}
