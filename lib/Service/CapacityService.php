<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Audit\Verb;
use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;

/**
 * An appointment's attendance limit and the queue behind it.
 *
 * Nothing here is stored as membership. Who holds a spot is derived from the
 * yes-responses ordered by spot_claimed_at: the first max_attendees are in, the
 * rest are waiting. A spot that frees up therefore promotes the next person as
 * a consequence of the ordering, with no write and nothing to keep in step.
 *
 * Orthogonal to BookingService, which is the organizer planning people in by
 * hand. Capacity is self-service and per-appointment; booking is organizer-
 * authored and switched on instance-wide. Where both are in use, booking
 * operates within the capacity.
 */
class CapacityService {
	/** The person is waiting for a spot. Set when they join, never notified. */
	public const NOTIFIED_WAITLISTED = 'waitlisted';
	/** They were told a spot came free. */
	public const NOTIFIED_PROMOTED = 'promoted';
	/** They were told at close that no spot came free. */
	public const NOTIFIED_NOT_PROMOTED = 'not_promoted';

	public function __construct(
		private AttendanceResponseMapper $responseMapper,
		private NotificationService $notificationService,
		private AuditEventService $auditEventService,
	) {
	}

	/**
	 * The attendance limit, or null when the appointment does not have one.
	 * Null is also the feature's off switch: without a limit nothing below
	 * runs a query.
	 */
	public function limitOf(Appointment $appointment): ?int {
		$limit = $appointment->getMaxAttendees();
		return $limit === null || $limit <= 0 ? null : $limit;
	}

	/**
	 * Whether people who arrive at a full appointment may queue for a spot.
	 * Meaningless without a limit, so false there.
	 */
	public function isWaitlistEnabled(Appointment $appointment): bool {
		return $this->limitOf($appointment) !== null && $appointment->getWaitlistEnabled();
	}

	/**
	 * Everyone who answered yes, in queue order.
	 *
	 * @return list<AttendanceResponse>
	 */
	public function queue(Appointment $appointment): array {
		return $this->responseMapper->findClaimedSpots($appointment->getId());
	}

	/**
	 * How many people answered yes. Can exceed the limit: an organizer may
	 * answer on somebody's behalf past it, and lowering a limit never takes a
	 * spot back from a person who already had one.
	 */
	public function occupancy(Appointment $appointment): int {
		if ($this->limitOf($appointment) === null) {
			return 0;
		}
		return count($this->queue($appointment));
	}

	/**
	 * Whether a further self-service yes would have to wait.
	 */
	public function isFull(Appointment $appointment): bool {
		$limit = $this->limitOf($appointment);
		return $limit !== null && $this->occupancy($appointment) >= $limit;
	}

	/**
	 * Split the queue into the people holding a spot and the people waiting.
	 *
	 * @param list<AttendanceResponse>|null $queue pre-loaded queue, to save a query
	 * @return array{confirmed: list<AttendanceResponse>, waiting: list<AttendanceResponse>}
	 */
	public function split(Appointment $appointment, ?array $queue = null): array {
		$limit = $this->limitOf($appointment);
		$queue ??= $this->queue($appointment);
		if ($limit === null) {
			return ['confirmed' => $queue, 'waiting' => []];
		}
		return [
			'confirmed' => array_slice($queue, 0, $limit),
			'waiting' => array_slice($queue, $limit),
		];
	}

	/**
	 * A person's standing in the queue: whether they are waiting, and their
	 * place in line counted from 1. Both null-ish for an appointment without a
	 * limit, so callers can hand the result straight to a payload.
	 *
	 * @return array{waitlisted: bool, waitlistPosition: ?int}
	 */
	public function standingOf(Appointment $appointment, string $userId): array {
		if ($this->limitOf($appointment) === null) {
			return ['waitlisted' => false, 'waitlistPosition' => null];
		}

		$waiting = $this->split($appointment)['waiting'];
		foreach ($waiting as $index => $response) {
			if ($response->getUserId() === $userId) {
				return ['waitlisted' => true, 'waitlistPosition' => $index + 1];
			}
		}

		return ['waitlisted' => false, 'waitlistPosition' => null];
	}

