<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Db\CategoryMapper;
use OCA\Attendance\Db\DatetimeFormatTrait;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Collaboration\Collaborators\ISearch as ICollaboratorSearch;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\IShare;

/**
 * Core service for managing appointments and responses.
 * Delegates to specialized services for summary, visibility, and check-in operations.
 *
 * @psalm-type ListRow = array{appointment: Appointment, isAttendee: bool, isOrganizer: bool, isPast: bool}
 */
class AppointmentService {
	use DatetimeFormatTrait;

	/**
	 * Fields whose change alters where an attendee has to be. A renamed title
	 * or a reworded description does not, and a moved response deadline is
	 * what the reminder job chases anyway — editing those stays silent.
	 *
	 * Every field listed here needs its own sentence in Notifier::describeUpdate(),
	 * otherwise its push degrades to the generic fallback wording.
	 */
	private const NOTIFIED_UPDATE_FIELDS = ['time', 'location'];

	/** Rows read per round when paging the sidebar's past appointments. */
	private const NAVIGATION_CHUNK_SIZE = 200;

	private AppointmentMapper $appointmentMapper;
	private AttendanceResponseMapper $responseMapper;
	private IGroupManager $groupManager;
	private IUserManager $userManager;
	private ConfigService $configService;
	private VisibilityService $visibilityService;
	private ResponseSummaryService $responseSummaryService;
	private NotificationService $notificationService;
	private AttachmentService $attachmentService;
	private ICollaboratorSearch $collaboratorSearch;
	private IAppManager $appManager;
	private GuestService $guestService;
	private AuditEventService $auditEventService;
	private BookingService $bookingService;
	private PermissionService $permissionService;
	private OrgCalendarSyncService $orgCalendarSyncService;
	private CategoryMapper $categoryMapper;
	private TalkRoomService $talkRoomService;
	private ResponsePolicyService $responsePolicyService;
	private CapacityService $capacityService;
	private AppointmentSerializer $appointmentSerializer;
	private VacationService $vacationService;
	/** @var array<string, bool> per-request cache for isOrganizerAnywhere() */
	private array $organizerAnywhereCache = [];

	public function __construct(
		AppointmentMapper $appointmentMapper,
		AttendanceResponseMapper $responseMapper,
		IGroupManager $groupManager,
		IUserManager $userManager,
		ConfigService $configService,
		VisibilityService $visibilityService,
		ResponseSummaryService $responseSummaryService,
		NotificationService $notificationService,
		AttachmentService $attachmentService,
		ICollaboratorSearch $collaboratorSearch,
		IAppManager $appManager,
		GuestService $guestService,
		AuditEventService $auditEventService,
		BookingService $bookingService,
		PermissionService $permissionService,
		OrgCalendarSyncService $orgCalendarSyncService,
		CategoryMapper $categoryMapper,
		TalkRoomService $talkRoomService,
		ResponsePolicyService $responsePolicyService,
		CapacityService $capacityService,
		AppointmentSerializer $appointmentSerializer,
		VacationService $vacationService,
	) {
		$this->appointmentMapper = $appointmentMapper;
		$this->responseMapper = $responseMapper;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->configService = $configService;
		$this->visibilityService = $visibilityService;
		$this->responseSummaryService = $responseSummaryService;
		$this->notificationService = $notificationService;
		$this->attachmentService = $attachmentService;
		$this->collaboratorSearch = $collaboratorSearch;
		$this->appManager = $appManager;
		$this->guestService = $guestService;
		$this->auditEventService = $auditEventService;
		$this->bookingService = $bookingService;
		$this->permissionService = $permissionService;
		$this->orgCalendarSyncService = $orgCalendarSyncService;
		$this->categoryMapper = $categoryMapper;
		$this->talkRoomService = $talkRoomService;
		$this->responsePolicyService = $responsePolicyService;
		$this->capacityService = $capacityService;
		$this->appointmentSerializer = $appointmentSerializer;
		$this->vacationService = $vacationService;
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
		array $visibleTeams = [],
		bool $sendNotification = false,
		?string $calendarUri = null,
		?string $calendarEventUid = null,
		?string $seriesId = null,
		?int $seriesPosition = null,
		?string $responseDeadline = null,
		?array $organizers = null,
		?string $location = null,
		?int $categoryId = null,
		bool $createTalkRoom = false,
		?bool $allowMaybe = null,
		?int $maxAttendees = null,
		bool $waitlistEnabled = true,
	): Appointment {
		$this->validateDateRange($startDatetime, $endDatetime);

		$startFormatted = $this->formatDatetime($startDatetime);
		$endFormatted = $this->formatDatetime($endDatetime);
		$deadlineFormatted = $this->normalizeOptionalDatetime(
			$responseDeadline,
			$startFormatted,
		);

		$appointment = new Appointment();
		$appointment->setName($name);
		$appointment->setDescription($this->stripHtmlFromMarkdown($description));
		$appointment->setStartDatetime($startFormatted);
		$appointment->setEndDatetime($endFormatted);
		$appointment->setCreatedBy($createdBy);
		$appointment->setCreatedAt(gmdate('Y-m-d H:i:s'));
		$appointment->setUpdatedAt(gmdate('Y-m-d H:i:s'));
		$appointment->setIsActive(1);
		$appointment->setVisibleUsers(empty($visibleUsers) ? null : json_encode($visibleUsers));
		$appointment->setVisibleGroups(empty($visibleGroups) ? null : json_encode($visibleGroups));
		$appointment->setVisibleTeams(empty($visibleTeams) ? null : json_encode($visibleTeams));
		$appointment->setLocation($this->normalizeLocation($location));
		$appointment->setCategoryId($this->normalizeCategoryId($categoryId));
		$appointment->setCalendarUri($calendarUri);
		$appointment->setCalendarEventUid($calendarEventUid);
		$appointment->setSeriesId($seriesId);
		$appointment->setSeriesPosition($seriesPosition);
		$appointment->setSendNotification($sendNotification);
		$appointment->setCreateTalkRoom($createTalkRoom);
		// NULL is a real state here: the appointment has no opinion on "Maybe"
		// and follows whatever the instance default says, now and later.
		$appointment->setAllowMaybe($allowMaybe);
		$appointment->setMaxAttendees($this->normalizeAttendeeLimit($maxAttendees));
		$appointment->setWaitlistEnabled($waitlistEnabled);
		$appointment->setResponseDeadline($deadlineFormatted);
		// The creator freely chooses the initial organizer list; when the client
		// sends nothing, the creator becomes the sole organizer.
		$requestedOrganizers = $organizers ?? [$createdBy];
		// Without manage permission, being an organizer is the creator's only
		// handle on the appointment — a copy would carry only foreign organizers.
		if (!$this->permissionService->canManageAppointments($createdBy)) {
			$requestedOrganizers[] = $createdBy;
		}
		$this->applyOrganizerChange(
			$appointment,
			$this->normalizeOrganizers($requestedOrganizers),
			$createdBy,
			true,
		);

		$appointment = $this->appointmentMapper->insert($appointment);

		$this->auditEventService->recordAppointmentLifecycle(
			\OCA\Attendance\Audit\Verb::APPOINTMENT_CREATED,
			$appointment->getId(),
			\OCA\Attendance\Audit\Verb::SOURCE_CLIENT,
		);

		$this->applyVacationAutoResponses($appointment);

		$this->orgCalendarSyncService->syncAppointment($appointment);

		if ($sendNotification) {
			$this->notificationService->sendNewAppointmentNotifications(
				$appointment,
				$this->recipientsWithout($this->getAffectedUsers($appointment), $createdBy),
			);
		}

		return $appointment;
	}

	/**
	 * Default a brand-new appointment's invitees to "no" when they're on
	 * vacation for its window — they can still change it afterwards through
	 * the normal respond flow, same as any other answer.
	 *
	 * Only runs at creation time: a vacation entered after the appointment
	 * already exists does not retroactively touch anyone's response.
	 */
	private function applyVacationAutoResponses(Appointment $appointment): void {
		// Nearly every appointment has nobody on vacation, so ask the small
		// vacation table first and only resolve the audience when it matters.
		$onVacation = $this->vacationService->findUsersOnVacation(
			$appointment->getStartDatetime(),
			$appointment->getEndDatetime(),
		);
		if (empty($onVacation)) {
			return;
		}

		$invited = $this->recipientsWithout($this->getAffectedUsers($appointment), null);
		foreach (array_intersect($onVacation, $invited) as $userId) {
			$response = new AttendanceResponse();
			$response->setAppointmentId($appointment->getId());
			$response->setUserId($userId);
			$response->setResponse('no');
			$response->setComment('');
			$response->setRespondedAt(gmdate('Y-m-d H:i:s'));
			$response->setResponseSource(ResponseService::SOURCE_VACATION);
			$this->responseMapper->insert($response);
			$this->auditEventService->recordResponseChange(
				$appointment->getId(),
				$userId,
				null,
				'',
				'no',
				'',
				\OCA\Attendance\Audit\Verb::SOURCE_VACATION,
			);
		}
	}

	/**
	 * Answer "no" to every upcoming, still-open appointment inside a vacation
	 * that reached this user and that they have not answered yet — what a user
	 * opts into when they record a vacation after appointments already exist.
	 * An answer already given stays: they may well have picked the gig over
	 * the holiday.
	 *
	 * @return int How many appointments were answered
	 */
	public function answerNoDuringVacation(string $userId, string $startDate, string $endDate): int {
		$answered = 0;
		$appointments = $this->appointmentMapper->findUpcomingOverlapping($startDate . ' 00:00:00', $endDate . ' 23:59:59');
		foreach ($appointments as $appointment) {
			if ($appointment->isClosed() || $appointment->isCancelled()) {
				continue;
			}
			if (!$this->visibilityService->isUserTargetAttendee($appointment, $userId)) {
				continue;
			}
			$existing = $this->getUserResponse($appointment->getId(), $userId);
			if ($existing !== null && $existing->getResponse() !== null) {
				continue;
			}

			$this->applyResponse(
				$appointment,
				$userId,
				'no',
				null,
				ResponseService::SOURCE_VACATION,
				\OCA\Attendance\Audit\Verb::SOURCE_VACATION,
				null,
			);
			$answered++;
		}

		return $answered;
	}

