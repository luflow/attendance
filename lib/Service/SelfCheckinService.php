<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Service for self-check-in operations.
 * Handles finding active appointments and performing self-check-in for users.
 */
class SelfCheckinService {
	private AppointmentMapper $appointmentMapper;
	private AttendanceResponseMapper $responseMapper;
	private VisibilityService $visibilityService;
	private LoggerInterface $logger;

	/** Default time window in minutes before appointment start to allow check-in */
	private const DEFAULT_WINDOW_MINUTES = 30;

	public function __construct(
		AppointmentMapper $appointmentMapper,
		AttendanceResponseMapper $responseMapper,
		VisibilityService $visibilityService,
		LoggerInterface $logger,
	) {
		$this->appointmentMapper = $appointmentMapper;
		$this->responseMapper = $responseMapper;
		$this->visibilityService = $visibilityService;
		$this->logger = $logger;
	}

	/**
	 * Get active appointments that a user can self-check into right now.
	 *
	 * Returns appointments where:
	 * - is_active = 1
	 * - start_datetime - 30min <= NOW <= end_datetime
	 * - User is a target attendee (via visibility settings)
	 *
	 * @param string $userId The user ID
	 * @return array List of appointment data with check-in status
	 */
	public function getActiveAppointments(string $userId): array {
		$appointments = $this->appointmentMapper->findActiveInWindow(self::DEFAULT_WINDOW_MINUTES);

		$result = [];
		foreach ($appointments as $appointment) {
			// Only include appointments where the user is a target attendee
			if (!$this->visibilityService->isUserTargetAttendee($appointment, $userId)) {
				continue;
			}

			$appointmentData = $appointment->jsonSerialize();

			// Check if user is already checked in
			try {
				$response = $this->responseMapper->findByAppointmentAndUser($appointment->getId(), $userId);
				$responseData = $response->jsonSerialize();
				$appointmentData['alreadyCheckedIn'] = $response->isCheckedIn();
				$appointmentData['checkinState'] = $responseData['checkinState'];
				$appointmentData['checkinAt'] = $responseData['checkinAt'];
			} catch (DoesNotExistException $e) {
				$appointmentData['alreadyCheckedIn'] = false;
				$appointmentData['checkinState'] = null;
				$appointmentData['checkinAt'] = null;
			}

			$result[] = $appointmentData;
		}

		return $result;
	}

	/**
	 * Self-check-in to a specific appointment.
	 *
	 * @param int $appointmentId The appointment ID
	 * @param string $userId The user checking themselves in
	 * @return array Result with appointment details and check-in status
	 * @throws \InvalidArgumentException If validation fails
	 */
	public function selfCheckin(int $appointmentId, string $userId): array {
		// Verify appointment exists and is active
		try {
			$appointment = $this->appointmentMapper->find($appointmentId);
		} catch (DoesNotExistException $e) {
			throw new \InvalidArgumentException('Appointment not found.');
		}

		if (!$appointment->getIsActive()) {
			throw new \InvalidArgumentException('Appointment is not active.');
		}

		// Verify appointment is within the check-in time window
		$now = new \DateTime('now', new \DateTimeZone('UTC'));
		$start = new \DateTime($appointment->getStartDatetime(), new \DateTimeZone('UTC'));
		$end = new \DateTime($appointment->getEndDatetime(), new \DateTimeZone('UTC'));
		$windowStart = (clone $start)->modify('-' . self::DEFAULT_WINDOW_MINUTES . ' minutes');

		if ($now < $windowStart || $now > $end) {
			throw new \InvalidArgumentException('Appointment is not within the check-in time window.');
		}

		// Verify user is a target attendee
		if (!$this->visibilityService->isUserTargetAttendee($appointment, $userId)) {
			throw new \InvalidArgumentException('You are not an attendee of this appointment.');
		}

		// Find existing response or create new one
		try {
			$response = $this->responseMapper->findByAppointmentAndUser($appointmentId, $userId);

			// If already checked in, return existing check-in
			if ($response->isCheckedIn()) {
				$this->logger->info("User {$userId} already checked in to appointment {$appointmentId}");
				$responseData = $response->jsonSerialize();
				return [
					'appointment' => $appointment->jsonSerialize(),
					'checkinState' => $responseData['checkinState'],
					'checkinAt' => $responseData['checkinAt'],
					'alreadyCheckedIn' => true,
				];
			}
		} catch (DoesNotExistException $e) {
			// Create new response
			$response = new AttendanceResponse();
			$response->setAppointmentId($appointmentId);
			$response->setUserId($userId);
		}

		// Perform check-in
		$response->setCheckinState('yes');
		$response->setCheckinBy($userId); // Self-check-in: checkinBy == userId
		$response->setCheckinAt(gmdate('Y-m-d H:i:s'));
		$response->setCheckinSource('nfc');

		if ($response->getId()) {
			$this->responseMapper->update($response);
		} else {
			$this->responseMapper->insert($response);
		}

		$this->logger->info("User {$userId} self-checked in to appointment {$appointmentId}");

		$responseData = $response->jsonSerialize();
		return [
			'appointment' => $appointment->jsonSerialize(),
			'checkinState' => 'yes',
			'checkinAt' => $responseData['checkinAt'],
			'alreadyCheckedIn' => false,
		];
	}
}
