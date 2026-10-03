<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;

/**
 * The appointment payload every client sees.
 *
 * The entity cannot build it alone: whether "Maybe" is offered depends on the
 * instance default and on whether the appointment has a limit, and occupancy is
 * derived from the queue. Both the appointment endpoints and the check-in view
 * hand out this shape, so it lives here rather than in either of them.
 */
class AppointmentSerializer {
	public function __construct(
		private ResponsePolicyService $responsePolicyService,
		private CapacityService $capacityService,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function serialize(Appointment $appointment): array {
		/** @var array<string, mixed> $data */
		$data = $appointment->jsonSerialize();
		$data['allowMaybe'] = $this->responsePolicyService->isMaybeAllowed($appointment);
		// Only an appointment with a limit pays for the queue query, which is
		// why every reader asks limitOf() first. Without one there is nothing to
		// count and nothing that can be full.
		$hasLimit = $this->capacityService->limitOf($appointment) !== null;
		$data['occupancy'] = $hasLimit ? $this->capacityService->occupancy($appointment) : 0;
		$data['isFull'] = $hasLimit && $this->capacityService->isFull($appointment);
		return $data;
	}
}
