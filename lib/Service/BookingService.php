<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

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
}