	/**
	 * Who an audience selection would address, before any appointment exists —
	 * the same resolution getAffectedUsers() does, so a hint computed from a
	 * draft and the auto-"no" on the saved appointment can't disagree.
	 *
	 * @param array<array-key, string> $visibleUsers
	 * @param array<array-key, string> $visibleGroups
	 * @param array<array-key, string> $visibleTeams
	 * @return list<string>
	 */
	public function previewAudience(array $visibleUsers, array $visibleGroups, array $visibleTeams): array {
		return $this->recipientsWithout($this->resolveAudience($visibleUsers, $visibleGroups, $visibleTeams), null);
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
		array $visibleGroups = [],
		array $visibleTeams = [],
		?DeadlineUpdate $deadlineUpdate = null,
		?array $organizers = null,
		?string $location = null,
		?int $categoryId = null,
		?bool $createTalkRoom = null,
		?bool $allowMaybe = null,
		?int $maxAttendees = null,
		?bool $waitlistEnabled = null,
	): Appointment {
		$appointment = $this->appointmentMapper->find($id);

		$this->validateDateRange($startDatetime, $endDatetime);

		$before = clone $appointment;

		$startFormatted = $this->formatDatetime($startDatetime);
		$endFormatted = $this->formatDatetime($endDatetime);

		$appointment->setName($name);
		$appointment->setDescription($this->stripHtmlFromMarkdown($description));
		$appointment->setStartDatetime($startFormatted);
		$appointment->setEndDatetime($endFormatted);
		$appointment->setUpdatedAt(gmdate('Y-m-d H:i:s'));
		$appointment->setVisibleUsers(empty($visibleUsers) ? null : json_encode($visibleUsers));
		$appointment->setVisibleGroups(empty($visibleGroups) ? null : json_encode($visibleGroups));
		$appointment->setVisibleTeams(empty($visibleTeams) ? null : json_encode($visibleTeams));
		$appointment->setLocation($this->normalizeLocation($location));
		$appointment->setCategoryId($this->normalizeCategoryId($categoryId));

		if ($organizers !== null) {
			$this->applyOrganizerChange(
				$appointment,
				$this->normalizeOrganizers($organizers),
				$userId,
				$this->permissionService->canManageAppointments($userId),
			);
		}

		$deadlineUpdate ??= DeadlineUpdate::unchanged();
		if ($deadlineUpdate->isClear()) {
			$appointment->setResponseDeadline(null);
		} elseif ($deadlineUpdate->isSet()) {
			$appointment->setResponseDeadline(
				$this->normalizeOptionalDatetime($deadlineUpdate->value(), $startFormatted),
			);
		}

		if ($createTalkRoom !== null) {
			$appointment->setCreateTalkRoom($createTalkRoom);
		}

		// Unlike the column, the parameter has no tri-state: null means the
		// caller left the field alone. Deciding is one-way — an appointment that
		// has an answer keeps it rather than falling back to the default.
		if ($allowMaybe !== null) {
			$appointment->setAllowMaybe($allowMaybe);
		}

		// Zero and null both mean "no limit", so the field clears itself; there
		// is no separate way to say "leave it alone". Lowering it below what is
		// already taken is allowed and demotes nobody — the appointment simply
		// sits over capacity until people drop out.
		$appointment->setMaxAttendees($this->normalizeAttendeeLimit($maxAttendees));
		if ($waitlistEnabled !== null) {
			$appointment->setWaitlistEnabled($waitlistEnabled);
		}

		$updated = $this->appointmentMapper->update($appointment);

		// A raised limit lets the next people in line through.
		$this->capacityService->syncWaitlistNotifications($updated);

		$this->notifyAboutUpdate($before, $updated, $this->commitAppointmentChange($before, $updated), $userId);

		return $updated;
	}

	/**
	 * Post-commit tail shared by the single-appointment and series edit paths:
	 * record what moved and push it out to the organizer calendar. Notifying is
	 * deliberately not part of it — a series aggregates into one wave instead of
	 * one per appointment.
	 *
	 * @return list<string> The changed fields, as recorded for the audit trail
	 */
	private function commitAppointmentChange(Appointment $before, Appointment $updated): array {
		$changedFields = $this->computeAppointmentChanges($before, $updated);
		if ($changedFields !== []) {
			$this->auditEventService->recordAppointmentUpdate($updated->getId(), $changedFields);
		}

		$this->orgCalendarSyncService->syncAppointment($updated);
		$this->talkRoomService->syncRoomDetails($updated);
		if (in_array('organizers', $changedFields, true)) {
			// A new organiser needs moderator rank and a place in the room; one
			// who lost the role needs the rank back, or the room entirely if they
			// no longer hold a place either.
			$this->talkRoomService->syncParticipants($updated);
		}

		return $changedFields;
	}

	/**
	 * Announce an edit that happened outside updateAppointment(). The calendar
	 * sync listener writes straight to the mapper when someone drags the linked
	 * event in the Calendar app, and that edit is as real to an attendee as one
	 * made in the app.
	 *
	 * @param string|null $actorId Excluded from the wave; null when the edit has
	 *                             no identifiable author
	 */
	public function announceAppointmentUpdate(Appointment $before, Appointment $updated, ?string $actorId): void {
		$this->notifyAboutUpdate($before, $updated, $this->computeAppointmentChanges($before, $updated), $actorId);
		// Dragging the linked event in the Calendar app moves the date the room
		// is named after, so this path has to carry it too.
		$this->talkRoomService->syncRoomDetails($updated);
	}

	/**
	 * Two waves after an edit: people who just gained access hear about the
	 * appointment for the first time, everyone who already had it hears what
	 * moved. Both stay quiet for an appointment that is already over or
	 * cancelled — editing those is bookkeeping, not news.
	 *
	 * @param list<string> $changedFields As returned by computeAppointmentChanges()
	 * @param string|null $actorId The editor, excluded from both waves
	 */
	private function notifyAboutUpdate(Appointment $before, Appointment $updated, array $changedFields, ?string $actorId): void {
		if ($updated->isCancelled() || $updated->isPast()) {
			return;
		}

		$notifiedChanges = array_values(array_intersect(self::NOTIFIED_UPDATE_FIELDS, $changedFields));
		// The create-time opt-out governs who learns the appointment exists, so
		// it governs this late arrival too.
		$announceNewcomers = in_array('visibility', $changedFields, true) && $updated->getSendNotification();
		// Deciding this before expanding the audience keeps a rename off the
		// group/team expansion, which can hydrate every account on the instance.
		if ($notifiedChanges === [] && !$announceNewcomers) {
			return;
		}

		$addressed = $this->getAffectedUsers($updated);
		$stillAddressed = $addressed;

		if ($announceNewcomers) {
			$previouslyAddressed = $this->getAffectedUsers($before);
			$newcomers = $this->recipientsWithout(array_diff($addressed, $previouslyAddressed), $actorId);
			if ($newcomers !== []) {
				$this->notificationService->sendNewAppointmentNotifications($updated, $newcomers);
			}
			$stillAddressed = array_intersect($addressed, $previouslyAddressed);
		}

		if ($notifiedChanges === []) {
			return;
		}

		$this->notificationService->sendUpdateNotifications(
			$updated,
			$this->recipientsWithout($stillAddressed, $actorId),
			$notifiedChanges,
		);
	}

	/**
	 * Everyone in a notification audience except the person who caused it.
	 * Public alongside getAffectedUsers(), which is what callers feed it.
	 *
	 * @param array<array-key, mixed> $userIds As returned by getAffectedUsers()
	 * @return list<string>
	 */
	public function recipientsWithout(array $userIds, ?string $actorId): array {
		return array_values(array_filter(
			$userIds,
			static fn ($userId): bool => is_string($userId) && $userId !== $actorId,
		));
	}

	/**
	 * An empty string means "no location" the same as null does.
	 */
	private function normalizeLocation(?string $location): ?string {
		return $location !== null && $location !== '' ? $location : null;
	}

