<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;

/**
 * The rules deciding which answers an appointment accepts.
 *
 * Two services persist responses: AppointmentService::applyResponse() for the
 * web, mobile and on-behalf paths, ResponseService::submitResponse() for the
 * quick-response links in notifications. Both ask here, so a rule cannot hold
 * on one path and leak on the other.
 */
class ResponsePolicyService {
	/** Every answer the storage layer understands, in display order. */
	public const RESPONSES = ['yes', 'maybe', 'no'];

	public function __construct(
		private ConfigService $configService,
	) {
	}

	/**
	 * Whether this appointment offers "Maybe". The per-appointment column wins;
	 * NULL means the appointment has no opinion and follows the instance default.
	 */
	public function isMaybeAllowed(Appointment $appointment): bool {
		return $appointment->getAllowMaybe() ?? $this->configService->isMaybeAllowed();
	}

	/**
	 * @param ?string $response the answer about to be stored, or null to withdraw
	 * @throws \InvalidArgumentException if this appointment does not accept it
	 */
	public function assertResponseAllowed(Appointment $appointment, ?string $response): void {
		if ($response === null) {
			return;
		}
		if (!in_array($response, self::RESPONSES, true)) {
			throw new \InvalidArgumentException('Invalid response. Must be yes, no, maybe, or null.');
		}
		if ($response === 'maybe' && !$this->isMaybeAllowed($appointment)) {
			throw new \InvalidArgumentException('This appointment does not accept "maybe" as an answer.');
		}
	}
}
