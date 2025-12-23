<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Core service for managing appointments and responses.
 * Delegates to specialized services for summary, visibility, and check-in operations.
 */
class AppointmentService {
	private AppointmentMapper $appointmentMapper;
	private AttendanceResponseMapper $responseMapper;
	private IGroupManager $groupManager;
	private IUserManager $userManager;
	private ConfigService $configService;
	private VisibilityService $visibilityService;
	private ResponseSummaryService $responseSummaryService;
	private NotificationService $notificationService;

	public function __construct(
		AppointmentMapper $appointmentMapper,
		AttendanceResponseMapper $responseMapper,
		IGroupManager $groupManager,
		IUserManager $userManager,
		ConfigService $configService,
		VisibilityService $visibilityService,
		ResponseSummaryService $responseSummaryService,
		NotificationService $notificationService
	) {
		$this->appointmentMapper = $appointmentMapper;
		$this->responseMapper = $responseMapper;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->configService = $configService;
		$this->visibilityService = $visibilityService;
		$this->responseSummaryService = $responseSummaryService;
		$this->notificationService = $notificationService;
	}

	/**
	 * Create a new appointment.
	 */
	public function createAppointment(
		string $name,
		string $description,
		string $startDatetime,
		string $endDatetime,
		string $createdBy,
		array $visibleUsers = [],
		array $visibleGroups = [],
		bool $sendNotification = false
	): Appointment {
		$startFormatted = $this->formatDatetime($startDatetime);
		$endFormatted = $this->formatDatetime($endDatetime);

		$appointment = new Appointment();
		$appointment->setName($name);
		$appointment->setDescription($description);
		$appointment->setStartDatetime($startFormatted);
		$appointment->setEndDatetime($endFormatted);
		$appointment->setCreatedBy($createdBy);
		$appointment->setCreatedAt(date('Y-m-d H:i:s'));
		$appointment->setUpdatedAt(date('Y-m-d H:i:s'));
		$appointment->setIsActive(1);
		$appointment->setVisibleUsers(empty($visibleUsers) ? null : json_encode($visibleUsers));
		$appointment->setVisibleGroups(empty($visibleGroups) ? null : json_encode($visibleGroups));

		$appointment = $this->appointmentMapper->insert($appointment);

		if ($sendNotification) {
			$affectedUsers = $this->getAffectedUsers($appointment);
			$affectedUsers = array_filter($affectedUsers, fn($userId) => $userId !== $createdBy);
			$this->notificationService->sendNewAppointmentNotifications($appointment, array_values($affectedUsers));
		}

		return $appointment;
	}

	/**
	 * Update an existing appointment.
	 */
	public function updateAppointment(
		int $id,
		string $name,
		string $description,
		string $startDatetime,
		string $endDatetime,
		string $userId,
		array $visibleUsers = [],
		array $visibleGroups = []
	): Appointment {
		$appointment = $this->appointmentMapper->find($id);

		$startFormatted = $this->formatDatetime($startDatetime);
		$endFormatted = $this->formatDatetime($endDatetime);

		$appointment->setName($name);
		$appointment->setDescription($description);
		$appointment->setStartDatetime($startFormatted);
		$appointment->setEndDatetime($endFormatted);
		$appointment->setUpdatedAt(date('Y-m-d H:i:s'));
		$appointment->setVisibleUsers(empty($visibleUsers) ? null : json_encode($visibleUsers));
		$appointment->setVisibleGroups(empty($visibleGroups) ? null : json_encode($visibleGroups));

		return $this->appointmentMapper->update($appointment);
	}

	/**
	 * Delete an appointment (soft delete).
	 */
	public function deleteAppointment(int $id, string $userId): void {
		$appointment = $this->appointmentMapper->find($id);
		$appointment->setIsActive(0);
		$appointment->setUpdatedAt(date('Y-m-d H:i:s'));
		$this->appointmentMapper->update($appointment);
	}

	/**
	 * Get a single appointment by ID.
	 */
	public function getAppointment(int $id): Appointment {
		return $this->appointmentMapper->find($id);
	}