	/**
	 * Whether this person's yes currently holds a spot rather than a place in
	 * line. True for everyone who answered yes when there is no limit.
	 */
	public function holdsSpot(Appointment $appointment, string $userId): bool {
		if ($this->limitOf($appointment) === null) {
			return true;
		}
		foreach ($this->split($appointment)['confirmed'] as $response) {
			if ($response->getUserId() === $userId) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Keep the queue's ordering key in step with an answer that is about to be
	 * written. Claiming a spot stamps the time; giving one up clears it, so
	 * answering yes again later joins the back of the queue rather than
	 * reclaiming the old place. Re-saving a yes — a changed comment, the same
	 * answer twice — leaves the stamp alone.
	 *
	 * @param ?string $before the answer on record, null when there is none
	 * @param ?string $after the answer being written, null when withdrawing
	 */
	public function applySpotClaim(AttendanceResponse $response, ?string $before, ?string $after): void {
		if ($after === 'yes') {
			if ($before !== 'yes' || $response->getSpotClaimedAt() === null) {
				$response->setSpotClaimedAt(gmdate('Y-m-d H:i:s'));
			}
			return;
		}

		$response->setSpotClaimedAt(null);
		// Leaving the queue drops what they were last told, so re-joining can
		// notify them again.
		$response->setWaitlistNotifiedStatus(null);
		$response->setWaitlistNotifiedAt(null);
	}

	/**
	 * Bring the queue's notification markers in step and tell anyone a freed
	 * spot has just let in.
	 *
	 * Membership is derived, so a person can drift in and out of the limit as
	 * others change their answers. The marker is what keeps that from becoming
	 * a stream of notifications: it records the last thing they were told, and
	 * only a change from waiting to holding a spot is worth telling them.
	 * Joining the queue is marked but never announced — they just clicked the
	 * button.
	 */
	public function syncWaitlistNotifications(Appointment $appointment): void {
		if ($this->limitOf($appointment) === null) {
			return;
		}

		$split = $this->split($appointment);

		foreach ($split['confirmed'] as $response) {
			$status = $response->getWaitlistNotifiedStatus();
			if ($status !== self::NOTIFIED_WAITLISTED && $status !== self::NOTIFIED_NOT_PROMOTED) {
				continue;
			}
			$this->markNotified($response, self::NOTIFIED_PROMOTED);
			$this->notificationService->sendWaitlistNotification(
				$appointment,
				$response->getUserId(),
				'waitlist_promoted',
			);
			// Nobody performed this change, which is exactly why it is worth
			// recording: it answers "why am I suddenly in?".
			$this->auditEventService->record(
				Verb::WAITLIST_PROMOTED,
				$appointment->getId(),
				null,
				$response->getUserId(),
			);
		}

		foreach ($split['waiting'] as $response) {
			if ($response->getWaitlistNotifiedStatus() === self::NOTIFIED_WAITLISTED) {
				continue;
			}
			$this->markNotified($response, self::NOTIFIED_WAITLISTED);
		}
	}

	/**
	 * Close the loop for everyone still waiting when the inquiry ends. Without
	 * it they keep the date free for a spot that is never coming.
	 */
	public function notifyNotPromoted(Appointment $appointment): void {
		if ($this->limitOf($appointment) === null) {
			return;
		}

		foreach ($this->split($appointment)['waiting'] as $response) {
			if ($response->getWaitlistNotifiedStatus() === self::NOTIFIED_NOT_PROMOTED) {
				continue;
			}
			$this->markNotified($response, self::NOTIFIED_NOT_PROMOTED);
			$this->notificationService->sendWaitlistNotification(
				$appointment,
				$response->getUserId(),
				'waitlist_not_promoted',
			);
		}
	}

	private function markNotified(AttendanceResponse $response, string $status): void {
		$response->setWaitlistNotifiedStatus($status);
		$response->setWaitlistNotifiedAt(gmdate('Y-m-d H:i:s'));
		$this->responseMapper->update($response);
	}
}
