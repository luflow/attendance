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