	/**
	 * Get a single appointment with user response and summary.
	 */
	public function getAppointmentWithUserResponse(int $id, string $userId): ?array {
		$appointment = $this->appointmentMapper->find($id);

		if (!$this->visibilityService->canUserSeeAppointment($appointment, $userId)) {
			return null;
		}

		$appointmentData = $appointment->jsonSerialize();
		$appointmentData['userResponse'] = $this->getUserResponse($appointment->getId(), $userId);
		$appointmentData['responseSummary'] = $this->responseSummaryService->getResponseSummary($appointment->getId());

		return $appointmentData;
	}

	/**
	 * Get all appointments.
	 */
	public function getAllAppointments(): array {
		return $this->appointmentMapper->findAll();
	}

	/**
	 * Get upcoming appointments.
	 */
	public function getUpcomingAppointments(): array {
		return $this->appointmentMapper->findUpcoming();
	}

	/**
	 * Get past appointments.
	 */
	public function getPastAppointments(): array {
		return $this->appointmentMapper->findPast();
	}

	/**
	 * Get appointments created by a specific user.
	 */
	public function getAppointmentsByCreator(string $userId): array {
		return $this->appointmentMapper->findByCreatedBy($userId);
	}

	/**
	 * Submit attendance response.
	 */
	public function submitResponse(
		int $appointmentId,
		string $userId,
		string $response,
		string $comment = ''
	): AttendanceResponse {
		if (!in_array($response, ['yes', 'no', 'maybe'])) {
			throw new \InvalidArgumentException('Invalid response. Must be yes, no, or maybe.');
		}

		$this->appointmentMapper->find($appointmentId);

		try {
			$existingResponse = $this->responseMapper->findByAppointmentAndUser($appointmentId, $userId);
			$existingResponse->setResponse($response);
			$existingResponse->setComment($comment);
			$existingResponse->setRespondedAt(date('Y-m-d H:i:s'));
			return $this->responseMapper->update($existingResponse);
		} catch (DoesNotExistException $e) {
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
	 */
	public function getAppointmentResponses(int $appointmentId): array {
		return $this->responseMapper->findByAppointment($appointmentId);
	}

	/**
	 * Get all responses for an appointment with user details.
	 */
	public function getAppointmentResponsesWithUsers(int $appointmentId, string $requestingUserId): array {
		$appointment = $this->appointmentMapper->find($appointmentId);
		$responses = $this->responseMapper->findByAppointment($appointmentId);
		$result = [];

		foreach ($responses as $response) {
			if (!$this->visibilityService->canUserSeeAppointment($appointment, $response->getUserId())) {
				continue;
			}

			$user = $this->userManager->get($response->getUserId());
			$responseData = $response->jsonSerialize();
			$responseData['userName'] = $user ? $user->getDisplayName() : $response->getUserId();

			if ($user) {
				$userGroups = $this->groupManager->getUserGroups($user);
				$responseData['userGroups'] = array_map(fn($group) => $group->getGID(), $userGroups);
			} else {
				$responseData['userGroups'] = [];
			}

			$result[] = $responseData;
		}

		return $result;
	}

	/**
	 * Get minimal appointment data for navigation menu.
	 */
	public function getAppointmentsForNavigation(string $userId): array {
		$currentAppointments = $this->getUpcomingAppointments();
		$pastAppointments = $this->getPastAppointments();

		return [
			'current' => $this->buildNavigationData($currentAppointments, $userId),
			'past' => $this->buildNavigationData($pastAppointments, $userId),
		];
	}

	/**
	 * Build navigation data for a list of appointments.
	 */
	private function buildNavigationData(array $appointments, string $userId): array {
		$result = [];

		foreach ($appointments as $appointment) {
			if (!$this->visibilityService->canUserSeeAppointment($appointment, $userId)) {
				continue;
			}

			$userResponse = $this->getUserResponse($appointment->getId(), $userId);

			$result[] = [
				'id' => $appointment->getId(),
				'name' => $appointment->getName(),
				'startDatetime' => $this->formatDatetimeToUtc($appointment->getStartDatetime()),
				'userResponse' => ($userResponse && $userResponse->getResponse() !== null)
					? ['response' => $userResponse->getResponse()]
					: null,
			];
		}

		return $result;
	}

	/**
	 * Get appointments with user responses.
	 */
	public function getAppointmentsWithUserResponses(string $userId, bool $showPastAppointments = false): array {
		$appointments = $showPastAppointments
			? $this->getPastAppointments()
			: $this->getUpcomingAppointments();

		$result = [];

		foreach ($appointments as $appointment) {
			if (!$this->visibilityService->canUserSeeAppointment($appointment, $userId)) {
				continue;
			}

			$appointmentData = $appointment->jsonSerialize();
			$userResponse = $this->getUserResponse($appointment->getId(), $userId);
			$appointmentData['userResponse'] = ($userResponse && $userResponse->getResponse() !== null) ? $userResponse : null;
			$appointmentData['responseSummary'] = $this->responseSummaryService->getResponseSummary($appointment->getId());
			$result[] = $appointmentData;
		}

		return $result;
	}

	/**
	 * Get upcoming appointments for dashboard widget.
	 */
	public function getUpcomingAppointmentsForWidget(string $userId, int $limit = 5): array {
		$appointments = $this->getUpcomingAppointments();
		$result = [];
		$count = 0;

		foreach ($appointments as $appointment) {
			if ($count >= $limit) {
				break;
			}

			if (!$this->visibilityService->canUserSeeAppointment($appointment, $userId)) {
				continue;
			}

			$appointmentData = $appointment->jsonSerialize();
			$userResponse = $this->getUserResponse($appointment->getId(), $userId);
			$appointmentData['userResponse'] = ($userResponse && $userResponse->getResponse() !== null) ? $userResponse : null;
			$result[] = $appointmentData;
			$count++;
		}

		return $result;
	}

	/**
	 * Search for users and groups.
	 */
	public function searchUsersAndGroups(string $search = ''): array {
		$results = [];

		$users = $this->userManager->search($search, 20);
		foreach ($users as $user) {
			$results[] = [
				'id' => $user->getUID(),
				'label' => $user->getDisplayName(),
				'type' => 'user',
				'icon' => 'icon-user',
			];
		}

		$groups = $this->groupManager->search($search);
		foreach ($groups as $group) {
			$results[] = [
				'id' => $group->getGID(),
				'label' => $group->getDisplayName(),
				'type' => 'group',
				'icon' => 'icon-group',
			];
		}

		return $results;
	}

	/**
	 * Get all users who can see an appointment.
	 */
	private function getAffectedUsers(Appointment $appointment): array {
		$visibleUsers = $appointment->getVisibleUsers();
		$visibleGroups = $appointment->getVisibleGroups();

		$visibleUsersList = $visibleUsers ? json_decode($visibleUsers, true) : [];
		$visibleGroupsList = $visibleGroups ? json_decode($visibleGroups, true) : [];

		if (empty($visibleUsersList) && empty($visibleGroupsList)) {
			return $this->getAllWhitelistedUsers();
		}

		$userIds = $visibleUsersList;
		foreach ($visibleGroupsList as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group) {
				foreach ($group->getUsers() as $user) {
					$userIds[] = $user->getUID();
				}
			}
		}

		return array_unique($userIds);
	}

	/**
	 * Get all users in whitelisted groups.
	 */
	private function getAllWhitelistedUsers(): array {
		$whitelistedGroups = $this->configService->getWhitelistedGroups();
		$userIds = [];

		if (empty($whitelistedGroups)) {
			$allUsers = $this->userManager->search('');
			foreach ($allUsers as $user) {
				$userIds[] = $user->getUID();
			}
		} else {
			foreach ($whitelistedGroups as $groupId) {
				$group = $this->groupManager->get($groupId);
				if ($group) {
					foreach ($group->getUsers() as $user) {
						$userIds[] = $user->getUID();
					}
				}
			}
		}

		return array_unique($userIds);
	}

	/**
	 * Format datetime to UTC ISO 8601 format.
	 */
	private function formatDatetimeToUtc(string $datetime): string {
		try {
			$utcTimezone = new \DateTimeZone('UTC');
			$date = new \DateTime($datetime, $utcTimezone);
			return $date->format('Y-m-d\TH:i:s\Z');
		} catch (\Exception $e) {
			return $datetime;
		}
	}

	/**
	 * Convert ISO 8601 datetime to MySQL format.
	 */
	private function formatDatetime(string $datetime): string {
		try {
			$date = new \DateTime($datetime);
			return $date->format('Y-m-d H:i:s');
		} catch (\Exception $e) {
			return $datetime;
		}
	}
}
