<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Service\AppointmentListQuery;
use OCA\Attendance\Service\AppointmentService;
use OCA\Attendance\Service\AttachmentService;
use OCA\Attendance\Service\BookingService;
use OCA\Attendance\Service\CalendarService;
use OCA\Attendance\Service\CheckinService;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\DeadlineUpdate;
use OCA\Attendance\Service\ExportOptions;
use OCA\Attendance\Service\ExportService;
use OCA\Attendance\Service\GuestService;
use OCA\Attendance\Service\NotificationService;
use OCA\Attendance\Service\PermissionService;
use OCA\Attendance\Service\TalkRoomService;
use OCA\Attendance\Service\VisibilityService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

/**
 * @psalm-import-type AttendanceAppointmentPage from \OCA\Attendance\ResponseDefinitions
 * @psalm-import-type AttendanceExportSeries from \OCA\Attendance\ResponseDefinitions
 */
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
	private GuestService $guestService;
	private BookingService $bookingService;
	private TalkRoomService $talkRoomService;

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
		GuestService $guestService,
		BookingService $bookingService,
		TalkRoomService $talkRoomService,
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
		$this->guestService = $guestService;
		$this->bookingService = $bookingService;
		$this->talkRoomService = $talkRoomService;
	}

	/**
	 * Load an appointment and authorize the current user to manage it
	 * (global manager or organizer of this appointment). Returns the error
	 * DataResponse to send when loading or authorization fails, the
	 * Appointment otherwise.
	 */
	private function findManageableAppointment(int $id, string $userId, string $permissionError): \OCA\Attendance\Db\Appointment|DataResponse {
		try {
			$appointment = $this->appointmentService->getAppointment($id);
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Appointment not found'], 404);
		}
		if (!$this->permissionService->canManageAppointment($userId, $appointment)) {
			return new DataResponse(['error' => $permissionError], 403);
		}
		return $appointment;
	}

	/**
	 * List appointments visible to the current user
	 *
	 * Without $limit the whole list is returned as a plain array. With it the
	 * list is paged and wrapped in an object that also carries the total, the
	 * unanswered count and the values the filters can offer.
	 *
	 * @param bool $showPastAppointments Whether to show past appointments instead of upcoming ones. Superseded by $timeframe when that is given.
	 * @param bool $unansweredOnly When true, only return upcoming appointments that the user has not answered yet AND that are still open. Ignored unless the upcoming appointments are listed.
	 * @param bool $onlyForMe When true, restrict the result to the user's own appointments: the ones they are part of the target audience for (visibleUsers/Groups/Teams membership; appointments with no visibility restriction count as "for everyone" and are included) plus the ones they organize. This is the "My appointments" view; without it, holders of see_all_appointments get every appointment on the instance.
	 * @param bool $notScheduledOut When true, additionally drop closed appointments where somebody was scheduled but the user was not. Only has an effect while the planning feature is enabled; appointments nobody was scheduled for stay visible to everyone. Implies $onlyForMe.
	 * @param bool $onlyScheduled When true, return only appointments the user has been given a place in. Only has an effect while the planning feature is enabled. Open inquiries count as soon as a manager booked the user — being booked is an explicit act, unlike not being booked. Implies $onlyForMe.
	 * @param ?string $timeframe Which appointments to list: `upcoming` (soonest first), `past` (most recent first) or `all` (upcoming, then past)
	 * @param string $search Keep only appointments whose name or description contains this text
	 * @param ?string $status Keep only appointments whose inquiry is `open`, `closed` or `cancelled`
	 * @param ?string $response Keep only appointments the user answered `yes`, `maybe` or `no` — or not at all (`none`)
	 * @param ?string $role Keep only appointments the user is an `attendee` of, an `organizer` of, or neither (`uninvolved`). Ignored unless the role tells some of the listed appointments apart from others.
	 * @param list<string> $locations Keep only appointments at one of these locations
	 * @param list<int> $categoryIds Keep only appointments in one of these categories
	 * @param bool $cancelledLast List cancelled appointments behind all others instead of by date among them
	 * @param int<1, 100>|null $limit Page size. Switches the response to the paged object.
	 * @param int<0, max> $offset Number of matching appointments to skip
	 * @return DataResponse<Http::STATUS_OK, list<AttendanceAppointmentWithResponse>|AttendanceAppointmentPage, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function index(
		bool $showPastAppointments = false,
		bool $unansweredOnly = false,
		bool $onlyForMe = false,
		bool $notScheduledOut = false,
		bool $onlyScheduled = false,
		?string $timeframe = null,
		string $search = '',
		?string $status = null,
		?string $response = null,
		?string $role = null,
		array $locations = [],
		array $categoryIds = [],
		bool $cancelledLast = false,
		?int $limit = null,
		int $offset = 0,
	): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Gate the response overview and free-text comments server-side. The
		// frontend flags alone are advisory — without this an audience member
		// would receive every attendee's answers and comments over the wire.
		// Comments only surface inside the overview, so skip resolving that
		// permission when the overview itself is withheld.
		$canSeeResponseOverview = $this->permissionService->canSeeResponseOverview($user->getUID());
		$canSeeComments = $canSeeResponseOverview && $this->permissionService->canSeeComments($user->getUID());
		$canSeeResponseCounts = $this->permissionService->canSeeResponseCounts($user->getUID());

		$query = AppointmentListQuery::fromWire(
			$timeframe ?? ($showPastAppointments ? AppointmentListQuery::TIMEFRAME_PAST : AppointmentListQuery::TIMEFRAME_UPCOMING),
			$unansweredOnly,
			$onlyForMe,
			$notScheduledOut,
			$onlyScheduled,
			$search,
			$status,
			$response,
			$role,
			$locations,
			$categoryIds,
			$cancelledLast,
			$limit,
			$offset,
		);

		$page = $this->appointmentService->getAppointmentPage(
			$user->getUID(),
			$query,
			$canSeeResponseOverview,
			$canSeeComments,
			$canSeeResponseCounts,
		);

		// Add checkin summary to each appointment the user may see at least
		// the aggregate numbers for — it only ever carries counts, no names.
		foreach ($page['appointments'] as &$appointment) {
			if ($appointment['myPermissions']['canSeeResponseCounts']) {
				$appointment['checkinSummary'] = $this->checkinService->getCheckinSummary($appointment['id']);
			}
		}
		unset($appointment);

		return new DataResponse($query->isPaged() ? $page : $page['appointments']);
	}

	/**
	 * Get minimal appointment data for navigation menu
	 *
	 * @param int<0, 100>|null $pastLimit Page size for the past entries. Without it all of them are returned.
	 * @param int<0, max> $pastOffset Number of past entries to skip
	 * @return DataResponse<Http::STATUS_OK, array{current: list<AttendanceNavigationAppointment>, past: list<AttendanceNavigationAppointment>, pastHasMore: bool}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function navigation(?int $pastLimit = null, int $pastOffset = 0): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		$appointments = $this->appointmentService->getAppointmentsForNavigation($user->getUID(), $pastLimit, $pastOffset);
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
	 * @param ?string $responseDeadline Optional response deadline (ISO 8601). Cron auto-closes the inquiry once passed.
	 * @param list<string> $organizers User IDs to set as organizers; empty defaults to the creator
	 * @param ?string $location Free-text location
	 * @param ?int $categoryId Category ID
	 * @param bool $createTalkRoom Open a Talk conversation with the scheduled people when the inquiry closes
	 * @param ?bool $allowMaybe Offer "Maybe" as an answer; null follows the instance-wide default
	 * @param ?int $maxAttendees Attendance limit; null or zero means no limit
	 * @param bool $waitlistEnabled Whether a full appointment offers a place in line
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
		?string $responseDeadline = null,
		array $organizers = [],
		?string $location = null,
		?int $categoryId = null,
		bool $createTalkRoom = false,
		?bool $allowMaybe = null,
		?int $maxAttendees = null,
		bool $waitlistEnabled = true,
	): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Managers or members of the create_appointments groups may create;
		// the creator becomes organizer of the new appointment.
		if (!$this->permissionService->canCreateAppointments($user->getUID())) {
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
				$calendarEventUid,
				null,
				null,
				$responseDeadline,
				$organizers === [] ? null : $organizers,
				$location,
				$categoryId,
				$createTalkRoom,
				$allowMaybe,
				$maxAttendees,
				$waitlistEnabled,
			);

			$this->addAttachmentsToAppointment($appointment->getId(), $attachments, $user->getUID());

			return new DataResponse($this->appointmentService->serializeAppointment($appointment), Http::STATUS_CREATED);
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
	 * @param list<string> $organizers User IDs to set as organizers on all created appointments; empty defaults to the creator
	 * @return DataResponse<Http::STATUS_CREATED, array{created: list<int>, errors: list<array{index: int, name: string, error: string}>}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function bulkCreate(array $appointments, bool $sendNotification = false, array $attachments = [], array $organizers = []): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		if (!$this->permissionService->canCreateAppointments($user->getUID())) {
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
					$data['responseDeadline'] ?? null,
					$organizers === [] ? null : $organizers,
					$data['location'] ?? null,
					$data['categoryId'] ?? null,
					false,
					$data['allowMaybe'] ?? null,
					$data['maxAttendees'] ?? null,
					$data['waitlistEnabled'] ?? true,
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
			$this->notificationService->sendBulkAppointmentNotifications(
				count($createdIds),
				$firstAppointment->getName(),
				$this->appointmentService->recipientsWithout(
					$this->appointmentService->getAffectedUsers($firstAppointment),
					$user->getUID(),
				),
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
	 * @param ?string $responseDeadline Optional response deadline (ISO 8601). Empty string clears it; null leaves unchanged.
	 * @param ?list<string> $organizers New organizer list, or null to leave unchanged. Organizers may add anyone but remove only themselves; managers may change freely.
	 * @param ?string $location Free-text location, applied identically to every affected sibling when scope is future/all
	 * @param ?int $categoryId Category, applied identically to every affected sibling when scope is future/all
	 * @param ?bool $createTalkRoom Open a Talk conversation when the inquiry closes, or null to leave unchanged
	 * @param ?bool $allowMaybe Offer "Maybe" as an answer, or null to leave unchanged; applied identically to every affected sibling when scope is future/all
	 * @param ?int $maxAttendees Attendance limit; null or zero clears it, applied identically to every affected sibling when scope is future/all
	 * @param ?bool $waitlistEnabled Whether a full appointment offers a place in line, or null to leave unchanged
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
		?string $responseDeadline = null,
		?array $organizers = null,
		?string $location = null,
		?int $categoryId = null,
		?bool $createTalkRoom = null,
		?bool $allowMaybe = null,
		?int $maxAttendees = null,
		?bool $waitlistEnabled = null,
	): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		$appointment = $this->findManageableAppointment($id, $user->getUID(), 'Insufficient permissions to update appointments');
		if ($appointment instanceof DataResponse) {
			return $appointment;
		}

		try {
			$deadlineUpdate = DeadlineUpdate::fromWire($responseDeadline);

			if ($scope === 'future' || $scope === 'all') {
				$updatedAppointments = $this->appointmentService->updateSeriesAppointments(
					$id, $scope, $name, $description, $startDatetime, $endDatetime,
					$user->getUID(), $visibleUsers, $visibleGroups, $visibleTeams,
					$deadlineUpdate, $organizers, $location, $categoryId, $createTalkRoom,
					$allowMaybe, $maxAttendees, $waitlistEnabled,
				);

				// Sync attachments across all affected appointments
				foreach ($updatedAppointments as $updated) {
					$this->syncAttachments($updated->getId(), $attachments, $user->getUID());
				}

				return new DataResponse(array_map(
					fn ($updated) => $this->appointmentService->serializeAppointment($updated),
					$updatedAppointments,
				));
			}

			// scope === 'single': detach from series if part of one, then update normally
			if ($appointment->getSeriesId() !== null) {
				$updatedAppointments = $this->appointmentService->updateSeriesAppointments(
					$id, 'single', $name, $description, $startDatetime, $endDatetime,
					$user->getUID(), $visibleUsers, $visibleGroups, $visibleTeams,
					$deadlineUpdate, $organizers, $location, $categoryId, $createTalkRoom,
					$allowMaybe, $maxAttendees, $waitlistEnabled,
				);
				$this->syncAttachments($id, $attachments, $user->getUID());
				return new DataResponse($this->appointmentService->serializeAppointment($updatedAppointments[0]));
			}

			$appointment = $this->appointmentService->updateAppointment(
				$id, $name, $description, $startDatetime, $endDatetime,
				$user->getUID(), $visibleUsers, $visibleGroups, $visibleTeams,
				$deadlineUpdate, $organizers, $location, $categoryId, $createTalkRoom,
				$allowMaybe, $maxAttendees, $waitlistEnabled,
			);

			$this->syncAttachments($id, $attachments, $user->getUID());

			return new DataResponse($this->appointmentService->serializeAppointment($appointment));
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Close an appointment inquiry
	 *
	 * Marks the appointment as closed: no further responses are accepted and
	 * the reminder cron skips it. The appointment stays in the "upcoming" list
	 * with a closed badge; only when its end_datetime passes does it move to
	 * the past list.
	 *
	 * @param int $id Appointment ID
	 * @return DataResponse<Http::STATUS_OK, AttendanceAppointmentData, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function close(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		$appointment = $this->findManageableAppointment($id, $user->getUID(), 'Insufficient permissions to close this appointment');
		if ($appointment instanceof DataResponse) {
			return $appointment;
		}

		$updated = $this->appointmentService->closeAppointment($id);
		return new DataResponse($this->appointmentService->serializeAppointment($updated));
	}

	/**
	 * Re-open a closed appointment inquiry
	 *
	 * @param int $id Appointment ID
	 * @return DataResponse<Http::STATUS_OK, AttendanceAppointmentData, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function reopen(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		$appointment = $this->findManageableAppointment($id, $user->getUID(), 'Insufficient permissions to re-open this appointment');
		if ($appointment instanceof DataResponse) {
			return $appointment;
		}

		$updated = $this->appointmentService->reopenAppointment($id);
		return new DataResponse($this->appointmentService->serializeAppointment($updated));
	}

	/**
	 * Open a Talk conversation with the scheduled people
	 *
	 * Creates a group conversation holding the organisers and everyone who got
	 * a place, and links it to the appointment. Idempotent: an appointment that
	 * already has a live conversation gets its participants reconciled instead
	 * of a second room.
	 *
	 * The counterpart to the createTalkRoom opt-in, for inquiries that were
	 * closed without it — including those the deadline closed.
	 *
	 * @param int $id Appointment ID
	 * @return DataResponse<Http::STATUS_OK, AttendanceAppointmentData, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function createTalkRoom(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		$appointment = $this->findManageableAppointment($id, $user->getUID(), 'Insufficient permissions to open a conversation for this appointment');
		if ($appointment instanceof DataResponse) {
			return $appointment;
		}

		if (!$this->talkRoomService->isAvailable()) {
			return new DataResponse(['error' => 'Talk is not available on this server'], 400);
		}

		// Enforced here and not just in the UI, because mobile clients call this
		// endpoint directly. With planning on, who holds a place is only settled
		// when the inquiry closes.
		if (!$this->talkRoomService->mayOpenRoom($appointment)) {
			return new DataResponse(['error' => 'The inquiry is still open'], 400);
		}

		if ($this->talkRoomService->createForAppointment($appointment) === null) {
			return new DataResponse(['error' => 'The conversation could not be created'], 400);
		}

		return new DataResponse($this->appointmentService->getAppointment($id));
	}

	/**
	 * Delete the Talk conversation of an appointment
	 *
	 * Deletes the linked conversation in Talk, messages and all, and drops the
	 * createTalkRoom opt-in with it so the next answer does not open a new one.
	 *
	 * @param int $id Appointment ID
	 * @return DataResponse<Http::STATUS_OK, AttendanceAppointmentData, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function deleteTalkRoom(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		$appointment = $this->findManageableAppointment($id, $user->getUID(), 'Insufficient permissions to delete the conversation of this appointment');
		if ($appointment instanceof DataResponse) {
			return $appointment;
		}

		if (!$this->talkRoomService->isAvailable()) {
			return new DataResponse(['error' => 'Talk is not available on this server'], 400);
		}

		if (!$this->talkRoomService->deleteForAppointment($appointment)) {
			return new DataResponse(['error' => 'The conversation could not be deleted'], 400);
		}

		return new DataResponse($this->appointmentService->getAppointment($id));
	}

	/**
	 * Cancel an appointment
	 *
	 * Marks the appointment as cancelled (the event will not take place). All
	 * data/notes are kept, but the appointment drops out of the active lists,
	 * gets no reminders and is not auto-closed. Addressed attendees are notified
	 * and the calendar feed reflects the cancellation.
	 *
	 * @param int $id Appointment ID
	 * @return DataResponse<Http::STATUS_OK, AttendanceAppointmentData, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function cancel(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		$appointment = $this->findManageableAppointment($id, $user->getUID(), 'Insufficient permissions to cancel this appointment');
		if ($appointment instanceof DataResponse) {
			return $appointment;
		}

		$updated = $this->appointmentService->cancelAppointment($id, $user->getUID());
		return new DataResponse($this->appointmentService->serializeAppointment($updated));
	}

	/**
	 * Reactivate a cancelled appointment
	 *
	 * @param int $id Appointment ID
	 * @return DataResponse<Http::STATUS_OK, AttendanceAppointmentData, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function uncancel(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		$appointment = $this->findManageableAppointment($id, $user->getUID(), 'Insufficient permissions to reactivate this appointment');
		if ($appointment instanceof DataResponse) {
			return $appointment;
		}

		$updated = $this->appointmentService->uncancelAppointment($id, $user->getUID());
		return new DataResponse($this->appointmentService->serializeAppointment($updated));
	}

	/**
	 * Mark a yes-responder as booked (planned in) for an appointment
	 *
	 * Requires the booking feature to be enabled instance-wide and the caller to
	 * be a manager or the appointment's creator.
	 *
	 * @param int $id Appointment ID
	 * @param string $userId The responder to book
	 * @return DataResponse<Http::STATUS_OK, AttendanceResponseData, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function book(int $id, string $userId): DataResponse {
		return $this->setBooking($id, $userId, true);
	}

	/**
	 * Clear a responder's booking for an appointment (back to open)
	 *
	 * @param int $id Appointment ID
	 * @param string $userId The responder to unbook
	 * @return DataResponse<Http::STATUS_OK, AttendanceResponseData, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function unbook(int $id, string $userId): DataResponse {
		return $this->setBooking($id, $userId, false);
	}

	/**
	 * Shared book/unbook implementation: permission + feature gating, then
	 * delegate to BookingService.
	 */
	private function setBooking(int $id, string $userId, bool $book): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		if (!$this->bookingService->isEnabled()) {
			return new DataResponse(['error' => 'Booking is not enabled'], 400);
		}

		$appointment = $this->findManageableAppointment($id, $user->getUID(), 'Insufficient permissions to change bookings');
		if ($appointment instanceof DataResponse) {
			return $appointment;
		}

		try {
			$updated = $book
				? $this->bookingService->book($id, $userId)
				: $this->bookingService->unbook($id, $userId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new DataResponse(['error' => 'Response not found'], 404);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}

		return new DataResponse($updated);
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
			// Gate the response overview and free-text comments server-side
			// (see index()). The frontend permission flags are advisory only.
			$canSeeResponseOverview = $this->permissionService->canSeeResponseOverview($user->getUID());
			$canSeeComments = $canSeeResponseOverview && $this->permissionService->canSeeComments($user->getUID());
			$canSeeResponseCounts = $this->permissionService->canSeeResponseCounts($user->getUID());

			$appointment = $this->appointmentService->getAppointmentWithUserResponse(
				$id,
				$user->getUID(),
				$canSeeResponseOverview,
				$canSeeComments,
				$canSeeResponseCounts,
			);
			if ($appointment === null) {
				return new DataResponse(['error' => 'Appointment not found or not visible'], 404);
			}

			// Add checkin summary if user may see at least the aggregate
			// numbers for this appointment — it only ever carries counts.
			if ($appointment['myPermissions']['canSeeResponseCounts']) {
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
		$appointment = $this->findManageableAppointment($id, $user->getUID(), 'Insufficient permissions to delete appointments');
		if ($appointment instanceof DataResponse) {
			return $appointment;
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
	 * Get detailed responses for an appointment (requires manage appointments permission or being an organizer of it)
	 *
	 * @param int $id Appointment ID
	 * @return DataResponse<Http::STATUS_OK, list<AttendanceResponseWithUser>, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function getResponses(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		$appointment = $this->findManageableAppointment($id, $user->getUID(), 'Insufficient permissions to view detailed responses');
		if ($appointment instanceof DataResponse) {
			return $appointment;
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
	 * @param ?string $response Response value: yes, no, maybe — or null to withdraw an existing response
	 * @param string $comment Optional comment
	 * @param bool $acceptWaitlist Take a place in line when the appointment is already full, instead of being turned away
	 * @return DataResponse<Http::STATUS_OK, AttendanceResponseData, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function respond(int $id, ?string $response, string $comment = '', bool $acceptWaitlist = false): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		try {
			$attendanceResponse = $this->appointmentService->submitResponse(
				$id,
				$user->getUID(),
				$response,
				$comment,
				$acceptWaitlist
			);
			return new DataResponse($this->appointmentService->serializeResponse(
				$attendanceResponse,
				$this->appointmentService->getAppointment($id),
			));
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Set or clear a response on behalf of another user (requires the respond-for-others permission)
	 *
	 * @param int $appointmentId Appointment ID
	 * @param string $targetUserId User ID whose response is set
	 * @param ?string $response Response value: yes, no, maybe — or null to clear the response so the person counts as "not yet responded" again
	 * @return DataResponse<Http::STATUS_OK, AttendanceResponseData, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function respondForUser(int $appointmentId, string $targetUserId, ?string $response = null): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Own permission, deliberately not implied by manage/organizer rights —
		// only members of the explicitly configured groups may answer for others.
		if (!$this->permissionService->canRespondForOthers($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions to set responses for other users'], 403);
		}

		try {
			$appointment = $this->appointmentService->getAppointment($appointmentId);
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Appointment not found'], 404);
		}

		try {
			$attendanceResponse = $this->appointmentService->submitResponseForUser(
				$appointment,
				$targetUserId,
				$response,
				$user->getUID()
			);
			return new DataResponse($this->appointmentService->serializeResponse($attendanceResponse, $appointment));
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
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
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
			'canCreateAppointments' => $this->permissionService->canCreateAppointments($user->getUID()),
			'canCheckin' => $this->permissionService->canCheckin($user->getUID()),
			'canSeeResponseOverview' => $this->permissionService->canSeeResponseOverview($user->getUID()),
			'canSeeResponseCounts' => $this->permissionService->canSeeResponseCounts($user->getUID()),
			'canSeeComments' => $this->permissionService->canSeeComments($user->getUID()),
			'canSelfCheckin' => $this->permissionService->canSelfCheckin($user->getUID()),
			'canRespondForOthers' => $this->permissionService->canRespondForOthers($user->getUID()),
			'canSeeStatistics' => $this->permissionService->canSeeStatistics($user->getUID()),
			'canSeeIndividualResponses' => $this->permissionService->canSeeIndividualResponses($user->getUID()),
			'canSeeAllAppointments' => $this->permissionService->canSeeAllAppointments($user->getUID()),
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
			// Always true since the app requires NC 32+; kept for older mobile
			// clients that still gate their calendar sync UI on this flag.
			'calendarSyncAvailable' => true,
			'notificationsAppEnabled' => $this->appManager->isEnabledForUser('notifications'),
			// Older servers omit this flag → mobile clients hide close-inquiry UI.
			'closing' => true,
			'cancelling' => true,
			'bookingEnabled' => $this->configService->isBookingEnabled(),
			// Server understands index(onlyScheduled). Older servers ignore the
			// parameter and would answer with an unfiltered list, so clients
			// must hide the filter rather than send it blind.
			'scheduledFilter' => true,
			'remindMaybe' => true,
			// Server understands the allowMaybe field on an appointment and
			// rejects a "maybe" the appointment does not offer. Clients that do
			// not see this flag keep showing all three buttons.
			'responseOptions' => true,
			// What an appointment offers when it has no opinion of its own.
			// The appointment editor starts its switch here.
			'allowMaybeDefault' => $this->configService->isMaybeAllowed(),
			// Server understands maxAttendees/waitlistEnabled on an appointment,
			// reports occupancy and isFull, and takes acceptWaitlist on respond.
			// Clients without this flag show a plain "Yes" that a full
			// appointment answers with 400.
			'attendanceLimit' => true,
			// Older servers reject response=null. Mobile clients gate the
			// withdraw-response affordance on this flag.
			'responseToggle' => true,
			// True when the Guests app is available, meaning the server can
			// service POST /api/guests so clients may surface an "invite guest"
			// affordance in the appointment editor.
			'guestInvitation' => $this->guestService->isGuestsAppEnabled(),
			// True when the audit log + response-change notifications feature
			// is enabled. Clients hide the timeline tab when this is false.
			'auditLog' => $this->configService->isAuditLogEnabled(),
			// Server supports QR/NFC self-check-in (method param + overview
			// endpoint). Mobile clients hide the scan UI when this is false.
			'selfCheckin' => true,
			// Server supports per-appointment organizers (organizer list,
			// myPermissions payload, create_appointments permission). Mobile
			// clients hide organizer UI when this is false.
			'organizers' => true,
			// Server supports POST /respond/{targetUserId} for setting an
			// answer on behalf of a person (issue #47), gated by the
			// respond_for_others permission. Mobile clients hide the
			// on-behalf picker when this is false or the user lacks the
			// permission (getPermissions.canRespondForOthers).
			'respondOnBehalf' => true,
			// Server understands the see_response_counts permission and sends a
			// counts-only responseSummary (no names) to holders. Clients show
			// the yes/no/maybe bar whenever a summary arrives; on older servers
			// without this flag a summary always carries the full overview.
			'responseCounts' => true,
			// Server supports a free-text location field per appointment plus
			// GET /appointments/locations for suggestions. Mobile clients hide
			// the location field/filter when this is false.
			'locationsAvailable' => true,
			// Server supports admin-managed categories (GET /categories,
			// categoryId on appointments). Mobile clients hide the category
			// picker/badge/filter when this is false.
			'categoriesAvailable' => true,
			// GET /categories carries a template per category (description and
			// access restriction a new appointment starts with). Mobile clients
			// prefill the form from it when this is true.
			'categoryTemplates' => true,
			// Server supports the cross-appointment evaluation (GET
			// /statistics, see_statistics permission). Clients hide the
			// statistics entry point when this is false.
			'statisticsAvailable' => true,
			// True when Talk is installed and reachable, meaning the server can
			// open a conversation for the scheduled people (createTalkRoom on
			// the appointment, POST /appointments/{id}/talk-room). Clients hide
			// the opt-in and the button when this is false.
			'talkRoomsAvailable' => $this->talkRoomService->isAvailable(),
			// Server understands DELETE /appointments/{id}/talk-room. Clients
			// hide the delete action when this is false.
			'talkRoomDeletion' => true,
			// Server knows the see_all_appointments permission, and index(onlyForMe)
			// keeps the appointments the user organizes as well. On older servers
			// that scope drops them, so clients must not label such a view
			// "My appointments" without this flag.
			'seeAllAppointments' => true,
			// Server supports personal vacation periods (GET/POST/PUT/DELETE
			// /vacations), the create-appointment conflict hint, the team
			// overview, and auto-"no" responses for invitees on vacation.
			// Clients hide all of that UI when this is false.
			'vacationManagementEnabled' => true,
			// Server sends myPermissions.isAttendee. Without it every appointment
			// reads as "invited" and only organizing could be told apart, so
			// clients offer the "My role" filter whole or not at all.
			'roleFilter' => true,
			// Server pages and filters GET /appointments (limit/offset, timeframe,
			// search, status, response, role, locations, categoryIds) and pages the past
			// entries of /appointments/navigation. Older servers ignore all of
			// that and answer with the full list, so clients without this flag
			// must keep loading everything and filtering it themselves.
			'pagination' => true,
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
		$bannerEnabled = $this->configService->isMobileAppBannerEnabled();
		$user = $this->userSession->getUser();
		$hasPushDevice = $bannerEnabled && $user !== null
			? $this->notificationService->hasPushDevice($user->getUID())
			: false;

		return new DataResponse([
			'displayOrder' => $this->configService->getDisplayOrder(),
			'mobileAppBannerEnabled' => $bannerEnabled,
			'hasPushDevice' => $hasPushDevice,
			'onboarding' => $this->onboardingStateFor($user),
		]);
	}

	/**
	 * Which empty-instance prompt this user should get, decided here rather
	 * than reassembled from raw facts by every client.
	 *
	 * @return array{setupPrompt: bool, firstAppointmentPrompt: bool}
	 */
	private function onboardingStateFor(?IUser $user): array {
		$none = ['setupPrompt' => false, 'firstAppointmentPrompt' => false];
		if ($user === null) {
			return $none;
		}

		$isAdmin = $this->permissionService->isAdmin($user->getUID());
		$canCreate = $this->permissionService->canCreateAppointments($user->getUID());
		if ((!$isAdmin && !$canCreate) || $this->appointmentService->hasAnyAppointment()) {
			return $none;
		}

		// Walking the wizard hands the space over to the create prompt; from
		// then on the wizard lives in the admin settings only.
		$setupDone = $this->configService->isOnboardingCompleted();

		return [
			'setupPrompt' => $isAdmin && !$setupDone,
			'firstAppointmentPrompt' => $canCreate && (!$isAdmin || $setupDone),
		];
	}

	/**
	 * Get personal user settings
	 *
	 * Response-change notification toggle is only included for users who can
	 * actually receive these notifications (managers, users allowed to create
	 * appointments, or organizers of at least one appointment — with audit log
	 * enabled). Frontend uses the key's presence to decide whether to render
	 * the toggle at all.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{icalReminderTriggers: list<string>, notifyResponseChanges?: bool}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function getUserSettings(): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$payload = [
			'icalReminderTriggers' => $this->configService->getUserIcalReminderTriggers($user->getUID()),
		];

		if ($this->configService->isAuditLogEnabled()
			&& $this->canReceiveResponseNotifications($user->getUID())) {
			$payload['notifyResponseChanges'] = $this->configService->wantsResponseChangeNotifications($user->getUID());
		}

		return new DataResponse($payload);
	}

	/**
	 * Save personal user settings
	 *
	 * @param list<string> $icalReminderTriggers List of duration strings for iCal reminder triggers
	 * @param ?bool $notifyResponseChanges Opt-in toggle for response-change notifications (only honoured for eligible users)
	 * @return DataResponse<Http::STATUS_OK, array{success: bool}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function saveUserSettings(array $icalReminderTriggers = [], ?bool $notifyResponseChanges = null): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$this->configService->setUserIcalReminderTriggers($user->getUID(), $icalReminderTriggers);

		if ($notifyResponseChanges !== null
			&& $this->configService->isAuditLogEnabled()
			&& $this->canReceiveResponseNotifications($user->getUID())) {
			$this->configService->setWantsResponseChangeNotifications($user->getUID(), $notifyResponseChanges);
		}

		return new DataResponse(['success' => true]);
	}

	/**
	 * Whether the user can receive response-change notifications: global
	 * managers, users allowed to create appointments (they will become
	 * organizers), and current organizers of at least one appointment.
	 */
	private function canReceiveResponseNotifications(string $userId): bool {
		return $this->permissionService->canCreateAppointments($userId)
			|| $this->appointmentService->isOrganizerAnywhere($userId);
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
	 * @param ?list<string> $seriesIds Series to export in full, or null for no series filter
	 * @param bool $includeRsvp Whether to include the RSVP column per appointment
	 * @param bool $includeCheckin Whether to include the check-in column per appointment
	 * @param bool $includeOrganizers Whether to append a row listing each appointment's organizers
	 * @param bool $includeGroup Whether to include the group column next to the names
	 * @return DataResponse<Http::STATUS_OK, AttendanceExportResult, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function export(
		?array $appointmentIds = null,
		?string $startDate = null,
		?string $endDate = null,
		bool $includeComments = false,
		?array $seriesIds = null,
		bool $includeRsvp = true,
		bool $includeCheckin = true,
		bool $includeOrganizers = false,
		bool $includeGroup = true,
	): DataResponse {
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

		$options = ExportOptions::fromWire(
			$appointmentIds,
			$startDate,
			$endDate,
			$seriesIds,
			$includeRsvp,
			$includeCheckin,
			$includeComments,
			$includeOrganizers,
			$includeGroup,
		);

		if (!$options->hasAnyColumn()) {
			return new DataResponse(['error' => 'At least one of RSVP, check-in or comments must be included'], 400);
		}

		try {
			$result = $this->exportService->exportToOds($user->getUID(), $options);
			return new DataResponse([
				'path' => $result['path'],
				'filename' => $result['filename'],
			]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * List the appointment series the export can filter by, ongoing ones first
	 *
	 * @return DataResponse<Http::STATUS_OK, list<AttendanceExportSeries>, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function exportSeries(): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		if (!$this->permissionService->canManageAppointments($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions to export appointments'], 403);
		}

		return new DataResponse($this->exportService->getSeriesOverview());
	}

	/**
	 * Search for users, groups, and teams
	 *
	 * @param string $search Search query
	 * @return DataResponse<Http::STATUS_OK, list<array{id: string, label: string, type: string, icon: string, isGuest: bool}>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function searchUsersGroupsTeams(string $search = ''): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Managers and users allowed to create appointments need the picker
		// for visibility/organizer fields; organizers need it in the edit form;
		// admins need it for the response-summary-teams admin setting.
		if (!$this->permissionService->canCreateAppointments($user->getUID())
			&& !$this->appointmentService->isOrganizerAnywhere($user->getUID())
			&& !$this->permissionService->isAdmin($user->getUID())) {
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
	 * Get previously-used appointment locations for autocomplete, restricted
	 * to appointments the requesting user may see
	 *
	 * @return DataResponse<Http::STATUS_OK, list<string>, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function locationSuggestions(): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		return new DataResponse($this->appointmentService->getLocationSuggestions($user->getUID()));
	}

	/**
	 * Create a guest account via the Nextcloud Guests app
	 *
	 * Allows organizers to invite people without a Nextcloud account by
	 * provisioning a guest user. The new account is identified by its email
	 * (used both as UID and email). Guests cannot manage appointments or
	 * check in others — that is enforced by `PermissionService` regardless of
	 * group configuration.
	 *
	 * @param string $email Email address that becomes both the guest's UID and contact address
	 * @param string $displayName Optional human-readable name for the guest
	 * @return DataResponse<Http::STATUS_OK, AttendanceGuestCreationResult, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function createGuest(string $email = '', string $displayName = ''): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		if (!$this->permissionService->canManageAppointments($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions'], 403);
		}

		try {
			$result = $this->guestService->createGuest($user, $email, $displayName);
			return new DataResponse($result);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		} catch (\RuntimeException $e) {
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Send reminder notifications to users for an appointment
	 *
	 * @param int $id Appointment ID
	 * @param string $target Who to remind: 'non_responders', 'maybe', or 'both'
	 * @return DataResponse<Http::STATUS_OK, AttendanceReminderResult, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function sendReminders(int $id, string $target = 'non_responders'): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		if (!in_array($target, ConfigService::VALID_REMINDER_TARGETS, true)) {
			return new DataResponse(['error' => 'Invalid target, must be one of: non_responders, maybe, both'], 400);
		}

		$appointment = $this->findManageableAppointment($id, $user->getUID(), 'Insufficient permissions');
		if ($appointment instanceof DataResponse) {
			return $appointment;
		}

		if ($appointment->isClosed()) {
			return new DataResponse(['error' => 'Inquiry is closed'], 400);
		}

		$userIds = $this->appointmentService->getReminderTargetUserIds($appointment, $target);
		$sent = $this->notificationService->sendReminderToUsers($appointment, $userIds);

		return new DataResponse(['sent' => $sent]);
	}

	/**
	 * Send a reminder notification to a specific user for an appointment
	 *
	 * @param int $id Appointment ID
	 * @param string $userId Target user ID
	 * @return DataResponse<Http::STATUS_OK, AttendanceReminderResult, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function sendReminderToUser(int $id, string $userId): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		$appointment = $this->findManageableAppointment($id, $user->getUID(), 'Insufficient permissions');
		if ($appointment instanceof DataResponse) {
			return $appointment;
		}

		if ($appointment->isClosed()) {
			return new DataResponse(['error' => 'Inquiry is closed'], 400);
		}

		// Audience check on the reminder target (not the requester) — admin
		// bypass would let managers receive reminders for appointments
		// they're not actually in the audience for.
		if (!$this->visibilityService->isUserTargetAttendee($appointment, $userId)) {
			return new DataResponse(['error' => 'User is not a member of this appointment'], 400);
		}
		// Allow reminding non-responders and maybe-responders, but not yes/no
		$existingResponse = $this->appointmentService->getUserResponse($id, $userId);
		if ($existingResponse !== null && $existingResponse->getResponse() !== 'maybe') {
			return new DataResponse(['error' => 'User has already responded'], 400);
		}

		try {
			$this->notificationService->sendReminderToUser($appointment, $userId);
			return new DataResponse(['sent' => 1]);
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
