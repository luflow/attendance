<?php

declare(strict_types=1);

namespace OCA\Attendance\Activity\Setting;

final class AppointmentsSeriesUpdatedSetting extends AttendanceActivitySetting {
	#[\Override]
	public function getIdentifier(): string {
		return 'appointments_series_updated';
	}

	#[\Override]
	public function getName(): string {
		return $this->l->t('Several appointments of a series changed at once');
	}
}
