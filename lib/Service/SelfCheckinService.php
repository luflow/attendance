<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Audit\Verb;
use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Db\DatetimeFormatTrait;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Service for self-check-in operations.
 * Handles finding active appointments and performing self-check-in for users.
 */
class SelfCheckinService {
	use DatetimeFormatTrait;

	private AppointmentMapper $appointmentMapper;
	private AttendanceResponseMapper $responseMapper;
	private VisibilityService $visibilityService;
	private ConfigService $configService;
	private AuditEventService $auditEventService;
	private LoggerInterface $logger;

	/** Valid trigger methods for a self-check-in, mirrored in checkin_source as "self_<method>" */
	private const VALID_METHODS = ['qr', 'nfc'];

	public function __construct(
		AppointmentMapper $appointmentMapper,
		AttendanceResponseMapper $responseMapper,
		VisibilityService $visibilityService,
		ConfigService $configService,
		AuditEventService $auditEventService,
		LoggerInterface $logger,
	) {
		$this->appointmentMapper = $appointmentMapper;
		$this->responseMapper = $responseMapper;
		$this->visibilityService = $visibilityService;
		$this->configService = $configService;
		$this->auditEventService = $auditEventService;
		$this->logger = $logger;
	}

	/**
	 * Get the self-check-in overview for a user: appointments that can be
	 * checked into right now, plus the next upcoming appointment whose
	 * check-in window has not opened yet (for the "nothing right now" screen).
	 *
	 * An appointment is checkin-able when:
	 * - is_active = 1 and not cancelled
	 * - start_datetime - window <= NOW <= end_datetime
	 * - the user is a target attendee (via visibility settings)
	 *
	 * @param string $userId The user ID
	 * @return array{appointments: list<array<string, mixed>>, nextUpcoming: ?array{id: int, name: string, startDatetime: string, checkinWindowStartsAt: string}}
	 */
	public function getOverview(string $userId): array {
		$windowMinutes = $this->configService->getSelfCheckinWindowMinutes();
		$appointments = $this->appointmentMapper->findActiveInWindow($windowMinutes);

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

		return [
			'appointments' => $result,
			// Only needed for the "nothing right now" screen — skip the extra
			// query when there is something to check into.
			'nextUpcoming' => $result === []
				? $this->findNextUpcoming($userId, $windowMinutes)
				: null,
		];
	}

	/**
	 * Find the next visible appointment whose check-in window has not opened
	 * yet, so clients can show "check-in opens at …" when nothing matches now.
	 *
	 * @return ?array{id: int, name: string, startDatetime: string, checkinWindowStartsAt: string}
	 */
	private function findNextUpcoming(string $userId, int $windowMinutes): ?array {
		foreach ($this->appointmentMapper->findUpcomingOutsideWindow($windowMinutes) as $appointment) {
			if (!$this->visibilityService->isUserTargetAttendee($appointment, $userId)) {
				continue;
			}
			// UTC-marked ISO strings like every other datetime in the API —
			// naive strings would be interpreted as local time by clients.
			return [
				'id' => $appointment->getId(),
				'name' => $appointment->getName(),
				'startDatetime' => $this->formatDatetimeToUtc($appointment->getStartDatetime()) ?? '',
				'checkinWindowStartsAt' => $this->formatUtcDatetime($this->getWindowStart($appointment, $windowMinutes)),
			];
		}
		return null;
	}

	private function getWindowStart(Appointment $appointment, int $windowMinutes): \DateTime {
		$start = new \DateTime($appointment->getStartDatetime(), new \DateTimeZone('UTC'));
		return $start->modify('-' . $windowMinutes . ' minutes');
	}

	/**
	 * Self-check-in to a specific appointment.
	 *
	 * @param int $appointmentId The appointment ID
	 * @param string $userId The user checking themselves in
	 * @param string $method How the check-in was triggered ('qr' or 'nfc')
	 * @return array Result with appointment details and check-in status
	 * @throws \InvalidArgumentException If validation fails
	 */
	public function selfCheckin(int $appointmentId, string $userId, string $method): array {
		if (!in_array($method, self::VALID_METHODS, true)) {
			throw new \InvalidArgumentException('Invalid check-in method.');
		}

		// Verify appointment exists and is active
		try {
			$appointment = $this->appointmentMapper->find($appointmentId);
		} catch (DoesNotExistException $e) {
			throw new \InvalidArgumentException('Appointment not found.');
		}

		if (!$appointment->getIsActive() || $appointment->isCancelled()) {
			throw new \InvalidArgumentException('Appointment is not active.');
		}

		// Verify appointment is within the check-in time window
		$now = new \DateTime('now', new \DateTimeZone('UTC'));
		$end = new \DateTime($appointment->getEndDatetime(), new \DateTimeZone('UTC'));
		$windowStart = $this->getWindowStart($appointment, $this->configService->getSelfCheckinWindowMinutes());

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
		$response->setCheckinSource('self_' . $method);

		if ($response->getId()) {
			$this->responseMapper->update($response);
		} else {
			$this->responseMapper->insert($response);
		}

		$this->auditEventService->record(
			Verb::CHECKIN_RECORDED,
			$appointmentId,
			$userId,
			$userId,
			['checkinState' => 'yes', 'method' => $method],
			Verb::SOURCE_SELF_CHECKIN,
		);

		$this->logger->info("User {$userId} self-checked in to appointment {$appointmentId} via {$method}");

		$responseData = $response->jsonSerialize();
		return [
			'appointment' => $appointment->jsonSerialize(),
			'checkinState' => 'yes',
			'checkinAt' => $responseData['checkinAt'],
			'alreadyCheckedIn' => false,
		];
	}
}
