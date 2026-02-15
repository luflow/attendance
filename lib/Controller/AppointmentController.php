<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Service\AppointmentService;
use OCA\Attendance\Service\AttachmentService;
use OCA\Attendance\Service\CalendarService;
use OCA\Attendance\Service\CheckinService;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\ExportService;
use OCA\Attendance\Service\NotificationService;
use OCA\Attendance\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class AppointmentController extends Controller {
	private AppointmentService $appointmentService;
	private AttachmentService $attachmentService;
	private CalendarService $calendarService;
	private CheckinService $checkinService;
	private ConfigService $configService;
	private PermissionService $permissionService;
	private ExportService $exportService;
	private NotificationService $notificationService;
	private IUserSession $userSession;

	public function __construct(
		string $appName,
		IRequest $request,
		AppointmentService $appointmentService,
		AttachmentService $attachmentService,
		CalendarService $calendarService,
		CheckinService $checkinService,
		ConfigService $configService,
		PermissionService $permissionService,
		ExportService $exportService,
		NotificationService $notificationService,
		IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
		$this->appointmentService = $appointmentService;
		$this->attachmentService = $attachmentService;
		$this->calendarService = $calendarService;
		$this->checkinService = $checkinService;
		$this->configService = $configService;
		$this->permissionService = $permissionService;
		$this->exportService = $exportService;
		$this->notificationService = $notificationService;
		$this->userSession = $userSession;
	}

	/**
	 * @NoAdminRequired
	 */
	public function index(): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		$showPastAppointments = $this->request->getParam('showPastAppointments', 'false') === 'true';
		$appointments = $this->appointmentService->getAppointmentsWithUserResponses($user->getUID(), $showPastAppointments);

		// Add checkin summary to each appointment if user can see response overview
		if ($this->permissionService->canSeeResponseOverview($user->getUID())) {
			foreach ($appointments as &$appointment) {
				$appointment['checkinSummary'] = $this->checkinService->getCheckinSummary($appointment['id']);
			}
		}

		return new DataResponse($appointments);
	}

	/**
	 * Get minimal appointment data for navigation menu
	 * @NoAdminRequired
	 */
	public function navigation(): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		$appointments = $this->appointmentService->getAppointmentsForNavigation($user->getUID());
		return new DataResponse($appointments);
	}

	/**
	 * @NoAdminRequired
	 */
	public function create(
		string $name,
		string $description,
		string $startDatetime,
		string $endDatetime,
		array $visibleUsers = [],
		array $visibleGroups = [],
		array $visibleTeams = [],
		bool $sendNotification = false,
		?string $calendarUri = null,
		?string $calendarEventUid = null,
		array $attachments = [],
	): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user can manage appointments
		if (!$this->permissionService->canManageAppointments($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions to create appointments'], 403);
		}

		try {
			$appointment = $this->appointmentService->createAppointment(
				$name,
				$description,
				$startDatetime,
				$endDatetime,
				$user->getUID(),
				$visibleUsers,
				$visibleGroups,
				$visibleTeams,
				$sendNotification,
				$calendarUri,
				$calendarEventUid
			);

			$this->addAttachmentsToAppointment($appointment->getId(), $attachments, $user->getUID());

			return new DataResponse($appointment);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Bulk create appointments (calendar import or recurring creation)
	 * @NoAdminRequired
	 */
	public function bulkCreate(array $appointments, bool $sendNotification = false, array $attachments = []): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		if (!$this->permissionService->canManageAppointments($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions to create appointments'], 403);
		}

		$createdIds = [];
		$errors = [];
		$firstAppointment = null;

		foreach ($appointments as $index => $data) {
			try {
				$appointment = $this->appointmentService->createAppointment(
					$data['name'] ?? '',
					$data['description'] ?? '',
					$data['startDatetime'] ?? '',
					$data['endDatetime'] ?? '',
					$user->getUID(),
					$data['visibleUsers'] ?? [],
					$data['visibleGroups'] ?? [],
					$data['visibleTeams'] ?? [],
					false,
					$data['calendarUri'] ?? null,
					$data['calendarEventUid'] ?? null,
				);
				$createdIds[] = $appointment->getId();
				if ($firstAppointment === null) {
					$firstAppointment = $appointment;
				}
			} catch (\Exception $e) {
				$errors[] = [
					'index' => $index,
					'name' => $data['name'] ?? '',
					'error' => $e->getMessage(),
				];
			}
		}

		// Add attachments to all created appointments
		if (!empty($attachments) && !empty($createdIds)) {
			foreach ($createdIds as $appointmentId) {
				$this->addAttachmentsToAppointment($appointmentId, $attachments, $user->getUID());
			}
		}

		// Send a single batch notification for all created appointments
		if ($sendNotification && $firstAppointment !== null && count($createdIds) > 0) {
			$affectedUsers = $this->appointmentService->getAffectedUsers($firstAppointment);
			$affectedUsers = array_filter($affectedUsers, fn ($userId) => $userId !== $user->getUID());
			$this->notificationService->sendBulkAppointmentNotifications(
				count($createdIds),
				$firstAppointment->getName(),
				array_values($affectedUsers),
			);
		}

		return new DataResponse([
			'created' => $createdIds,
			'errors' => $errors,
		]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function update(
		int $id,
		string $name,
		string $description,
		string $startDatetime,
		string $endDatetime,
		array $visibleUsers = [],
		array $visibleGroups = [],
		array $visibleTeams = [],
		array $attachments = [],
	): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user can manage appointments or is creator
		try {
			$appointment = $this->appointmentService->getAppointment($id);
			if (!$this->permissionService->canManageAppointments($user->getUID()) && $appointment->getCreatedBy() !== $user->getUID()) {
				return new DataResponse(['error' => 'Insufficient permissions to update appointments'], 403);
			}
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Appointment not found'], 404);
		}

		try {
			$appointment = $this->appointmentService->updateAppointment(
				$id,
				$name,
				$description,
				$startDatetime,
				$endDatetime,
				$user->getUID(),
				$visibleUsers,
				$visibleGroups,
				$visibleTeams
			);

			$this->syncAttachments($id, $attachments, $user->getUID());

			return new DataResponse($appointment);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Get a single appointment with user response
	 * @NoAdminRequired
	 */
	public function show(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		try {
			$appointment = $this->appointmentService->getAppointmentWithUserResponse($id, $user->getUID());
			if ($appointment === null) {
				return new DataResponse(['error' => 'Appointment not found or not visible'], 404);
			}

			// Add checkin summary if user can see response overview
			if ($this->permissionService->canSeeResponseOverview($user->getUID())) {
				$appointment['checkinSummary'] = $this->checkinService->getCheckinSummary($id);
			}

			return new DataResponse($appointment);
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Appointment not found'], 404);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function destroy(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user can manage appointments or is creator
		try {
			$appointment = $this->appointmentService->getAppointment($id);
			if (!$this->permissionService->canManageAppointments($user->getUID()) && $appointment->getCreatedBy() !== $user->getUID()) {
				return new DataResponse(['error' => 'Insufficient permissions to delete appointments'], 403);
			}
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Appointment not found'], 404);
		}

		try {
			$this->appointmentService->deleteAppointment($id, $user->getUID());
			return new DataResponse(['success' => true]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Get detailed responses for an appointment (admin only)
	 * @NoAdminRequired
	 */
	public function getResponses(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user can manage appointments
		if (!$this->permissionService->canManageAppointments($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions to view detailed responses'], 403);
		}

		try {
			$responses = $this->appointmentService->getAppointmentResponsesWithUsers($id, $user->getUID());
			return new DataResponse($responses);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 403);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function respond(int $id, string $response, string $comment = ''): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		try {
			$attendanceResponse = $this->appointmentService->submitResponse(
				$id,
				$user->getUID(),
				$response,
				$comment
			);
			return new DataResponse($attendanceResponse);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Get upcoming appointments for dashboard widget
	 * @NoAdminRequired
	 */
	public function widget(): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		try {
			$appointments = $this->appointmentService->getUpcomingAppointmentsForWidget($user->getUID(), 5);
			return new DataResponse($appointments);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Set Checkin for a user (admin only)
	 *
	 * @NoAdminRequired
	 */
	public function checkinResponse(int $appointmentId, string $targetUserId): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user can do checkins
		if (!$this->permissionService->canCheckin($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions to checkin responses'], 403);
		}

		$response = $this->request->getParam('response');
		$comment = $this->request->getParam('comment');

		try {
			$result = $this->checkinService->checkinResponse(
				$appointmentId,
				$targetUserId,
				$response,
				$comment,
				$user->getUID()
			);

			return new DataResponse($result);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function getPermissions(): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		return new DataResponse([
			'canManageAppointments' => $this->permissionService->canManageAppointments($user->getUID()),
			'canCheckin' => $this->permissionService->canCheckin($user->getUID()),
			'canSeeResponseOverview' => $this->permissionService->canSeeResponseOverview($user->getUID()),
			'canSeeComments' => $this->permissionService->canSeeComments($user->getUID()),
			'calendarAvailable' => $this->calendarService->isCalendarAvailable(),
			'calendarSyncEnabled' => $this->configService->isCalendarSyncEnabled(),
			'displayOrder' => $this->configService->getDisplayOrder(),
		]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function getCheckinData(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user can do checkins
		if (!$this->permissionService->canCheckin($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions to access check-in data'], 403);
		}

		try {
			$checkinData = $this->checkinService->getCheckinData($id);
			return new DataResponse($checkinData);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Export appointments to ODS file with optional filtering
	 * @NoAdminRequired
	 */
	public function export(): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user can manage appointments
		if (!$this->permissionService->canManageAppointments($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions to export appointments'], 403);
		}

		// Get filter parameters
		$appointmentIds = $this->request->getParam('appointmentIds'); // array or null
		$startDate = $this->request->getParam('startDate'); // Y-m-d format or null
		$endDate = $this->request->getParam('endDate'); // Y-m-d format or null
		$includeComments = filter_var($this->request->getParam('includeComments', false), FILTER_VALIDATE_BOOLEAN);

		// Validate appointmentIds is array if provided
		if ($appointmentIds !== null && !is_array($appointmentIds)) {
			return new DataResponse(['error' => 'appointmentIds must be an array'], 400);
		}

		// Validate date formats if provided
		if ($startDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
			return new DataResponse(['error' => 'startDate must be in Y-m-d format'], 400);
		}
		if ($endDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
			return new DataResponse(['error' => 'endDate must be in Y-m-d format'], 400);
		}

		// Validate endDate is after startDate
		if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
			return new DataResponse(['error' => 'endDate must be after startDate'], 400);
		}

		try {
			$result = $this->exportService->exportToOds($user->getUID(), $appointmentIds, $startDate, $endDate, $includeComments);
			return new DataResponse([
				'success' => true,
				'path' => $result['path'],
				'filename' => $result['filename'],
			]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Search for users, groups, and teams
	 * @NoAdminRequired
	 */
	public function searchUsersGroupsTeams(string $search = ''): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user can manage appointments
		if (!$this->permissionService->canManageAppointments($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions'], 403);
		}

		try {
			$results = $this->appointmentService->searchUsersGroupsTeams($search);
			return new DataResponse($results);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Add attachments to an appointment by file IDs.
	 */
	private function addAttachmentsToAppointment(int $appointmentId, array $fileIds, string $userId): void {
		foreach ($fileIds as $fileId) {
			$this->attachmentService->addAttachment($appointmentId, (int)$fileId, $userId);
		}
	}

	/**
	 * Sync attachments: add new ones, remove ones no longer in the list.
	 */
	private function syncAttachments(int $appointmentId, array $fileIds, string $userId): void {
		$existing = $this->attachmentService->getAttachments($appointmentId);
		$existingFileIds = array_map(fn ($a) => $a['fileId'], $existing);
		$desiredFileIds = array_map('intval', $fileIds);

		// Remove attachments no longer in the list
		foreach ($existingFileIds as $existingFileId) {
			if (!in_array($existingFileId, $desiredFileIds, true)) {
				$this->attachmentService->removeAttachment($appointmentId, $existingFileId);
			}
		}

		// Add new attachments
		foreach ($desiredFileIds as $fileId) {
			if (!in_array($fileId, $existingFileIds, true)) {
				$this->attachmentService->addAttachment($appointmentId, $fileId, $userId);
			}
		}
	}

}
