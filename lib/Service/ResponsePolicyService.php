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
		private CapacityService $capacityService,
	) {
	}

	/**
	 * Whether this appointment offers "Maybe". The per-appointment column wins;
	 * NULL means the appointment has no opinion and follows the instance default.
	 */
	public function isMaybeAllowed(Appointment $appointment): bool {
		// A limit and "Maybe" cannot coexist: a maybe would hold a spot away
		// from somebody who would commit to it. The limit wins over both the
		// appointment's own answer and the instance default.
		if ($this->capacityService->limitOf($appointment) !== null) {
			return false;
		}
		return $appointment->getAllowMaybe() ?? $this->configService->isMaybeAllowed();
	}

	/**
	 * @param ?string $response the answer about to be stored, or null to withdraw
	 * @param ?string $currentResponse the answer on record, null when there is none
	 * @param bool $acceptWaitlist the person asked for a place in line if the
	 *                             appointment turns out to be full
	 * @param bool $onBehalf an organizer is answering for somebody else, which
	 *                       may exceed the limit — they own the appointment and
	 *                       real rosters have exceptions
	 * @throws \InvalidArgumentException if this appointment does not accept the answer
	 * @throws \RuntimeException if the appointment is full
	 */
	public function assertResponseAllowed(
		Appointment $appointment,
		?string $response,
		?string $currentResponse = null,
		bool $acceptWaitlist = false,
		bool $onBehalf = false,
	): void {
		if ($response === null) {
			return;
		}
		if (!in_array($response, self::RESPONSES, true)) {
			throw new \InvalidArgumentException('Invalid response. Must be yes, no, maybe, or null.');
		}
		if ($response === 'maybe' && !$this->isMaybeAllowed($appointment)) {
			throw new \InvalidArgumentException('This appointment does not accept "maybe" as an answer.');
		}

		// Only a fresh yes can run into the limit. Re-saving one — the same
		// answer again, an edited comment — changes neither the queue nor its
		// order, so it must not be turned away.
		if ($response !== 'yes' || $currentResponse === 'yes' || $onBehalf) {
			return;
		}
		if (!$this->capacityService->isFull($appointment)) {
			return;
		}
		if (!$this->capacityService->isWaitlistEnabled($appointment)) {
			throw new \RuntimeException('This appointment is full.');
		}
		if (!$acceptWaitlist) {
			throw new \RuntimeException('This appointment is full. Join the waitlist to take the next free spot.');
		}
	}
}
