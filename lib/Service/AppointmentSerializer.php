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
		// Handed on to isFull() so the queue is counted once; without a limit
		// occupancy() answers 0 without a query.
		$occupancy = $this->capacityService->occupancy($appointment);
		$data['occupancy'] = $occupancy;
		$data['isFull'] = $this->capacityService->isFull($appointment, $occupancy);
		return $data;
	}
}
