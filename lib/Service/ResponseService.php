<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Service for handling attendance responses.
 * Manages CRUD operations for user responses to appointments.
 */
class ResponseService {
	private AppointmentMapper $appointmentMapper;
	private AttendanceResponseMapper $responseMapper;
	private IGroupManager $groupManager;
	private IUserManager $userManager;

	public function __construct(
		AppointmentMapper $appointmentMapper,
		AttendanceResponseMapper $responseMapper,
		IGroupManager $groupManager,
		IUserManager $userManager
	) {
		$this->appointmentMapper = $appointmentMapper;
		$this->responseMapper = $responseMapper;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
	}

	/**
	 * Submit or update an attendance response.
	 *
	 * @param int $appointmentId The appointment ID
	 * @param string $userId The user ID
	 * @param string $response The response (yes, no, maybe)
	 * @param string $comment Optional comment
	 * @return AttendanceResponse The saved response
	 * @throws \InvalidArgumentException If response is invalid
	 */
	public function submitResponse(
		int $appointmentId,
		string $userId,
		string $response,
		string $comment = ''
	): AttendanceResponse {
		// Validate response
		if (!in_array($response, ['yes', 'no', 'maybe'])) {
			throw new \InvalidArgumentException('Invalid response. Must be yes, no, or maybe.');
		}

		// Check if appointment exists
		$this->appointmentMapper->find($appointmentId);

		// Check if user already responded
		try {
			$existingResponse = $this->responseMapper->findByAppointmentAndUser($appointmentId, $userId);
			// Update existing response
			$existingResponse->setResponse($response);
			$existingResponse->setComment($comment);
			$existingResponse->setRespondedAt(date('Y-m-d H:i:s'));
			return $this->responseMapper->update($existingResponse);
		} catch (DoesNotExistException $e) {
			// Create new response
			$attendanceResponse = new AttendanceResponse();
			$attendanceResponse->setAppointmentId($appointmentId);
			$attendanceResponse->setUserId($userId);
			$attendanceResponse->setResponse($response);
			$attendanceResponse->setComment($comment);
			$attendanceResponse->setRespondedAt(date('Y-m-d H:i:s'));
			return $this->responseMapper->insert($attendanceResponse);
		}
	}

	/**
	 * Get user's response for an appointment.
	 *
	 * @param int $appointmentId The appointment ID
	 * @param string $userId The user ID
	 * @return AttendanceResponse|null The response or null if not found
	 */
	public function getUserResponse(int $appointmentId, string $userId): ?AttendanceResponse {
		try {
			return $this->responseMapper->findByAppointmentAndUser($appointmentId, $userId);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Get all responses for an appointment.
	 *
	 * @param int $appointmentId The appointment ID
	 * @return array<AttendanceResponse> List of responses
	 */
	public function getAppointmentResponses(int $appointmentId): array {
		return $this->responseMapper->findByAppointment($appointmentId);
	}

	/**
	 * Get all responses for an appointment with user details.
	 *
	 * @param int $appointmentId The appointment ID
	 * @return array List of responses with user details
	 */
	public function getAppointmentResponsesWithUsers(int $appointmentId): array {
		$responses = $this->responseMapper->findByAppointment($appointmentId);
		$result = [];

		foreach ($responses as $response) {
			$user = $this->userManager->get($response->getUserId());
			$responseData = $response->jsonSerialize();
			$responseData['userName'] = $user ? $user->getDisplayName() : $response->getUserId();

			// Add user groups
			if ($user) {
				$userGroups = $this->groupManager->getUserGroups($user);
				$responseData['userGroups'] = array_map(function ($group) {
					return $group->getGID();
				}, $userGroups);
			} else {
				$responseData['userGroups'] = [];
			}

			$result[] = $responseData;
		}

		return $result;
	}

	/**
	 * Check-in a user for an appointment.
	 *
	 * @param int $appointmentId The appointment ID
	 * @param string $targetUserId The user being checked in
	 * @param string|null $response The check-in state (yes, no, maybe)
	 * @param string|null $comment The check-in comment
	 * @param string $adminUserId The admin performing the check-in
	 * @return AttendanceResponse The updated response
	 * @throws \InvalidArgumentException If response is invalid
	 */
	public function checkinResponse(
		int $appointmentId,
		string $targetUserId,
		?string $response,
		?string $comment,
		string $adminUserId
	): AttendanceResponse {
		// Validate response if provided
		if ($response !== null && !in_array($response, ['yes', 'no', 'maybe'])) {
			throw new \InvalidArgumentException('Invalid response. Must be yes, no, or maybe.');
		}

		// Find existing response or create new one
		try {
			$attendanceResponse = $this->responseMapper->findByAppointmentAndUser($appointmentId, $targetUserId);
		} catch (DoesNotExistException $e) {
			// Create new response if none exists
			$attendanceResponse = new AttendanceResponse();
			$attendanceResponse->setAppointmentId($appointmentId);
			$attendanceResponse->setUserId($targetUserId);
		}

		// Set checkin values
		if ($response !== null) {
			$attendanceResponse->setCheckinState($response);
		}
		if ($comment !== null) {
			$attendanceResponse->setCheckinComment($comment);
		}
		$attendanceResponse->setCheckinBy($adminUserId);
		$attendanceResponse->setCheckinAt(date('Y-m-d H:i:s'));

		// Save or update
		if ($attendanceResponse->getId()) {
			return $this->responseMapper->update($attendanceResponse);
		} else {
			return $this->responseMapper->insert($attendanceResponse);
		}
	}

	/**
	 * Check if a user has responded to an appointment.
	 *
	 * @param int $appointmentId The appointment ID
	 * @param string $userId The user ID
	 * @return bool True if user has responded
	 */
	public function hasUserResponded(int $appointmentId, string $userId): bool {
		return $this->getUserResponse($appointmentId, $userId) !== null;
	}

	/**
	 * Get list of user IDs who have responded to an appointment.
	 *
	 * @param int $appointmentId The appointment ID
	 * @return array<string> List of user IDs
	 */
	public function getRespondedUserIds(int $appointmentId): array {
		$responses = $this->responseMapper->findByAppointment($appointmentId);
		return array_map(fn($response) => $response->getUserId(), $responses);
	}
}
