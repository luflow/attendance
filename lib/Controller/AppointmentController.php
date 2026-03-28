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
use OCA\Attendance\Service\VisibilityService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

class AppointmentController extends Controller {
	private AppointmentService $appointmentService;
	private AttachmentService $attachmentService;
	private CalendarService $calendarService;
	private CheckinService $checkinService;
	private ConfigService $configService;
	private PermissionService $permissionService;
	private ExportService $exportService;
	private NotificationService $notificationService;
	private VisibilityService $visibilityService;
	private IAppManager $appManager;
	private IUserSession $userSession;
	private ISecureRandom $secureRandom;

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
		VisibilityService $visibilityService,
		IAppManager $appManager,
		IUserSession $userSession,
		ISecureRandom $secureRandom,
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
		$this->visibilityService = $visibilityService;
		$this->appManager = $appManager;
		$this->userSession = $userSession;
		$this->secureRandom = $secureRandom;
	}

	/**
	 * List appointments visible to the current user
	 *
	 * @param bool $showPastAppointments Whether to show past appointments instead of upcoming ones
	 * @return DataResponse<Http::STATUS_OK, list<AttendanceAppointmentWithResponse>, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function index(bool $showPastAppointments = false): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

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
	 *
	 * @return DataResponse<Http::STATUS_OK, array{current: list<AttendanceNavigationAppointment>, past: list<AttendanceNavigationAppointment>}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function navigation(): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		$appointments = $this->appointmentService->getAppointmentsForNavigation($user->getUID());
		return new DataResponse($appointments);
	}

	/**
	 * Create a new appointment
	 *
	 * @param string $name Appointment name
	 * @param string $description Appointment description
	 * @param string $startDatetime Start date and time (ISO 8601)
	 * @param string $endDatetime End date and time (ISO 8601)
	 * @param list<string> $visibleUsers User IDs to make the appointment visible to
	 * @param list<string> $visibleGroups Group IDs to make the appointment visible to
	 * @param list<string> $visibleTeams Team IDs to make the appointment visible to
	 * @param bool $sendNotification Whether to send notifications to visible users
	 * @param ?string $calendarUri URI of the source calendar (when imported from calendar)
	 * @param ?string $calendarEventUid UID of the source calendar event
	 * @param list<int> $attachments File IDs to attach to the appointment
	 * @return DataResponse<Http::STATUS_CREATED, AttendanceAppointmentData, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
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

			return new DataResponse($appointment, Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Bulk create appointments (calendar import or recurring creation)
	 *
	 * @param list<AttendanceBulkAppointmentItem> $appointments List of appointments to create
	 * @param bool $sendNotification Whether to send a batch notification to affected users
	 * @param list<int> $attachments File IDs to attach to all created appointments
	 * @return DataResponse<Http::STATUS_CREATED, array{created: list<int>, errors: list<array{index: int, name: string, error: string}>}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
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

		// Generate a series ID to link all appointments in this bulk create
		$seriesId = $this->secureRandom->generate(36);

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
					$seriesId,
					$index,
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
		], Http::STATUS_CREATED);
	}

	/**
	 * Update an existing appointment
	 *
	 * @param int $id Appointment ID
	 * @param string $name Appointment name
	 * @param string $description Appointment description
	 * @param string $startDatetime Start date and time (ISO 8601)
	 * @param string $endDatetime End date and time (ISO 8601)
	 * @param list<string> $visibleUsers User IDs to make the appointment visible to
	 * @param list<string> $visibleGroups Group IDs to make the appointment visible to
	 * @param list<string> $visibleTeams Team IDs to make the appointment visible to
	 * @param list<int> $attachments File IDs to attach to the appointment
	 * @param string $scope Series update scope: single, future, or all
	 * @return DataResponse<Http::STATUS_OK, AttendanceAppointmentData|list<AttendanceAppointmentData>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
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
		string $scope = 'single',
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
			if ($scope === 'future' || $scope === 'all') {
				$updatedAppointments = $this->appointmentService->updateSeriesAppointments(
					$id, $scope, $name, $description, $startDatetime, $endDatetime,
					$user->getUID(), $visibleUsers, $visibleGroups, $visibleTeams
				);

				// Sync attachments across all affected appointments
				foreach ($updatedAppointments as $updated) {
					$this->syncAttachments($updated->getId(), $attachments, $user->getUID());
				}

				return new DataResponse($updatedAppointments);
			}

			// scope === 'single': detach from series if part of one, then update normally
			if ($appointment->getSeriesId() !== null) {
				$updatedAppointments = $this->appointmentService->updateSeriesAppointments(
					$id, 'single', $name, $description, $startDatetime, $endDatetime,
					$user->getUID(), $visibleUsers, $visibleGroups, $visibleTeams
				);
				$this->syncAttachments($id, $attachments, $user->getUID());
				return new DataResponse($updatedAppointments[0]);
			}

			$appointment = $this->appointmentService->updateAppointment(
				$id, $name, $description, $startDatetime, $endDatetime,
				$user->getUID(), $visibleUsers, $visibleGroups, $visibleTeams
			);

			$this->syncAttachments($id, $attachments, $user->getUID());

			return new DataResponse($appointment);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Get a single appointment with user response
	 *
	 * @param int $id Appointment ID
	 * @return DataResponse<Http::STATUS_OK, AttendanceAppointmentWithResponse, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
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
	 * Delete an appointment
	 *
	 * @param int $id Appointment ID
	 * @param string $scope Series delete scope: single, future, or all
	 * @return DataResponse<Http::STATUS_OK, AttendanceDeleteResult, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function destroy(int $id, string $scope = 'single'): DataResponse {
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
			if (($scope === 'future' || $scope === 'all') && $appointment->getSeriesId() !== null) {
				$deletedCount = $this->appointmentService->deleteSeriesAppointments($id, $scope, $user->getUID());
				return new DataResponse(['deletedCount' => $deletedCount]);
			}

			if ($scope === 'single' && $appointment->getSeriesId() !== null) {
				$deletedCount = $this->appointmentService->deleteSeriesAppointments($id, 'single', $user->getUID());
				return new DataResponse(['deletedCount' => $deletedCount]);
			}

			$this->appointmentService->deleteAppointment($id, $user->getUID());
			return new DataResponse(['deletedCount' => 1]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Get detailed responses for an appointment (requires manage appointments permission)
	 *
	 * @param int $id Appointment ID
	 * @return DataResponse<Http::STATUS_OK, list<AttendanceResponseWithUser>, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
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
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Submit the current user's response to an appointment
	 *
	 * @param int $id Appointment ID
	 * @param string $response Response value: yes, no, or maybe
	 * @param string $comment Optional comment
	 * @return DataResponse<Http::STATUS_OK, AttendanceResponseData, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
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
	 *
	 * @return DataResponse<Http::STATUS_OK, list<AttendanceAppointmentWithResponse>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
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
	 * Set check-in status for a user (requires check-in permission)
	 *
	 * @param int $appointmentId Appointment ID
	 * @param string $targetUserId User ID to check in
	 * @param ?string $response Check-in response: yes, no, or null to keep current state
	 * @param string $comment Optional check-in comment
	 * @return DataResponse<Http::STATUS_OK, AttendanceResponseData, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function checkinResponse(int $appointmentId, string $targetUserId, ?string $response = null, string $comment = ''): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user can do checkins
		if (!$this->permissionService->canCheckin($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions to checkin responses'], 403);
		}

		// Verify appointment exists
		try {
			$this->appointmentService->getAppointment($appointmentId);
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Appointment not found'], 404);
		}

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
	 * Reset all check-in data for an appointment
	 *
	 * @param int $id Appointment ID
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function resetCheckin(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		if (!$this->permissionService->canCheckin($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions to reset check-in data'], 403);
		}

		// Verify appointment exists
		try {
			$this->appointmentService->getAppointment($id);
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Appointment not found'], 404);
		}

		try {
			$this->checkinService->resetCheckin($id);
			return new DataResponse([]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Get the current user's permissions
	 *
	 * @return DataResponse<Http::STATUS_OK, AttendanceUserPermissions, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
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
			'canSelfCheckin' => $this->permissionService->canSelfCheckin($user->getUID()),
		]);
	}

	/**
	 * Get system-wide capabilities
	 *
	 * @return DataResponse<Http::STATUS_OK, AttendanceCapabilities, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function getCapabilities(): DataResponse {
		return new DataResponse([
			'calendarAvailable' => $this->calendarService->isCalendarAvailable(),
			'calendarSyncEnabled' => $this->configService->isCalendarSyncEnabled(),
			'teamsAvailable' => $this->visibilityService->isTeamsAvailable(),
			'calendarSyncAvailable' => \OCP\Util::getVersion()[0] >= 32,
			'notificationsAppEnabled' => $this->appManager->isEnabledForUser('notifications'),
		]);
	}

	/**
	 * Get push notification configuration
	 *
	 * Returns the push proxy server URL for mobile clients.
	 *
	 * @return DataResponse<Http::STATUS_OK, AttendancePushConfig, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function getPushConfig(): DataResponse {
		return new DataResponse([
			'enabled' => $this->configService->isPushEnabled(),
			'proxyServer' => $this->configService->getPushProxyServer(),
		]);
	}

	/**
	 * Get user-relevant app configuration
	 *
	 * @return DataResponse<Http::STATUS_OK, AttendanceUserConfig, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function getUserConfig(): DataResponse {
		return new DataResponse([
			'displayOrder' => $this->configService->getDisplayOrder(),
		]);
	}

	/**
	 * Get check-in data for an appointment
	 *
	 * @param int $id Appointment ID
	 * @return DataResponse<Http::STATUS_OK, AttendanceCheckinData, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
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
	 *
	 * @param ?list<int> $appointmentIds Specific appointment IDs to export, or null for all
	 * @param ?string $startDate Start date filter in Y-m-d format
	 * @param ?string $endDate End date filter in Y-m-d format
	 * @param bool $includeComments Whether to include user comments in the export
	 * @return DataResponse<Http::STATUS_OK, AttendanceExportResult, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function export(?array $appointmentIds = null, ?string $startDate = null, ?string $endDate = null, bool $includeComments = false): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user can manage appointments
		if (!$this->permissionService->canManageAppointments($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions to export appointments'], 403);
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
				'path' => $result['path'],
				'filename' => $result['filename'],
			]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Search for users, groups, and teams
	 *
	 * @param string $search Search query
	 * @return DataResponse<Http::STATUS_OK, list<array{id: string, label: string, type: string, icon: string}>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
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
