<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Booking / planning: a second, orthogonal dimension next to the yes/no/maybe
 * response. Managers mark yes-responders as "booked" (planned in) for an
 * appointment. The status lives per response.
 *
 * bookingStatus values:
 *  - null       open / undecided (default; also a plain yes-responder)
 *  - 'booked'   the person is planned in
 *  - 'declined' the person was not planned in (set by the close-time wave)
 */
class BookingService {
	public const STATUS_BOOKED = 'booked';
	public const STATUS_DECLINED = 'declined';

	public function __construct(
		private AttendanceResponseMapper $responseMapper,
		private AppointmentMapper $appointmentMapper,
		private ConfigService $configService,
		private NotificationService $notificationService,
	) {
	}

	/**
	 * Whether the booking feature is enabled instance-wide.
	 */
	public function isEnabled(): bool {
		return $this->configService->isBookingEnabled();
	}

	/**
	 * Mark a yes-responder as booked (planned in) for an appointment.
	 *
	 * @throws DoesNotExistException if the user has no response on this appointment
	 * @throws \InvalidArgumentException if the response is not a "yes"
	 */
	public function book(int $appointmentId, string $userId): AttendanceResponse {
		return $this->setBookingStatus($appointmentId, $userId, self::STATUS_BOOKED);
	}

	/**
	 * Clear a booking, returning the response to the open/undecided state.
	 *
	 * @throws DoesNotExistException if the user has no response on this appointment
	 */
	public function unbook(int $appointmentId, string $userId): AttendanceResponse {
		return $this->setBookingStatus($appointmentId, $userId, null);
	}

	/**
	 * Set the booking status of a single response. Only yes-responders may be
	 * booked/declined; clearing (null) is always allowed. Returns the updated
	 * response.
	 *
	 * @param ?string $status null, 'booked' or 'declined'
	 * @throws DoesNotExistException if the user has no response on this appointment
	 * @throws \InvalidArgumentException on an invalid status or a non-yes booking
	 */
	public function setBookingStatus(int $appointmentId, string $userId, ?string $status): AttendanceResponse {
		if ($status !== null
			&& $status !== self::STATUS_BOOKED
			&& $status !== self::STATUS_DECLINED) {
			throw new \InvalidArgumentException('Invalid booking status: ' . $status);
		}

		// Scheduling is communicated by the close-time notification wave. Changing
		// who is scheduled after the inquiry is closed would silently skip that
		// wave, so block it — the manager must reopen the inquiry to make changes.
		$appointment = $this->appointmentMapper->find($appointmentId);
		if ($appointment->getClosedAt() !== null) {
			throw new \InvalidArgumentException('Cannot change scheduling on a closed inquiry');
		}

		$response = $this->responseMapper->findByAppointmentAndUser($appointmentId, $userId);

		// Booking only makes sense for people who said yes. Clearing is fine
		// regardless (e.g. after they changed their answer away from yes).
		if ($status !== null && $response->getResponse() !== 'yes') {
			throw new \InvalidArgumentException('Only yes-responders can be booked');
		}

		if ($response->getBookingStatus() === $status) {
			return $response;
		}

		$response->setBookingStatus($status);
		return $this->responseMapper->update($response);
	}

	/**
	 * Whether the user was scheduled out of an appointment: planning is on, the
	 * inquiry is closed, at least one person got a place — and this user did
	 * not. The mirror image of the notifyOnClose() wave below, kept next to it
	 * on purpose: exactly the people told "you are not scheduled" are the ones
	 * callers may hide the appointment from.
	 *
	 * Everything softer stays visible: an open inquiry has decided nothing yet,
	 * and an appointment nobody was scheduled for is one where the manager
	 * simply doesn't use the feature — it stays visible to everyone.
	 */
	public function isScheduledOut(Appointment $appointment, string $userId): bool {
		if (!$this->isEnabled() || !$appointment->isClosed()) {
			return false;
		}

		$anyoneBooked = false;
		foreach ($this->responseMapper->findByAppointment($appointment->getId()) as $response) {
			if ($response->getBookingStatus() !== self::STATUS_BOOKED) {
				continue;
			}
			// Own booking wins outright — no need to look at the rest.
			if ($response->getUserId() === $userId) {
				return false;
			}
			$anyoneBooked = true;
		}

		return $anyoneBooked;
	}

	/**
	 * The booking status as the user should be told it, which is not always the
	 * one on the row.
	 *
	 * The close-time wave stamps every yes-responder 'booked' or 'declined', so
	 * normally the stored value is the whole truth. It can fall behind, though:
	 * a wave that found nobody booked stamps no one, and a later booking (after
	 * a reopen and re-close) leaves the people it passed over still sitting on
	 * null. To them the appointment reads "you answered yes" when in fact a
	 * place went to someone else — so fall back to the same verdict the filter
	 * already draws in [isScheduledOut].
	 */
	public function effectiveBookingStatus(
		Appointment $appointment,
		AttendanceResponse $response,
		string $userId,
	): ?string {
		$stored = $response->getBookingStatus();
		if ($stored !== null) {
			return $stored;
		}
		return $this->isScheduledOut($appointment, $userId) ? self::STATUS_DECLINED : null;
	}

	/**
	 * Notification wave triggered when an appointment is closed: tell booked
	 * yes-responders they are planned in and the remaining yes-responders they
	 * are not. Rules:
	 *  - Only fires when at least one person is booked. Zero bookings → closing
	 *    behaves exactly as before (no notification), protecting managers who
	 *    don't use the feature even while it is enabled.
	 *  - Reopen-safe: each response remembers the last state communicated to it
	 *    (bookingNotifiedStatus). Re-closing only notifies people whose effective
	 *    status changed since — no duplicate notifications.
	 *
	 * @return array{booked: int, declined: int} count of notifications actually sent
	 */
	public function notifyOnClose(Appointment $appointment): array {
		$sent = ['booked' => 0, 'declined' => 0];
		if (!$this->isEnabled()) {
			return $sent;
		}

		$responses = $this->responseMapper->findByAppointment($appointment->getId());

		// Effective status per yes-responder: booked → 'booked', otherwise 'declined'.
		$targets = [];
		$bookedCount = 0;
		foreach ($responses as $response) {
			if ($response->getResponse() !== 'yes') {
				continue;
			}
			$status = $response->getBookingStatus() === self::STATUS_BOOKED
				? self::STATUS_BOOKED
				: self::STATUS_DECLINED;
			$targets[] = [$response, $status];
			if ($status === self::STATUS_BOOKED) {
				$bookedCount++;
			}
		}

		// Wave only when at least one person is booked.
		if ($bookedCount === 0) {
			return $sent;
		}

		$now = gmdate('Y-m-d H:i:s');
		foreach ($targets as [$response, $status]) {
			// Diff against the last communicated state — skip if unchanged.
			if ($response->getBookingNotifiedStatus() === $status) {
				continue;
			}
			$this->notificationService->sendBookingNotification(
				$appointment,
				$response->getUserId(),
				$status,
			);
			$response->setBookingNotifiedStatus($status);
			$response->setBookingNotifiedAt($now);
			$this->responseMapper->update($response);

			if ($status === self::STATUS_BOOKED) {
				$sent['booked']++;
			} else {
				$sent['declined']++;
			}
		}

		return $sent;
	}
}