	/**
	 * A category id that no longer exists (deleted between page load and
	 * submit) is dropped rather than rejected — same leniency as an unknown
	 * organizer.
	 */
	private function normalizeCategoryId(?int $categoryId): ?int {
		if ($categoryId === null) {
			return null;
		}
		try {
			return $this->categoryMapper->find($categoryId)->getId();
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Normalize an organizer list: drop unknown users and guests, dedupe.
	 * Guests must never hold organizer rights (mirrors GUEST_BLOCKED_PERMISSIONS).
	 *
	 * @param array $organizers Raw user IDs from the client
	 * @return list<string>
	 */
	private function normalizeOrganizers(array $organizers): array {
		$normalized = [];
		foreach ($organizers as $userId) {
			$userId = (string)$userId;
			if (isset($normalized[$userId])) {
				continue;
			}
			if ($this->userManager->get($userId) === null || $this->guestService->isGuestUser($userId)) {
				continue;
			}
			$normalized[$userId] = true;
		}
		return array_keys($normalized);
	}

	/**
	 * Apply an already-normalized organizer list against the guardrails:
	 * managers may change the list freely; organizers may add anyone but
	 * remove only themselves.
	 *
	 * @param list<string> $normalized Result of normalizeOrganizers()
	 * @throws \InvalidArgumentException When a non-manager removes someone else
	 */
	private function applyOrganizerChange(Appointment $appointment, array $normalized, string $actorId, bool $actorIsManager): void {
		if (!$actorIsManager) {
			$removed = array_diff($appointment->getOrganizersList(), $normalized);
			if (array_diff($removed, [$actorId]) !== []) {
				throw new \InvalidArgumentException('Organizers can only remove themselves from the organizer list.');
			}
		}
		$appointment->setOrganizers($normalized === [] ? null : json_encode($normalized));
	}

	/**
	 * Diff the audit-worthy fields of an appointment. Returns just the field
	 * keys that changed — before/after values are intentionally not stored to
	 * keep the audit row small (description in particular could be very long).
	 *
	 * @return list<string>
	 */
	private function computeAppointmentChanges(Appointment $before, Appointment $after): array {
		$fields = [];

		if ($before->getName() !== $after->getName()) {
			$fields[] = 'name';
		}
		if ($before->getDescription() !== $after->getDescription()) {
			$fields[] = 'description';
		}
		if ($before->getStartDatetime() !== $after->getStartDatetime()
			|| $before->getEndDatetime() !== $after->getEndDatetime()) {
			$fields[] = 'time';
		}
		if ($before->getVisibleUsers() !== $after->getVisibleUsers()
			|| $before->getVisibleGroups() !== $after->getVisibleGroups()
			|| $before->getVisibleTeams() !== $after->getVisibleTeams()) {
			$fields[] = 'visibility';
		}
		if ($before->getResponseDeadline() !== $after->getResponseDeadline()) {
			$fields[] = 'deadline';
		}
		if ($before->getOrganizersList() !== $after->getOrganizersList()) {
			$fields[] = 'organizers';
		}
		if ($before->getLocation() !== $after->getLocation()) {
			$fields[] = 'location';
		}
		if ($before->getCategoryId() !== $after->getCategoryId()) {
			$fields[] = 'category';
		}

		return $fields;
	}

	/**
	 * Close an appointment inquiry. Marks closedAt with the current UTC time.
	 * Idempotent: returns the existing appointment unchanged if already closed.
	 */
	public function closeAppointment(int $id, string $source = \OCA\Attendance\Audit\Verb::SOURCE_CLIENT): Appointment {
		$appointment = $this->appointmentMapper->find($id);
		if ($appointment->isClosed()) {
			return $appointment;
		}
		$appointment->setClosedAt(gmdate('Y-m-d H:i:s'));
		$appointment->setUpdatedAt(gmdate('Y-m-d H:i:s'));
		$updated = $this->appointmentMapper->update($appointment);

		$this->auditEventService->recordAppointmentLifecycle(
			\OCA\Attendance\Audit\Verb::APPOINTMENT_CLOSED,
			$id,
			$source,
		);

		// Booking wave: notify planned-in / not-planned-in yes-responders. No-op
		// unless the feature is on and somebody is booked — or an earlier close
		// handed out a verdict that now has to be taken back. Reopen-safe:
		// only diffs against the last communicated state.
		$this->bookingService->notifyOnClose($updated);

		// Anyone still in line keeps the date free for a spot that is no longer
		// coming. Same fire-once marker as the promotions.
		$this->capacityService->notifyNotPromoted($updated);

		$this->talkRoomService->openOrSync($updated);

		return $updated;
	}

	/**
	 * Re-open a previously closed appointment inquiry. Clears closedAt.
	 * Idempotent: returns the existing appointment unchanged if not closed.
	 *
	 * Also clears any responseDeadline — the admin just overruled the
	 * auto-close, so the deadline that triggered it is moot. Without this,
	 * the next reminder-job tick would re-close on the same condition.
	 */
	public function reopenAppointment(int $id): Appointment {
		$appointment = $this->appointmentMapper->find($id);
		if (!$appointment->isClosed()) {
			return $appointment;
		}
		$appointment->setClosedAt(null);
		$appointment->setResponseDeadline(null);
		$appointment->setUpdatedAt(gmdate('Y-m-d H:i:s'));
		$updated = $this->appointmentMapper->update($appointment);

		$this->auditEventService->recordAppointmentLifecycle(
			\OCA\Attendance\Audit\Verb::APPOINTMENT_REOPENED,
			$id,
			\OCA\Attendance\Audit\Verb::SOURCE_CLIENT,
		);

		return $updated;
	}

	/**
	 * Cancel an appointment: mark cancelledAt with the current UTC time. This is
	 * a separate state next to closedAt — the event does NOT take place, but all
	 * data/notes are kept. Cancelled appointments drop out of the active lists,
	 * get no reminders and are not auto-closed. Addressed attendees are notified.
	 * Idempotent: returns the existing appointment unchanged if already cancelled.
	 *
	 * @param int $id The appointment ID
	 * @param string|null $actorId The user performing the cancellation; excluded
	 *                             from the notification wave (they already know they cancelled).
	 */
	public function cancelAppointment(int $id, ?string $actorId = null): Appointment {
		$appointment = $this->appointmentMapper->find($id);
		if ($appointment->isCancelled()) {
			return $appointment;
		}
		$appointment->setCancelledAt(gmdate('Y-m-d H:i:s'));
		$appointment->setUpdatedAt(gmdate('Y-m-d H:i:s'));
		$updated = $this->appointmentMapper->update($appointment);

		$this->auditEventService->recordAppointmentLifecycle(
			\OCA\Attendance\Audit\Verb::APPOINTMENT_CANCELLED,
			$id,
			\OCA\Attendance\Audit\Verb::SOURCE_CLIENT,
		);

		$this->orgCalendarSyncService->syncAppointment($updated);

		// Notify addressed attendees that the appointment will not take place.
		$this->notificationService->sendCancellationNotifications(
			$updated,
			$this->recipientsWithout($this->getAffectedUsers($updated), $actorId),
		);

		return $updated;
	}

	/**
	 * Reactivate a previously cancelled appointment: clears cancelledAt. The
	 * appointment returns to whatever state it had before (e.g. still closed if
	 * it was closed). Addressed attendees are notified, mirroring the cancel
	 * wave — they were told it was off and need to hear it is back on.
	 * Idempotent: returns the existing appointment unchanged if not cancelled.
	 *
	 * @param int $id The appointment ID
	 * @param string|null $actorId The user reactivating, excluded from the notification wave
	 */
	public function uncancelAppointment(int $id, ?string $actorId = null): Appointment {
		$appointment = $this->appointmentMapper->find($id);
		if (!$appointment->isCancelled()) {
			return $appointment;
		}
		$appointment->setCancelledAt(null);
		$appointment->setUpdatedAt(gmdate('Y-m-d H:i:s'));
		$updated = $this->appointmentMapper->update($appointment);

		$this->auditEventService->recordAppointmentLifecycle(
			\OCA\Attendance\Audit\Verb::APPOINTMENT_UNCANCELLED,
			$id,
			\OCA\Attendance\Audit\Verb::SOURCE_CLIENT,
		);

		$this->orgCalendarSyncService->syncAppointment($updated);

		$this->notificationService->sendReactivationNotifications(
			$updated,
			$this->recipientsWithout($this->getAffectedUsers($updated), $actorId),
		);

		return $updated;
	}

	/**
	 * Delete an appointment (soft delete).
	 */
	public function deleteAppointment(int $id, string $userId): void {
		$appointment = $this->appointmentMapper->find($id);
		$appointment->setIsActive(0);
		$appointment->setUpdatedAt(gmdate('Y-m-d H:i:s'));
		// Only forget the conversation — what people wrote in there is theirs,
		// and deleting an inquiry is no reason to take it away from them.
		$appointment->setTalkRoomToken(null);
		$this->appointmentMapper->update($appointment);
		$this->orgCalendarSyncService->handleAppointmentDeleted($appointment);
	}

	/**
	 * Update appointments in a series based on scope.
	 *
	 * @param int $referenceId The appointment ID used as reference
	 * @param string $scope 'single', 'future', or 'all'
	 * @param string $name Appointment name
	 * @param string $description Appointment description
	 * @param string $startDatetime Start date and time (ISO 8601)
	 * @param string $endDatetime End date and time (ISO 8601)
	 * @param string $userId User performing the update
	 * @param list<string> $visibleUsers User IDs
	 * @param list<string> $visibleGroups Group IDs
	 * @param list<string> $visibleTeams Team IDs
	 * @param ?DeadlineUpdate $deadlineUpdate Deadline change instruction.
	 * @param ?list<string> $organizers New organizer list, or null to leave unchanged
	 * @param ?string $location Location, applied identically to every affected sibling
	 * @param ?int $categoryId Category, applied identically to every affected sibling
	 * @param ?bool $allowMaybe Whether "Maybe" is offered, applied identically to every affected sibling; null leaves it alone
	 * @param ?int $maxAttendees Attendance limit, applied identically to every affected sibling; null clears it
	 * @param ?bool $waitlistEnabled Whether a full sibling offers a waitlist; null leaves it alone
	 * @return list<Appointment> Updated appointments
	 */
	public function updateSeriesAppointments(
		int $referenceId,
		string $scope,
		string $name,
		string $description,
		string $startDatetime,
		string $endDatetime,
		string $userId,
		array $visibleUsers = [],
		array $visibleGroups = [],
		array $visibleTeams = [],
		?DeadlineUpdate $deadlineUpdate = null,
		?array $organizers = null,
		?string $location = null,
		?int $categoryId = null,
		?bool $createTalkRoom = null,
		?bool $allowMaybe = null,
		?int $maxAttendees = null,
		?bool $waitlistEnabled = null,
	): array {
		$deadlineUpdate ??= DeadlineUpdate::unchanged();
		$reference = $this->appointmentMapper->find($referenceId);

		if ($scope === 'single') {
			// Detach from series and update normally
			$reference->setSeriesId(null);
			$reference->setSeriesPosition(null);
			$this->appointmentMapper->update($reference);
			$updated = $this->updateAppointment(
				$referenceId, $name, $description, $startDatetime, $endDatetime,
				$userId, $visibleUsers, $visibleGroups, $visibleTeams,
				$deadlineUpdate, $organizers, $location, $categoryId, $createTalkRoom,
				$allowMaybe, $maxAttendees, $waitlistEnabled,
			);
			return [$updated];
		}

		$seriesId = $reference->getSeriesId();
		if ($seriesId === null) {
			// Not part of a series, just update single
			$updated = $this->updateAppointment(
				$referenceId, $name, $description, $startDatetime, $endDatetime,
				$userId, $visibleUsers, $visibleGroups, $visibleTeams,
				$deadlineUpdate, $organizers, $location, $categoryId, $createTalkRoom,
				$allowMaybe, $maxAttendees, $waitlistEnabled,
			);
			return [$updated];
		}

		// Calculate time deltas
		$oldStart = new \DateTime($reference->getStartDatetime(), new \DateTimeZone('UTC'));
		$oldEnd = new \DateTime($reference->getEndDatetime(), new \DateTimeZone('UTC'));
		$newStart = new \DateTime($startDatetime);
		$newStart->setTimezone(new \DateTimeZone('UTC'));
		$newEnd = new \DateTime($endDatetime);
		$newEnd->setTimezone(new \DateTimeZone('UTC'));

		$startDelta = $newStart->getTimestamp() - $oldStart->getTimestamp();
		$endDelta = $newEnd->getTimestamp() - $oldEnd->getTimestamp();

		// Load siblings
		if ($scope === 'future') {
			$siblings = $this->appointmentMapper->findBySeriesIdFromPosition($seriesId, $reference->getSeriesPosition());
		} else {
			$siblings = $this->appointmentMapper->findBySeriesId($seriesId);
		}

		// Hoisted once for the whole series: the requested organizer list and
		// the actor's manager status are identical for every sibling.
		$actorIsManager = $this->permissionService->canManageAppointments($userId);
		$normalizedOrganizers = $organizers === null ? null : $this->normalizeOrganizers($organizers);

		$this->assertCanManageSiblings($siblings, $userId, $actorIsManager);

		$this->validateDateRange($startDatetime, $endDatetime);

		$descriptionClean = $this->stripHtmlFromMarkdown($description);
		$visibleUsersJson = empty($visibleUsers) ? null : json_encode($visibleUsers);
		$visibleGroupsJson = empty($visibleGroups) ? null : json_encode($visibleGroups);
		$visibleTeamsJson = empty($visibleTeams) ? null : json_encode($visibleTeams);
		$locationValue = $this->normalizeLocation($location);
		$categoryIdValue = $this->normalizeCategoryId($categoryId);

		// Per-sibling deadline rule. Three cases by $deadlineUpdate:
		//   set     → sibling_start + (new_deadline - new_start)
		//   clear   → null
		//   unchanged → rebase the existing deadline by startDelta
		$deadlineOffsetSeconds = null;
		if ($deadlineUpdate->isSet()) {
			$newDeadline = new \DateTime($deadlineUpdate->value());
			$newDeadline->setTimezone(new \DateTimeZone('UTC'));
			$deadlineOffsetSeconds = $newDeadline->getTimestamp() - $newStart->getTimestamp();
		}

		$updated = [];
		/** @var list<array{before: Appointment, after: Appointment, changed: list<string>}> $edits */
		$edits = [];
		foreach ($siblings as $sibling) {
			$before = clone $sibling;

			$sibling->setName($name);
			$sibling->setDescription($descriptionClean);
			$sibling->setLocation($locationValue);
			$sibling->setCategoryId($categoryIdValue);
			if ($createTalkRoom !== null) {
				$sibling->setCreateTalkRoom($createTalkRoom);
			}
			if ($allowMaybe !== null) {
				$sibling->setAllowMaybe($allowMaybe);
			}
			$sibling->setMaxAttendees($this->normalizeAttendeeLimit($maxAttendees));
			if ($waitlistEnabled !== null) {
				$sibling->setWaitlistEnabled($waitlistEnabled);
			}

			// Apply time deltas
			$siblingStart = new \DateTime($sibling->getStartDatetime(), new \DateTimeZone('UTC'));
			$siblingEnd = new \DateTime($sibling->getEndDatetime(), new \DateTimeZone('UTC'));
			$siblingStart->modify("{$startDelta} seconds");
			$siblingEnd->modify("{$endDelta} seconds");
			$sibling->setStartDatetime($siblingStart->format('Y-m-d H:i:s'));
			$sibling->setEndDatetime($siblingEnd->format('Y-m-d H:i:s'));

			$sibling->setUpdatedAt(gmdate('Y-m-d H:i:s'));
			$sibling->setVisibleUsers($visibleUsersJson);
			$sibling->setVisibleGroups($visibleGroupsJson);
			$sibling->setVisibleTeams($visibleTeamsJson);

			if ($normalizedOrganizers !== null) {
				$this->applyOrganizerChange($sibling, $normalizedOrganizers, $userId, $actorIsManager);
			}

			if ($deadlineUpdate->isClear()) {
				$sibling->setResponseDeadline(null);
			} elseif ($deadlineOffsetSeconds !== null) {
				$siblingDeadline = (clone $siblingStart)->modify("{$deadlineOffsetSeconds} seconds");
				$sibling->setResponseDeadline($siblingDeadline->format('Y-m-d H:i:s'));
			} elseif ($sibling->getResponseDeadline() !== null) {
				// Rebase the existing deadline by startDelta so it tracks the new start.
				$siblingDeadline = new \DateTime($sibling->getResponseDeadline(), new \DateTimeZone('UTC'));
				$siblingDeadline->modify("{$startDelta} seconds");
				$sibling->setResponseDeadline($siblingDeadline->format('Y-m-d H:i:s'));
			}

			$updatedSibling = $this->appointmentMapper->update($sibling);
			$this->capacityService->syncWaitlistNotifications($updatedSibling);
			$edits[] = [
				'before' => $before,
				'after' => $updatedSibling,
				'changed' => $this->commitAppointmentChange($before, $updatedSibling),
			];
			$updated[] = $updatedSibling;
		}

		$this->notifyAboutSeriesUpdate($edits, $userId);

		return $updated;
	}

	/**
	 * One wave for the whole series instead of one per appointment — moving a
	 * weekly rehearsal by an hour would otherwise mean fifty pushes per person.
	 * Appointments already over or cancelled do not count towards it.
	 *
	 * @param list<array{before: Appointment, after: Appointment, changed: list<string>}> $edits
	 */
	private function notifyAboutSeriesUpdate(array $edits, string $actorId): void {
		$upcoming = array_values(array_filter(
			$edits,
			static fn (array $edit): bool => !$edit['after']->isCancelled() && !$edit['after']->isPast(),
		));
		if ($upcoming === []) {
			return;
		}

		$notifiedChanges = [];
		$movedCount = 0;
		$visibilityMoved = false;
		foreach ($upcoming as $edit) {
			$changes = array_intersect(self::NOTIFIED_UPDATE_FIELDS, $edit['changed']);
			if ($changes !== []) {
				$movedCount++;
				$notifiedChanges = array_unique(array_merge($notifiedChanges, $changes));
			}
			$visibilityMoved = $visibilityMoved || in_array('visibility', $edit['changed'], true);
		}

		$sample = $upcoming[0];
		$announceNewcomers = $visibilityMoved && $sample['after']->getSendNotification();
		if ($movedCount === 0 && !$announceNewcomers) {
			return;
		}

		// A series edit writes the same visibility to every sibling, so one
		// before/after pair describes the whole series' audience.
		$addressed = $this->getAffectedUsers($sample['after']);
		$stillAddressed = $addressed;

		if ($announceNewcomers) {
			$previouslyAddressed = $this->getAffectedUsers($sample['before']);
			$newcomers = $this->recipientsWithout(array_diff($addressed, $previouslyAddressed), $actorId);
			if ($newcomers !== []) {
				// Newcomers gain the whole series at once, which is the shape the
				// bulk-create wave already speaks.
				$this->notificationService->sendBulkAppointmentNotifications(
					count($upcoming),
					$sample['after']->getName(),
					$newcomers,
				);
			}
			$stillAddressed = array_intersect($addressed, $previouslyAddressed);
		}

		if ($movedCount === 0) {
			return;
		}

		$this->notificationService->sendSeriesUpdateNotifications(
			$movedCount,
			$sample['after']->getName(),
			array_values($notifiedChanges),
			$this->recipientsWithout($stillAddressed, $actorId),
		);
	}

	/**
	 * Delete appointments in a series based on scope.
	 *
	 * @param int $referenceId The appointment ID used as reference
	 * @param string $scope 'single', 'future', or 'all'
	 * @param string $userId User performing the delete
	 * @return int Number of deleted appointments
	 */
	public function deleteSeriesAppointments(int $referenceId, string $scope, string $userId): int {
		$reference = $this->appointmentMapper->find($referenceId);

		if ($scope === 'single') {
			// Detach from series and delete normally
			$reference->setSeriesId(null);
			$reference->setSeriesPosition(null);
			$this->appointmentMapper->update($reference);
			$this->deleteAppointment($referenceId, $userId);
			return 1;
		}

		$seriesId = $reference->getSeriesId();
		if ($seriesId === null) {
			$this->deleteAppointment($referenceId, $userId);
			return 1;
		}

		if ($scope === 'future') {
			$siblings = $this->appointmentMapper->findBySeriesIdFromPosition($seriesId, $reference->getSeriesPosition());
		} else {
			$siblings = $this->appointmentMapper->findBySeriesId($seriesId);
		}

		$this->assertCanManageSiblings($siblings, $userId, $this->permissionService->canManageAppointments($userId));

		foreach ($siblings as $sibling) {
			$sibling->setIsActive(0);
			$sibling->setUpdatedAt(gmdate('Y-m-d H:i:s'));
			$this->appointmentMapper->update($sibling);
			$this->orgCalendarSyncService->handleAppointmentDeleted($sibling);
		}

		return count($siblings);
	}

	/**
	 * Series-wide operations touch appointments beyond the one the controller
	 * authorized. Managers may touch all of them; organizers only when they
	 * are organizer of every affected sibling.
	 *
	 * @param list<Appointment> $siblings
	 * @throws \InvalidArgumentException
	 */
	private function assertCanManageSiblings(array $siblings, string $userId, bool $actorIsManager): void {
		if ($actorIsManager) {
			return;
		}
		foreach ($siblings as $sibling) {
			if (!$this->permissionService->isOrganizer($sibling, $userId)) {
				throw new \InvalidArgumentException('You are not an organizer of all appointments in this series.');
			}
		}
	}

	/**
	 * Get a single appointment by ID.
	 */
	public function getAppointment(int $id): Appointment {
		return $this->appointmentMapper->find($id);
	}

	/**
	 * Whether the user is organizer of at least one active appointment.
	 * Memoized per request — the user-search endpoint consults this per
	 * keystroke and the underlying LIKE query scans the appointments table.
	 */
	public function isOrganizerAnywhere(string $userId): bool {
		return $this->organizerAnywhereCache[$userId]
			??= $this->appointmentMapper->existsWithOrganizer($userId);
	}

	/**
	 * Whether the instance holds any appointment at all — the signal for a
	 * fresh install, used to offer admins the setup wizard.
	 */
	public function hasAnyAppointment(): bool {
		return $this->appointmentMapper->hasAny();
	}

	/**
	 * Get a single appointment with user response and summary.
	 *
	 * @param bool $includeResponseSummary Attach the full response overview
	 *                                     (other attendees' answers and roster). Gate on the caller's
	 *                                     PERMISSION_SEE_RESPONSE_OVERVIEW — it leaks every attendee's answer.
	 * @param bool $includeComments Include free-text comments in that overview.
	 *                              Gate on the caller's PERMISSION_SEE_COMMENTS.
	 * @param bool $includeResponseCounts Attach the aggregate counts (no names)
	 *                                    when the full overview is withheld. Gate on
	 *                                    the caller's PERMISSION_SEE_RESPONSE_COUNTS.
	 */
	public function getAppointmentWithUserResponse(int $id, string $userId, bool $includeResponseSummary = false, bool $includeComments = false, bool $includeResponseCounts = false): ?array {
		$appointment = $this->appointmentMapper->find($id);

		if (!$this->visibilityService->canUserSeeAppointment($appointment, $userId)) {
			return null;
		}

		// Organizers get the insights for their own appointment even without
		// the global overview/comments permissions.
		$myPermissions = $this->buildMyPermissions(
			$appointment,
			$userId,
			$this->permissionService->canManageAppointments($userId),
			$includeResponseSummary,
			$includeComments,
			$includeResponseCounts,
			$this->visibilityService->isUserTargetAttendee($appointment, $userId),
		);

		$appointmentData = $this->serializeAppointment($appointment);
		$appointmentData = $this->enrichVisibilityData($appointmentData);
		$appointmentData = $this->enrichSeriesCount($appointmentData, $appointment);
		// Only expose userResponse when the user actually answered yes/no/maybe.
		// A row with response=NULL exists when an admin checked the user in
		// before they ever responded; treat that as "no response" (matches list endpoint).
		$userResponse = $this->getUserResponse($appointment->getId(), $userId);
		$appointmentData['userResponse'] = $this->serializeUserResponse($userResponse, $appointment);
		$responseSummary = $this->buildResponseSummaryFor($myPermissions, $appointment->getId());
		if ($responseSummary !== null) {
			$appointmentData['responseSummary'] = $responseSummary;
		}
		$appointmentData['attachments'] = $this->attachmentService->getAttachments($appointment->getId());
		$appointmentData['myPermissions'] = $myPermissions;
		$appointmentData = $this->hideTalkRoomFromOutsiders($appointmentData, $myPermissions['canEdit'], $userResponse);

		return $appointmentData;
	}

	/**
	 * Strip the Talk room token for viewers who are not in the room. They would
	 * otherwise be shown a room they cannot enter — Talk refuses a non-member,
	 * so the badge and its link would dead-end.
	 *
	 * @param array $appointmentData The serialized appointment data
	 * @return array The appointment data, token cleared for non-members
	 */
	private function hideTalkRoomFromOutsiders(array $appointmentData, bool $canManage, ?AttendanceResponse $ownResponse): array {
		if (($appointmentData['talkRoomToken'] ?? null) !== null
			&& !$this->talkRoomService->belongsInRoom($canManage, $ownResponse)) {
			$appointmentData['talkRoomToken'] = null;
		}

		return $appointmentData;
	}

	/**
	 * The summary tier the viewer gets on this appointment: the full overview,
	 * the aggregate counts, or nothing.
	 *
	 * @param array{isOrganizer: bool, isAttendee: bool, canEdit: bool, canSeeResponses: bool, canSeeResponseCounts: bool, canSeeComments: bool, canSeeAuditLog: bool} $myPermissions
	 */
	private function buildResponseSummaryFor(array $myPermissions, int $appointmentId): ?array {
		if ($myPermissions['canSeeResponses']) {
			return $this->responseSummaryService->getResponseSummary($appointmentId, $myPermissions['canSeeComments']);
		}
		if ($myPermissions['canSeeResponseCounts']) {
			return $this->responseSummaryService->getResponseCounts($appointmentId);
		}
		return null;
	}

	/**
	 * Per-appointment permissions of the requesting user, shipped with every
	 * appointment payload so clients gate their UI on the appointment context
	 * instead of the global flags.
	 *
	 * Composes the same "global permission OR organizer" rules as
	 * PermissionService::canManageAppointment()/canSeeResponseOverviewFor(),
	 * but takes the global flags precomputed so the list endpoint does not
	 * redo the group lookups per appointment.
	 *
	 * @param bool $isAttendee In the audience, i.e. asked to answer
	 * @return array{isOrganizer: bool, isAttendee: bool, canEdit: bool, canSeeResponses: bool, canSeeResponseCounts: bool, canSeeComments: bool, canSeeAuditLog: bool}
	 */
	private function buildMyPermissions(
		Appointment $appointment,
		string $userId,
		bool $globalManage,
		bool $globalSeeResponses,
		bool $globalSeeComments,
		bool $globalSeeCounts,
		bool $isAttendee,
	): array {
		$isOrganizer = $this->permissionService->isOrganizer($appointment, $userId);
		$canEdit = $globalManage || $isOrganizer;
		$canSeeResponses = $globalSeeResponses || $isOrganizer;

		// Mirrors AuditController's gate — computed server-side because the
		// audit visibility mode is admin config the clients don't know.
		$canSeeAuditLog = $this->configService->isAuditLogEnabled()
			&& ($this->configService->getAuditLogVisibility() === ConfigService::AUDIT_LOG_VISIBILITY_ALL_WITH_OVERVIEW
				? $canSeeResponses
				: $canEdit);

		return [
			'isOrganizer' => $isOrganizer,
			'isAttendee' => $isAttendee,
			'canEdit' => $canEdit,
			'canSeeResponses' => $canSeeResponses,
			'canSeeResponseCounts' => $globalSeeCounts || $canSeeResponses,
			'canSeeComments' => $globalSeeComments || $isOrganizer,
			'canSeeAuditLog' => $canSeeAuditLog,
		];
	}

	/**
	 * The user's own response as the clients receive it, carrying the booking
	 * verdict they were actually told rather than the raw column.
	 *
	 * @return array<string, mixed>|null
	 */
	private function serializeUserResponse(?AttendanceResponse $userResponse, ?Appointment $appointment = null): ?array {
		if ($userResponse === null || $userResponse->getResponse() === null) {
			return null;
		}
		return $this->serializeResponse($userResponse, $appointment);
	}

	/**
	 * A response as a client sees it: the row, the scheduling verdict, and
	 * whether this yes holds a spot or a place in line. The standing is derived,
	 * so it moves on its own as other people change their answers.
	 *
	 * @return array<string, mixed>
	 */
	public function serializeResponse(AttendanceResponse $response, ?Appointment $appointment = null): array {
		/** @var array<string, mixed> $data */
		$data = $response->jsonSerialize();
		$data['bookingStatus'] = $this->bookingService->effectiveBookingStatus($response);
		$standing = $appointment === null
			? ['waitlisted' => false, 'waitlistPosition' => null]
			: $this->capacityService->standingOf($appointment, $response->getUserId());
		return $data + $standing;
	}

	/**
	 * Get all appointments.
	 */
	public function getAllAppointments(): array {
		return $this->appointmentMapper->findAll();
	}

	/**
	 * Get upcoming appointments.
	 *
	 * @return list<Appointment>
	 */
	public function getUpcomingAppointments(): array {
		return $this->appointmentMapper->findUpcoming();
	}

	/**
	 * Get past appointments.
	 *
	 * @return list<Appointment>
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
	 * Distinct, previously-used locations for autocomplete suggestions,
	 * restricted to appointments the user may see, most recent first.
	 *
	 * @return list<string>
	 */
	public function getLocationSuggestions(string $userId, int $limit = 20): array {
		// Holders of see_all_appointments see every appointment anyway
		// (canUserSeeAppointment's own bypass), so skip the per-appointment
		// check entirely for them.
		$canSeeAll = $this->permissionService->canSeeAllAppointments($userId);

		$suggestions = [];
		foreach ($this->appointmentMapper->findWithLocation() as $appointment) {
			if (!$canSeeAll && !$this->visibilityService->canUserSeeAppointment($appointment, $userId)) {
				continue;
			}
			$location = $appointment->getLocation();
			if ($location === null || in_array($location, $suggestions, true)) {
				continue;
			}
			$suggestions[] = $location;
			if (count($suggestions) >= $limit) {
				break;
			}
		}

		return $suggestions;
	}

	/**
	 * Submit attendance response.
	 *
	 * Pass $response = null to withdraw an existing response: the row stays
	 * (so any admin check-in data is preserved) but response/comment are
	 * cleared so the user is treated as "not yet responded" again.
	 */
	public function submitResponse(
		int $appointmentId,
		string $userId,
		?string $response,
		string $comment = '',
		bool $acceptWaitlist = false,
	): AttendanceResponse {
		$this->validateResponseValue($response);

		$appointment = $this->appointmentMapper->find($appointmentId);

		if (!$this->visibilityService->canUserSeeAppointment($appointment, $userId)) {
			throw new DoesNotExistException('Appointment not found');
		}

		return $this->applyResponse(
			$appointment,
			$userId,
			$response,
			$comment,
			null,
			\OCA\Attendance\Audit\Verb::SOURCE_CLIENT,
			null,
			$acceptWaitlist,
		);
	}

	/**
	 * Submit or clear an attendance response on behalf of another user.
	 *
	 * Managers use this when a person announced their answer out of band
	 * (e.g. on the phone) and did not respond themselves. The target user's
	 * own comment survives a value change — only the manager-visible answer
	 * is touched. Withdrawing ($response = null) restores the "not yet
	 * responded" state so the person is picked up by reminders again, and
	 * clears the comment like a self-withdraw would.
	 *
	 * Callers load (and authorize) the appointment themselves, so it is
	 * passed in rather than re-fetched.
	 */
	public function submitResponseForUser(
		Appointment $appointment,
		string $targetUserId,
		?string $response,
		string $actorUserId,
	): AttendanceResponse {
		$this->validateResponseValue($response);

		// The audience check below trusts the permission layer, which treats
		// unknown users as managers when permissions are unconfigured — so an
		// explicit existence check is needed to keep junk rows out.
		if ($this->userManager->get($targetUserId) === null) {
			throw new \InvalidArgumentException('User not found');
		}

		// The target must at least see the appointment; whether they may be
		// given an answer is the response policy's call in applyResponse().
		if (!$this->visibilityService->canUserSeeAppointment($appointment, $targetUserId)) {
			throw new \InvalidArgumentException('User is not part of this appointment\'s audience');
		}

		return $this->applyResponse(
			$appointment,
			$targetUserId,
			$response,
			null,
			ResponseService::SOURCE_ADMIN,
			\OCA\Attendance\Audit\Verb::SOURCE_ADMIN_RESPONSE,
			$actorUserId,
		);
	}

	private function validateResponseValue(?string $response): void {
		if ($response !== null && !in_array($response, ['yes', 'no', 'maybe'])) {
			throw new \InvalidArgumentException('Invalid response. Must be yes, no, maybe, or null.');
		}
	}

	/**
	 * Shared persist-and-audit core for self and on-behalf responses.
	 *
	 * @param ?string $comment new comment, or null to keep the existing one
	 *                         (withdrawing always wipes it so an orphan comment
	 *                         can't survive the response that gave it context)
	 * @param ?string $responseSource value for response_source, or null to leave it untouched
	 * @param ?string $actorUserId audit actor when acting on someone else's behalf
	 * @param bool $acceptWaitlist take a place in line when the appointment is
	 *                             already full, rather than being turned away
	 */
	private function applyResponse(
		Appointment $appointment,
		string $userId,
		?string $response,
		?string $comment,
		?string $responseSource,
		string $auditSource,
		?string $actorUserId,
		bool $acceptWaitlist = false,
	): AttendanceResponse {
		$appointmentId = $appointment->getId();

		if ($appointment->isClosed()) {
			throw new \RuntimeException('This appointment is closed and no longer accepts responses.');
		}

		// A called-off appointment will not take place, so there is nothing left
		// to answer. The clients hide the controls, but notification actions and
		// older app versions reach this endpoint directly.
		if ($appointment->isCancelled()) {
			throw new \RuntimeException('This appointment was cancelled and no longer accepts responses.');
		}

		$existingResponse = null;
		try {
			$existingResponse = $this->responseMapper->findByAppointmentAndUser($appointmentId, $userId);
		} catch (DoesNotExistException $e) {
			// First answer from this person.
		}

		$beforeResponse = $existingResponse?->getResponse();
		$beforeComment = $existingResponse === null ? '' : (string)$existingResponse->getComment();

		// Same guards ResponseService::submitResponse() runs for quick-response
		// links, so neither path can accept an answer the other rejects. An
		// organizer answering for somebody else may exceed a limit.
		$this->responsePolicyService->assertResponseAllowed(
			$appointment,
			$userId,
			$response,
			$beforeResponse,
			$acceptWaitlist,
			$actorUserId !== null,
		);

		if ($response === null) {
			$comment = '';
		}

		if ($existingResponse !== null) {
			// Withdrawing an already-withdrawn (or never-set) response is a no-op:
			// don't churn responded_at or notification state on repeated clicks.
			if ($response === null && $beforeResponse === null) {
				return $existingResponse;
			}

			$afterComment = $comment ?? $beforeComment;
			$existingResponse->setResponse($response);
			$existingResponse->setComment($afterComment);
			$existingResponse->setRespondedAt(gmdate('Y-m-d H:i:s'));
			if ($responseSource !== null) {
				$existingResponse->setResponseSource($responseSource);
			}
			$this->capacityService->applySpotClaim($existingResponse, $beforeResponse, $response);
			$result = $this->responseMapper->update($existingResponse);
		} else {
			// No row to withdraw from — return a transient placeholder rather
			// than inserting a junk row that filters would have to skip later.
			if ($response === null) {
				$placeholder = new AttendanceResponse();
				$placeholder->setAppointmentId($appointmentId);
				$placeholder->setUserId($userId);
				$placeholder->setResponse(null);
				return $placeholder;
			}
			$afterComment = $comment ?? '';
			$attendanceResponse = new AttendanceResponse();
			$attendanceResponse->setAppointmentId($appointmentId);
			$attendanceResponse->setUserId($userId);
			$attendanceResponse->setResponse($response);
			$attendanceResponse->setComment($afterComment);
			$attendanceResponse->setRespondedAt(gmdate('Y-m-d H:i:s'));
			if ($responseSource !== null) {
				$attendanceResponse->setResponseSource($responseSource);
			}
			$this->capacityService->applySpotClaim($attendanceResponse, null, $response);
			$result = $this->responseMapper->insert($attendanceResponse);
		}

		// The queue moved: a withdrawn yes frees a spot for whoever is next, a
		// fresh one may have taken the last.
		$this->capacityService->syncWaitlistNotifications($appointment);

		$this->auditEventService->recordResponseChange(
			$appointmentId,
			$userId,
			$beforeResponse,
			$beforeComment,
			$response,
			$afterComment,
			$auditSource,
			$actorUserId,
		);

		// The person no longer counts as "missing a response", so their
		// pending respond-notifications are stale either way; on a clear the
		// next reminder run will re-notify them.
		$this->notificationService->markAppointmentNotificationsProcessed($appointmentId, $userId);

		// Keep the response summary in the organization calendar event current
		$this->orgCalendarSyncService->syncAppointment($appointment);

		// A withdrawn yes has to close the Talk room behind them, and without a
		// planning mode a fresh yes may be what opens the room in the first
		// place. This is the path the web and mobile clients take; the
		// quick-response links go through ResponseService.
		$this->talkRoomService->openOrSync($appointment);

		return $result;
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
			$responseUserId = $response->getUserId();

			// A former target attendee keeps their recorded response after
			// leaving the group/team that made the appointment visible to
			// them (issue #213) — only a manager's test response on an
			// appointment they were never part of is filtered.
			if (!$this->visibilityService->isUserTargetAttendee($appointment, $responseUserId)
				&& $this->permissionService->canManageAppointments($responseUserId)) {
				continue;
			}

			$user = $this->userManager->get($response->getUserId());
			$responseData = $response->jsonSerialize();
			$responseData['userName'] = $user ? $user->getDisplayName() : $response->getUserId();

			if ($user) {
				$userGroups = $this->groupManager->getUserGroups($user);
				$responseData['userGroups'] = array_map(fn ($group) => $group->getGID(), $userGroups);
			} else {
				$responseData['userGroups'] = [];
			}

			$result[] = $responseData;
		}

		return $result;
	}

	/**
	 * Get minimal appointment data for navigation menu. Scoped to the user's
	 * own appointments: every sidebar section it feeds ("Unanswered", "My
	 * appointments", "Past appointments") is a personal list, and for a holder
	 * of see_all_appointments the unscoped one would be the whole instance.
	 *
	 * @param ?int $pastLimit Page size for the past entries, which grow without
	 *                        bound; null returns all of them
	 * @return array{current: list<array<string, mixed>>, past: list<array<string, mixed>>, pastHasMore: bool}
	 */
	public function getAppointmentsForNavigation(string $userId, ?int $pastLimit = null, int $pastOffset = 0): array {
		$current = $this->buildNavigationData($this->ownAppointments($this->getUpcomingAppointments(), $userId), $userId);

		if ($pastLimit === null) {
			return [
				'current' => $current,
				'past' => $this->buildNavigationData($this->ownAppointments($this->getPastAppointments(), $userId), $userId),
				'pastHasMore' => false,
			];
		}

		$pastLimit = max(0, min(AppointmentListQuery::MAX_LIMIT, $pastLimit));
		$pastOffset = max(0, $pastOffset);
		// One more than the page holds tells whether another page follows.
		$wanted = $pastOffset + $pastLimit + 1;

		// Read in chunks and stop once the page is full, so an instance's whole
		// history is only walked by somebody who pages that far.
		$own = [];
		for ($chunkStart = 0; count($own) < $wanted; $chunkStart += self::NAVIGATION_CHUNK_SIZE) {
			$chunk = $this->appointmentMapper->findPast(self::NAVIGATION_CHUNK_SIZE, $chunkStart);
			array_push($own, ...$this->ownAppointments($chunk, $userId));
			if (count($chunk) < self::NAVIGATION_CHUNK_SIZE) {
				break;
			}
		}

		return [
			'current' => $current,
			'past' => $this->buildNavigationData(array_slice($own, $pastOffset, $pastLimit), $userId),
			'pastHasMore' => count($own) > $pastOffset + $pastLimit,
		];
	}

	/**
	 * The appointments addressed to the user or organized by them, each with
	 * the audience verdict the navigation payload carries anyway.
	 *
	 * @param array<Appointment> $appointments
	 * @return list<array{appointment: Appointment, inAudience: bool}>
	 */
	private function ownAppointments(array $appointments, string $userId): array {
		$own = [];
		foreach ($appointments as $appointment) {
			$inAudience = $this->visibilityService->isUserTargetAttendee($appointment, $userId);
			if ($inAudience || $this->permissionService->isOrganizer($appointment, $userId)) {
				$own[] = ['appointment' => $appointment, 'inAudience' => $inAudience];
			}
		}

		return $own;
	}

	/**
	 * @param list<array{appointment: Appointment, inAudience: bool}> $own
	 * @return list<array<string, mixed>>
	 */
	private function buildNavigationData(array $own, string $userId): array {
		$responses = $this->responseMapper->findByUserForAppointments(
			$userId,
			array_map(static fn (array $entry): int => $entry['appointment']->getId(), $own),
		);

		$result = [];
		foreach ($own as ['appointment' => $appointment, 'inAudience' => $inAudience]) {
			$userResponse = $responses[$appointment->getId()] ?? null;

			$result[] = [
				'id' => $appointment->getId(),
				'name' => $appointment->getName(),
				'startDatetime' => $this->formatDatetimeToUtc($appointment->getStartDatetime()),
				'userResponse' => ($userResponse && $userResponse->getResponse() !== null)
					? ['response' => $userResponse->getResponse()]
					: null,
				'closedAt' => $this->formatDatetimeToUtc($appointment->getClosedAt()),
				'cancelledAt' => $this->formatDatetimeToUtc($appointment->getCancelledAt()),
				'inAudience' => $inAudience,
			];
		}

		return $result;
	}

	/**
	 * Get appointments with user responses — the whole list of one timeframe.
	 * See getAppointmentPage() for the parameters.
	 *
	 * @return list<array<array-key, mixed>>
	 */
	public function getAppointmentsWithUserResponses(
		string $userId,
		bool $showPastAppointments = false,
		bool $unansweredOnly = false,
		bool $onlyForMe = false,
		bool $includeResponseSummary = false,
		bool $includeComments = false,
		bool $includeResponseCounts = false,
		bool $notScheduledOut = false,
		bool $onlyScheduled = false,
	): array {
		return $this->getAppointmentPage(
			$userId,
			AppointmentListQuery::fromWire(
				$showPastAppointments ? AppointmentListQuery::TIMEFRAME_PAST : AppointmentListQuery::TIMEFRAME_UPCOMING,
				$unansweredOnly,
				$onlyForMe,
				$notScheduledOut,
				$onlyScheduled,
			),
			$includeResponseSummary,
			$includeComments,
			$includeResponseCounts,
		)['appointments'];
	}

	/**
	 * The appointment list for a query: the page it asks for (or everything,
	 * unpaged), with what a client needs to page on and to offer its filters.
	 * Deciding who sees what covers every appointment in the timeframe — the
	 * total depends on it — but stays cheap; summaries, attachments and display
	 * names are only built for the appointments actually returned.
	 *
	 * The scope flags on the query:
	 * - unansweredOnly drops closed inquiries and anything the user answered.
	 * - onlyForMe keeps the appointments addressed to the user or organized by
	 *   them — the predicate of VisibilityService::isUserOwnAppointment, so it
	 *   bypasses the see_all_appointments "see everything" bypass.
	 * - notScheduledOut / onlyScheduled follow BookingService::isScheduledOut
	 *   and isScheduledIn; both imply onlyForMe.
	 *
	 * @param bool $includeResponseSummary Attach the full response overview
	 *                                     (other attendees' answers and roster) to each appointment. Gate on
	 *                                     the caller's PERMISSION_SEE_RESPONSE_OVERVIEW.
	 * @param bool $includeComments Include free-text comments in that overview.
	 *                              Gate on the caller's PERMISSION_SEE_COMMENTS.
	 * @param bool $includeResponseCounts Attach the aggregate counts (no names)
	 *                                    when the full overview is withheld. Gate on
	 *                                    the caller's PERMISSION_SEE_RESPONSE_COUNTS.
	 * @return array{
	 *     appointments: list<array<array-key, mixed>>,
	 *     total: int,
	 *     unansweredCount: ?int,
	 *     facets: array{locations: list<string>, categoryIds: list<int>, roles: list<string>},
	 * }
	 */
	public function getAppointmentPage(
		string $userId,
		AppointmentListQuery $query,
		bool $includeResponseSummary = false,
		bool $includeComments = false,
		bool $includeResponseCounts = false,
	): array {
		$rows = $this->scopedListRows($userId, $query);

		/** @var array<int, ?AttendanceResponse> $responses */
		$responses = [];
		if (!$query->isPaged() || $query->unansweredOnly || $query->response !== null) {
			$this->loadUserResponses($responses, $userId, $rows);
		}

		$unansweredCount = $query->isPaged() && $query->coversUpcoming()
			? $this->countUnanswered($responses, $userId, $rows)
			: null;

		if ($query->unansweredOnly) {
			$rows = array_values(array_filter(
				$rows,
				static fn (array $row): bool => !self::hasAnswered($responses[$row['appointment']->getId()] ?? null),
			));
		}
		if ($query->notScheduledOut) {
			$rows = array_values(array_filter(
				$rows,
				fn (array $row): bool => !$this->bookingService->isScheduledOut($row['appointment'], $userId),
			));
		}
		if ($query->onlyScheduled) {
			$rows = array_values(array_filter(
				$rows,
				fn (array $row): bool => $this->bookingService->isScheduledIn($row['appointment'], $userId),
			));
		}

		// Taken before the filters below, so picking one location still leaves
		// the others on offer.
		$facets = $this->listFacets($rows);
		// A role that is not on offer must not keep filtering invisibly.
		$role = in_array($query->role, $facets['roles'], true) ? $query->role : null;

		$rows = array_values(array_filter(
			$rows,
			static fn (array $row): bool => ($role === null || self::hasRole($row, $role))
				&& self::matchesListFilters(
					$row['appointment'],
					$responses[$row['appointment']->getId()] ?? null,
					$query,
				),
		));

		if ($query->cancelledLast) {
			$rows = [
				...array_filter($rows, static fn (array $row): bool => !$row['appointment']->isCancelled()),
				...array_filter($rows, static fn (array $row): bool => $row['appointment']->isCancelled()),
			];
		}

		$total = count($rows);
		if ($query->isPaged()) {
			$rows = array_slice($rows, $query->offset, $query->limit);
		}
		$this->loadUserResponses($responses, $userId, $rows);

		$globalManage = $this->permissionService->canManageAppointments($userId);
		$appointments = [];
		foreach ($rows as ['appointment' => $appointment, 'isAttendee' => $isAttendee, 'isPast' => $isPast]) {
			$userResponse = $responses[$appointment->getId()] ?? null;

			$myPermissions = $this->buildMyPermissions(
				$appointment,
				$userId,
				$globalManage,
				$includeResponseSummary,
				$includeComments,
				$includeResponseCounts,
				$isAttendee,
			);

			$appointmentData = $this->serializeAppointment($appointment);
			$appointmentData = $this->enrichVisibilityData($appointmentData);
			$appointmentData = $this->enrichSeriesCount($appointmentData, $appointment);
			$appointmentData['userResponse'] = $this->serializeUserResponse($userResponse, $appointment);
			$responseSummary = $this->buildResponseSummaryFor($myPermissions, $appointment->getId());
			if ($responseSummary !== null) {
				$appointmentData['responseSummary'] = $responseSummary;
			}
			$appointmentData['attachments'] = $this->attachmentService->getAttachments($appointment->getId());
			$appointmentData['myPermissions'] = $myPermissions;
			$appointmentData['isPast'] = $isPast;
			$appointments[] = $this->hideTalkRoomFromOutsiders($appointmentData, $myPermissions['canEdit'], $userResponse);
		}

		return [
			'appointments' => $appointments,
			'total' => $total,
			'unansweredCount' => $unansweredCount,
			'facets' => $facets,
		];
	}

	/**
	 * Every appointment of the query's timeframe that falls into its scope,
	 * in list order: upcoming ones soonest first, then past ones newest first.
	 *
	 * @return list<ListRow>
	 */
	private function scopedListRows(string $userId, AppointmentListQuery $query): array {
		// Hoisted: the list runs over every appointment on the instance, so the
		// group lookups behind this must not repeat per row.
		$canSeeAll = $this->permissionService->canSeeAllAppointments($userId);

		$timeframes = [];
		if ($query->coversUpcoming()) {
			$timeframes[] = [$this->getUpcomingAppointments(), false];
		}
		if ($query->coversPast()) {
			$timeframes[] = [$this->getPastAppointments(), true];
		}

		$rows = [];
		foreach ($timeframes as [$appointments, $isPast]) {
			foreach ($appointments as $appointment) {
				// Three nested scopes, each derived from the one below it:
				// visible ⊇ mine ⊇ asked-to-answer. Evaluated once per appointment
				// rather than through canUserSeeAppointment/isUserOwnAppointment,
				// which would redo the audience check two more times.
				$isAttendee = $this->visibilityService->isUserTargetAttendee($appointment, $userId);
				$isOrganizer = $this->permissionService->isOrganizer($appointment, $userId);
				$isOwn = $isAttendee || $isOrganizer;
				if (!$canSeeAll && !$isOwn) {
					continue;
				}
				if ($query->onlyForMe && !$isOwn) {
					continue;
				}
				// The unanswered inbox is strictly what the user was asked to
				// answer — organizing an appointment is not being asked, and for
				// see-all holders the visibility check otherwise lets through every
				// unanswered appointment in the system.
				if ($query->unansweredOnly && !self::awaitsAnswerFrom($appointment, $isAttendee)) {
					continue;
				}

				$rows[] = ['appointment' => $appointment, 'isAttendee' => $isAttendee, 'isOrganizer' => $isOrganizer, 'isPast' => $isPast];
			}
		}

		return $rows;
	}

	/**
	 * Fetch the user's own responses for the rows not looked up yet, in one
	 * query rather than one per appointment.
	 *
	 * @param array<int, ?AttendanceResponse> $responses Lookup by appointment id, extended in place
	 * @param array<ListRow> $rows
	 */
	private function loadUserResponses(array &$responses, string $userId, array $rows): void {
		$missing = [];
		foreach ($rows as $row) {
			$id = $row['appointment']->getId();
			if (!array_key_exists($id, $responses)) {
				$missing[] = $id;
			}
		}
		if ($missing === []) {
			return;
		}

		$found = $this->responseMapper->findByUserForAppointments($userId, $missing);
		foreach ($missing as $id) {
			$responses[$id] = $found[$id] ?? null;
		}
	}

	/**
	 * How many upcoming appointments still wait for the user's answer — the
	 * size of the unanswered inbox, whatever the query itself filters on.
	 *
	 * @param array<int, ?AttendanceResponse> $responses
	 * @param list<ListRow> $rows
	 */
	private function countUnanswered(array &$responses, string $userId, array $rows): int {
		$awaiting = array_filter(
			$rows,
			static fn (array $row): bool => !$row['isPast'] && self::awaitsAnswerFrom($row['appointment'], $row['isAttendee']),
		);
		$this->loadUserResponses($responses, $userId, $awaiting);

		return count(array_filter(
			$awaiting,
			static fn (array $row): bool => !self::hasAnswered($responses[$row['appointment']->getId()] ?? null),
		));
	}

	private static function awaitsAnswerFrom(Appointment $appointment, bool $isAttendee): bool {
		return $isAttendee && !$appointment->isClosed() && !$appointment->isCancelled();
	}

	/**
	 * A row with response=NULL exists when somebody was checked in before they
	 * ever answered; that is still "no response".
	 */
	private static function hasAnswered(?AttendanceResponse $response): bool {
		return $response !== null && $response->getResponse() !== null;
	}

	/**
	 * What the clients' filter controls can offer for these rows — a paged
	 * client no longer holds the list to work that out itself.
	 *
	 * @param list<ListRow> $rows
	 * @return array{locations: list<string>, categoryIds: list<int>, roles: list<string>}
	 */
	private function listFacets(array $rows): array {
		$locations = [];
		$categoryIds = [];
		foreach ($rows as $row) {
			$location = $row['appointment']->getLocation();
			if ($location !== null && $location !== '') {
				$locations[$location] = true;
			}
			$categoryId = $row['appointment']->getCategoryId();
			if ($categoryId !== null) {
				$categoryIds[$categoryId] = true;
			}
		}

		$locations = array_map('strval', array_keys($locations));
		sort($locations, SORT_STRING);

		// A role is only worth offering while it tells some appointments apart
		// from others — somebody simply invited everywhere gets none at all.
		$roles = [];
		foreach (AppointmentListQuery::ROLES as $role) {
			$matching = count(array_filter($rows, static fn (array $row): bool => self::hasRole($row, $role)));
			if ($matching > 0 && $matching < count($rows)) {
				$roles[] = $role;
			}
		}

		return ['locations' => $locations, 'categoryIds' => array_keys($categoryIds), 'roles' => $roles];
	}

	/**
	 * @param ListRow $row
	 */
	private static function hasRole(array $row, string $role): bool {
		return match ($role) {
			AppointmentListQuery::ROLE_ATTENDEE => $row['isAttendee'],
			AppointmentListQuery::ROLE_ORGANIZER => $row['isOrganizer'],
			default => !$row['isAttendee'] && !$row['isOrganizer'],
		};
	}

	private static function matchesListFilters(Appointment $appointment, ?AttendanceResponse $userResponse, AppointmentListQuery $query): bool {
		// "Open"/"closed" are about inquiries that still take responses, which
		// a cancelled one does not — it only ever matches "cancelled".
		$status = match (true) {
			$appointment->isCancelled() => AppointmentListQuery::STATUS_CANCELLED,
			$appointment->isClosed() => AppointmentListQuery::STATUS_CLOSED,
			default => AppointmentListQuery::STATUS_OPEN,
		};
		if ($query->status !== null && $query->status !== $status) {
			return false;
		}

		if ($query->response !== null
			&& ($userResponse?->getResponse() ?? AppointmentListQuery::RESPONSE_NONE) !== $query->response) {
			return false;
		}

		if ($query->locations !== [] && !in_array($appointment->getLocation(), $query->locations, true)) {
			return false;
		}
		if ($query->categoryIds !== [] && !in_array($appointment->getCategoryId(), $query->categoryIds, true)) {
			return false;
		}

		return $query->search === ''
			|| mb_stripos($appointment->getName() . ' ' . ($appointment->getDescription() ?? ''), $query->search) !== false;
	}

	/**
	 * Get upcoming appointments for the dashboard widget. Restricted to
	 * appointments the user is a target attendee of (incl. unrestricted
	 * "everyone" appointments) — no see-all bypass, so holders don't see noise
	 * from every appointment in the system. Deliberately narrower than the
	 * list's "My appointments" scope: the widget is what is asked of you, so
	 * appointments you only organize stay out of it.
	 */
	public function getUpcomingAppointmentsForWidget(string $userId, int $limit = 5): array {
		$appointments = $this->getUpcomingAppointments();
		$result = [];
		$count = 0;

		foreach ($appointments as $appointment) {
			if ($count >= $limit) {
				break;
			}
			if ($appointment->isCancelled()) {
				continue;
			}
			if (!$this->visibilityService->isUserTargetAttendee($appointment, $userId)) {
				continue;
			}
			if ($this->bookingService->isScheduledOut($appointment, $userId)) {
				continue;
			}

			$appointmentData = $this->serializeAppointment($appointment);
			$appointmentData = $this->enrichVisibilityData($appointmentData);
			$userResponse = $this->getUserResponse($appointment->getId(), $userId);
			$appointmentData['userResponse'] = $this->serializeUserResponse($userResponse, $appointment);
			$appointmentData['attachments'] = $this->attachmentService->getAttachments($appointment->getId());
			$appointmentData = $this->hideTalkRoomFromOutsiders(
				$appointmentData,
				$this->permissionService->canManageAppointment($userId, $appointment),
				$userResponse,
			);
			$result[] = $appointmentData;
			$count++;
		}

		return $result;
	}

	/**
	 * Search for users, groups, and teams (circles).
	 * Uses the ICollaboratorSearch interface to include all registered share types.
	 */
	public function searchUsersGroupsTeams(string $search = ''): array {
		$shareTypes = [
			IShare::TYPE_USER,
			IShare::TYPE_GROUP,
		];

		// Add circles/teams if the app is enabled
		if ($this->appManager->isEnabledForUser('circles')) {
			$shareTypes[] = IShare::TYPE_CIRCLE;
		}

		[$searchResult, $hasMore] = $this->collaboratorSearch->search(
			$search,
			$shareTypes,
			false, // lookup
			// Bound the page so an empty/very-short query on a large directory
			// can't fan out to every user/group/team. 200 leaves plenty of
			// headroom for a typical org while keeping the response bounded.
			200,
			0
		);

		return $this->formatCollaboratorResults($searchResult);
	}

	/**
	 * Format collaborator search results into a consistent format.
	 */
	private function formatCollaboratorResults($searchResult): array {
		$results = [];
		// Handle both ISearchResult object and array returns
		$resultData = is_array($searchResult) ? $searchResult : $searchResult->asArray();

		// Process exact matches and regular matches
		foreach (['exact', 'users', 'groups', 'circles'] as $category) {
			$items = [];

			if ($category === 'exact') {
				// Exact matches are nested by type
				$exactMatches = $resultData['exact'] ?? [];
				foreach (['users', 'groups', 'circles'] as $subCategory) {
					if (isset($exactMatches[$subCategory])) {
						$items = array_merge($items, $exactMatches[$subCategory]);
					}
				}
			} else {
				$items = $resultData[$category] ?? [];
			}

			foreach ($items as $item) {
				$shareType = $item['value']['shareType'] ?? null;
				$type = $this->mapShareTypeToString($shareType);

				if ($type === null) {
					continue;
				}

				$id = $item['value']['shareWith'] ?? $item['shareWith'] ?? '';
				$results[] = [
					'id' => $id,
					'label' => $item['label'] ?? '',
					'type' => $type,
					'icon' => $this->getIconForType($type),
					'isGuest' => $type === 'user' && $id !== ''
						? $this->guestService->isGuestUser((string)$id)
						: false,
				];
			}
		}

		// Remove duplicates based on id + type
		$seen = [];
		$uniqueResults = [];
		foreach ($results as $result) {
			$key = $result['type'] . ':' . $result['id'];
			if (!isset($seen[$key])) {
				$seen[$key] = true;
				$uniqueResults[] = $result;
			}
		}

		return $uniqueResults;
	}

	/**
	 * Map share type integer to string type.
	 */
	private function mapShareTypeToString(?int $shareType): ?string {
		return match ($shareType) {
			IShare::TYPE_USER => 'user',
			IShare::TYPE_GROUP => 'group',
			IShare::TYPE_CIRCLE => 'team',
			default => null,
		};
	}

	/**
	 * Get icon class for a given type.
	 */
	private function getIconForType(string $type): string {
		return match ($type) {
			'user' => 'icon-user',
			'group' => 'icon-group',
			'team' => 'icon-team',
			default => 'icon-user',
		};
	}

	/**
	 * Get user IDs of users who have not responded to an appointment.
	 *
	 * @param Appointment $appointment The appointment
	 * @return list<string> User IDs that have not responded
	 */
	public function getNonRespondingUserIds(Appointment $appointment): array {
		$appointmentId = $appointment->getId();

		// Get all responses for this appointment. Checking somebody in writes a
		// row without an answer, so having a row is a different question:
		// those people are still non-responders, which is how the summary and
		// the 'both' target below list them.
		$responses = $this->responseMapper->findByAppointment($appointmentId);
		$respondedUserIds = [];
		foreach ($responses as $response) {
			if ($response->getResponse() !== null) {
				$respondedUserIds[$response->getUserId()] = true;
			}
		}

		// Get all relevant users
		$whitelistedGroups = $this->configService->getWhitelistedGroups();
		$relevantUsers = $this->visibilityService->getRelevantUsersForAppointment($appointment, $whitelistedGroups);

		$nonResponding = [];
		foreach ($relevantUsers as $userId => $user) {
			if (!isset($respondedUserIds[$userId])) {
				$nonResponding[] = $userId;
			}
		}

		return $nonResponding;
	}

	/**
	 * Get user IDs of users who responded "maybe" to an appointment.
	 *
	 * @param Appointment $appointment The appointment
	 * @return list<string> User IDs that responded with "maybe"
	 */
	public function getMaybeRespondingUserIds(Appointment $appointment): array {
		$appointmentId = $appointment->getId();

		$responses = $this->responseMapper->findByAppointment($appointmentId);
		$maybeUserIds = [];
		foreach ($responses as $response) {
			if ($response->getResponse() === 'maybe') {
				$maybeUserIds[$response->getUserId()] = true;
			}
		}

		// Filter to only relevant/visible users
		$whitelistedGroups = $this->configService->getWhitelistedGroups();
		$relevantUsers = $this->visibilityService->getRelevantUsersForAppointment($appointment, $whitelistedGroups);

		$result = [];
		foreach ($relevantUsers as $userId => $user) {
			if (isset($maybeUserIds[$userId])) {
				$result[] = $userId;
			}
		}

		return $result;
	}

	/**
	 * Get user IDs to remind based on the target setting.
	 *
	 * @param Appointment $appointment The appointment
	 * @param string $target One of 'non_responders', 'maybe', 'both'
	 * @return list<string> User IDs to remind
	 */
	public function getReminderTargetUserIds(Appointment $appointment, string $target): array {
		if ($target === 'non_responders') {
			return $this->getNonRespondingUserIds($appointment);
		}
		if ($target === 'maybe') {
			return $this->getMaybeRespondingUserIds($appointment);
		}

		// 'both': single pass to avoid duplicate DB queries
		$responses = $this->responseMapper->findByAppointment($appointment->getId());
		$responseByUser = [];
		foreach ($responses as $response) {
			$responseByUser[$response->getUserId()] = $response->getResponse();
		}

		$whitelistedGroups = $this->configService->getWhitelistedGroups();
		$relevantUsers = $this->visibilityService->getRelevantUsersForAppointment($appointment, $whitelistedGroups);

		$result = [];
		foreach ($relevantUsers as $userId => $user) {
			$userResponse = $responseByUser[$userId] ?? null;
			if ($userResponse === null || $userResponse === 'maybe') {
				$result[] = $userId;
			}
		}

		return $result;
	}

	/**
	 * Get all users who can see an appointment.
	 */
	public function getAffectedUsers(Appointment $appointment): array {
		$visibleUsers = $appointment->getVisibleUsers();
		$visibleGroups = $appointment->getVisibleGroups();
		$visibleTeams = $appointment->getVisibleTeams();

		$visibleUsersList = $visibleUsers ? (array)json_decode($visibleUsers, true) : [];
		$visibleGroupsList = $visibleGroups ? (array)json_decode($visibleGroups, true) : [];
		$visibleTeamsList = $visibleTeams ? (array)json_decode($visibleTeams, true) : [];

		return $this->resolveAudience($visibleUsersList, $visibleGroupsList, $visibleTeamsList);
	}

	/**
	 * The users an audience selection addresses; no selection at all means
	 * everyone in the whitelist.
	 */
	private function resolveAudience(array $visibleUsersList, array $visibleGroupsList, array $visibleTeamsList): array {
		if (empty($visibleUsersList) && empty($visibleGroupsList) && empty($visibleTeamsList)) {
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

		// Get users from teams via VisibilityService
		foreach ($visibleTeamsList as $teamId) {
			$teamUsers = $this->visibilityService->getTeamMembers($teamId);
			$userIds = array_merge($userIds, $teamUsers);
		}

		return array_unique($userIds);
	}

	/**
	 * Get all users in whitelisted groups (or everyone, when no whitelist is
	 * configured) — the app-wide notion of "everyone", also used by
	 * VacationController to scope the team vacation overview and the
	 * create-appointment conflict hint when no narrower audience is given.
	 *
	 * @return list<string>
	 */
	public function getAllWhitelistedUsers(): array {
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

		return array_values(array_unique($userIds));
	}

	/**
	 * The appointment as a client sees it. The entity cannot resolve allowMaybe
	 * on its own — the tri-state column has to be read against the instance
	 * default — so payloads go through here instead of jsonSerialize().
	 *
	 * @return array<string, mixed>
	 */
	public function serializeAppointment(Appointment $appointment): array {
		return $this->appointmentSerializer->serialize($appointment);
	}

	/**
	 * A limit of zero or less is how a client says "no limit"; the column keeps
	 * NULL for that so every reader has one way to ask.
	 */
	private function normalizeAttendeeLimit(?int $maxAttendees): ?int {
		return $maxAttendees === null || $maxAttendees <= 0 ? null : $maxAttendees;
	}

	/**
	 * Enrich appointment data with series count.
	 */
	private function enrichSeriesCount(array $appointmentData, Appointment $appointment): array {
		$seriesId = $appointment->getSeriesId();
		if ($seriesId !== null) {
			$siblings = $this->appointmentMapper->findBySeriesId($seriesId);
			$appointmentData['seriesCount'] = count($siblings);
		} else {
			$appointmentData['seriesCount'] = 0;
		}
		return $appointmentData;
	}

	/**
	 * Validate that end datetime is after start datetime.
	 */
	private function validateDateRange(string $startDatetime, string $endDatetime): void {
		try {
			$start = new \DateTime($startDatetime);
			$end = new \DateTime($endDatetime);
			if ($end <= $start) {
				throw new \InvalidArgumentException('End date must be after start date.');
			}
		} catch (\InvalidArgumentException $e) {
			throw $e;
		} catch (\Exception $e) {
			throw new \InvalidArgumentException('Invalid date format.');
		}
	}

	/**
	 * Convert ISO 8601 datetime to MySQL format in UTC.
	 */
	private function formatDatetime(string $datetime): string {
		try {
			$date = new \DateTime($datetime);
			$date->setTimezone(new \DateTimeZone('UTC'));
			return $date->format('Y-m-d H:i:s');
		} catch (\Exception $e) {
			return $datetime;
		}
	}

	/**
	 * Normalise an optional datetime input (response deadline).
	 *
	 * - Empty string → null (deadline cleared).
	 * - Past deadline → InvalidArgumentException (would auto-close immediately
	 *   on the next cron tick, almost always a mistake). 60s grace absorbs
	 *   client/server clock skew.
	 * - Deadline after start → clamped to start (we never auto-close after the
	 *   appointment has begun).
	 *
	 * @param string $startFormatted Already-formatted start datetime (UTC, Y-m-d H:i:s).
	 * @throws \InvalidArgumentException
	 */
	private function normalizeOptionalDatetime(?string $datetime, string $startFormatted): ?string {
		if ($datetime === null || $datetime === '') {
			return null;
		}
		$formatted = $this->formatDatetime($datetime);
		$nowWithGrace = gmdate('Y-m-d H:i:s', time() - 60);
		if ($formatted < $nowWithGrace) {
			throw new \InvalidArgumentException('Response deadline must be in the future.');
		}
		if ($formatted > $startFormatted) {
			return $startFormatted;
		}
		return $formatted;
	}

	/**
	 * Enrich visibility data with display names.
	 *
	 * Converts raw user/group/team IDs to objects with id, label, and type.
	 *
	 * @param array $appointmentData The serialized appointment data
	 * @return array The enriched appointment data
	 */
	public function enrichVisibilityData(array $appointmentData): array {
		/** @var list<string> $userIds */
		$userIds = $appointmentData['visibleUsers'] ?? [];
		/** @var list<string> $groupIds */
		$groupIds = $appointmentData['visibleGroups'] ?? [];
		/** @var list<string> $teamIds */
		$teamIds = $appointmentData['visibleTeams'] ?? [];
		$appointmentData = array_merge(
			$appointmentData,
			$this->visibilityService->enrichAudience($userIds, $groupIds, $teamIds),
		);

		$appointmentData['organizers'] = array_map(
			fn (string $userId) => $this->visibilityService->userRef($userId),
			$appointmentData['organizers'] ?? [],
		);

		return $appointmentData;
	}

	/**
	 * Strip HTML tags from markdown text to prevent stored XSS.
	 * Preserves markdown formatting but removes raw HTML that users may embed.
	 */
	private function stripHtmlFromMarkdown(string $text): string {
		return strip_tags($text);
	}
}
