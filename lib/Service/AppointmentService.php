<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
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
 */
class AppointmentService {
	use DatetimeFormatTrait;

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
		$appointment->setCalendarUri($calendarUri);
		$appointment->setCalendarEventUid($calendarEventUid);
		$appointment->setSeriesId($seriesId);
		$appointment->setSeriesPosition($seriesPosition);
		$appointment->setSendNotification($sendNotification);
		$appointment->setResponseDeadline($deadlineFormatted);
		// The creator freely chooses the initial organizer list; when the client
		// sends nothing, the creator becomes the sole organizer.
		$this->applyOrganizerChange(
			$appointment,
			$this->normalizeOrganizers($organizers ?? [$createdBy]),
			$createdBy,
			true,
		);

		$appointment = $this->appointmentMapper->insert($appointment);

		$this->auditEventService->recordAppointmentLifecycle(
			\OCA\Attendance\Audit\Verb::APPOINTMENT_CREATED,
			$appointment->getId(),
			\OCA\Attendance\Audit\Verb::SOURCE_APP,
		);

		$this->orgCalendarSyncService->syncAppointment($appointment);

		if ($sendNotification) {
			$affectedUsers = $this->getAffectedUsers($appointment);
			$affectedUsers = array_filter($affectedUsers, fn ($userId) => $userId !== $createdBy);
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
		array $visibleGroups = [],
		array $visibleTeams = [],
		?DeadlineUpdate $deadlineUpdate = null,
		?array $organizers = null,
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

		$updated = $this->appointmentMapper->update($appointment);

		$changedFields = $this->computeAppointmentChanges($before, $updated);
		if ($changedFields !== []) {
			$this->auditEventService->recordAppointmentUpdate($id, $changedFields);
		}

		$this->orgCalendarSyncService->syncAppointment($updated);

		return $updated;
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

		return $fields;
	}

	/**
	 * Close an appointment inquiry. Marks closedAt with the current UTC time.
	 * Idempotent: returns the existing appointment unchanged if already closed.
	 */
	public function closeAppointment(int $id): Appointment {
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
			\OCA\Attendance\Audit\Verb::SOURCE_APP,
		);

		// Booking wave: notify planned-in / not-planned-in yes-responders. No-op
		// unless the feature is on AND at least one person is booked; reopen-safe
		// (only diffs against the last communicated state).
		$this->bookingService->notifyOnClose($updated);

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
			\OCA\Attendance\Audit\Verb::SOURCE_APP,
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
			\OCA\Attendance\Audit\Verb::SOURCE_APP,
		);

		$this->orgCalendarSyncService->syncAppointment($updated);

		// Notify addressed attendees that the appointment will not take place.
		$affectedUsers = $this->getAffectedUsers($updated);
		if ($actorId !== null) {
			$affectedUsers = array_filter($affectedUsers, fn ($userId) => $userId !== $actorId);
		}
		$this->notificationService->sendCancellationNotifications($updated, array_values($affectedUsers));

		return $updated;
	}

	/**
	 * Reactivate a previously cancelled appointment: clears cancelledAt. The
	 * appointment returns to whatever state it had before (e.g. still closed if
	 * it was closed). No notification is sent (mirrors reopen).
	 * Idempotent: returns the existing appointment unchanged if not cancelled.
	 */
	public function uncancelAppointment(int $id): Appointment {
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
			\OCA\Attendance\Audit\Verb::SOURCE_APP,
		);

		$this->orgCalendarSyncService->syncAppointment($updated);

		return $updated;
	}

	/**
	 * Delete an appointment (soft delete).
	 */
	public function deleteAppointment(int $id, string $userId): void {
		$appointment = $this->appointmentMapper->find($id);
		$appointment->setIsActive(0);
		$appointment->setUpdatedAt(gmdate('Y-m-d H:i:s'));
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
				$deadlineUpdate, $organizers,
			);
			return [$updated];
		}

		$seriesId = $reference->getSeriesId();
		if ($seriesId === null) {
			// Not part of a series, just update single
			$updated = $this->updateAppointment(
				$referenceId, $name, $description, $startDatetime, $endDatetime,
				$userId, $visibleUsers, $visibleGroups, $visibleTeams,
				$deadlineUpdate, $organizers,
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
		foreach ($siblings as $sibling) {
			$before = clone $sibling;

			$sibling->setName($name);
			$sibling->setDescription($descriptionClean);

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
			$changedFields = $this->computeAppointmentChanges($before, $updatedSibling);
			if ($changedFields !== []) {
				$this->auditEventService->recordAppointmentUpdate(
					$updatedSibling->getId(),
					$changedFields,
				);
			}
			$this->orgCalendarSyncService->syncAppointment($updatedSibling);
			$updated[] = $updatedSibling;
		}

		return $updated;
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
		);

		$appointmentData = $appointment->jsonSerialize();
		$appointmentData = $this->enrichVisibilityData($appointmentData);
		$appointmentData = $this->enrichSeriesCount($appointmentData, $appointment);
		// Only expose userResponse when the user actually answered yes/no/maybe.
		// A row with response=NULL exists when an admin checked the user in
		// before they ever responded; treat that as "no response" (matches list endpoint).
		$userResponse = $this->getUserResponse($appointment->getId(), $userId);
		$appointmentData['userResponse'] = $this->serializeUserResponse($userResponse);
		$responseSummary = $this->buildResponseSummaryFor($myPermissions, $appointment->getId());
		if ($responseSummary !== null) {
			$appointmentData['responseSummary'] = $responseSummary;
		}
		$appointmentData['attachments'] = $this->attachmentService->getAttachments($appointment->getId());
		$appointmentData['myPermissions'] = $myPermissions;

		return $appointmentData;
	}

	/**
	 * The summary tier the viewer gets on this appointment: the full overview,
	 * the aggregate counts, or nothing.
	 *
	 * @param array{isOrganizer: bool, canEdit: bool, canSeeResponses: bool, canSeeResponseCounts: bool, canSeeComments: bool, canSeeAuditLog: bool} $myPermissions
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
	 * @return array{isOrganizer: bool, canEdit: bool, canSeeResponses: bool, canSeeResponseCounts: bool, canSeeComments: bool, canSeeAuditLog: bool}
	 */
	private function buildMyPermissions(
		Appointment $appointment,
		string $userId,
		bool $globalManage,
		bool $globalSeeResponses,
		bool $globalSeeComments,
		bool $globalSeeCounts,
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
	private function serializeUserResponse(?AttendanceResponse $userResponse): ?array {
		if ($userResponse === null || $userResponse->getResponse() === null) {
			return null;
		}
		$data = $userResponse->jsonSerialize();
		$data['bookingStatus'] = $this->bookingService->effectiveBookingStatus($userResponse);
		return $data;
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
			\OCA\Attendance\Audit\Verb::SOURCE_APP,
			null,
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

		// The target must be part of the appointment's audience — a manager
		// cannot invent responses for people the inquiry never reached.
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
	 */
	private function applyResponse(
		Appointment $appointment,
		string $userId,
		?string $response,
		?string $comment,
		?string $responseSource,
		string $auditSource,
		?string $actorUserId,
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

		if ($response === null) {
			$comment = '';
		}

		$beforeResponse = null;
		$beforeComment = '';

		try {
			$existingResponse = $this->responseMapper->findByAppointmentAndUser($appointmentId, $userId);
			$beforeResponse = $existingResponse->getResponse();
			$beforeComment = (string)$existingResponse->getComment();

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
			$result = $this->responseMapper->update($existingResponse);
		} catch (DoesNotExistException $e) {
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
			$result = $this->responseMapper->insert($attendanceResponse);
		}

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
			if (!$this->visibilityService->canUserSeeAppointment($appointment, $response->getUserId())) {
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
	 * Get minimal appointment data for navigation menu.
	 */
	public function getAppointmentsForNavigation(string $userId): array {
		$currentAppointments = $this->getUpcomingAppointments();
		$pastAppointments = $this->getPastAppointments();

		return [
			// Past list skips inAudience: it's only used by the "Unanswered"
			// sidebar, which never reads past entries.
			'current' => $this->buildNavigationData($currentAppointments, $userId, true),
			'past' => $this->buildNavigationData($pastAppointments, $userId, false),
		];
	}

	private function buildNavigationData(array $appointments, string $userId, bool $withAudience): array {
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
				'closedAt' => $this->formatDatetimeToUtc($appointment->getClosedAt()),
				'cancelledAt' => $this->formatDatetimeToUtc($appointment->getCancelledAt()),
				'inAudience' => $withAudience
					&& $this->visibilityService->isUserTargetAttendee($appointment, $userId),
			];
		}

		return $result;
	}

	/**
	 * Get appointments with user responses.
	 *
	 * @param bool $unansweredOnly Drop closed inquiries and any appointment
	 *                             the user has already answered. Implies
	 *                             upcoming-only (ignored when combined with
	 *                             $showPastAppointments).
	 * @param bool $onlyForMe Drop appointments the user is not part of the
	 *                        target audience for. Same predicate as
	 *                        VisibilityService::isUserTargetAttendee, so it
	 *                        bypasses the manage-permission "see everything"
	 *                        bypass.
	 * @param bool $includeResponseSummary Attach the full response overview
	 *                                     (other attendees' answers and roster) to each appointment. Gate on
	 *                                     the caller's PERMISSION_SEE_RESPONSE_OVERVIEW.
	 * @param bool $includeComments Include free-text comments in that overview.
	 *                              Gate on the caller's PERMISSION_SEE_COMMENTS.
	 * @param bool $includeResponseCounts Attach the aggregate counts (no names)
	 *                                    when the full overview is withheld. Gate on
	 *                                    the caller's PERMISSION_SEE_RESPONSE_COUNTS.
	 * @param bool $notScheduledOut Drop appointments the user was scheduled out
	 *                              of (see isScheduledOut). Implies $onlyForMe —
	 *                              a relevance filter that still let through
	 *                              other people's appointments would be odd.
	 * @param bool $onlyScheduled Keep only appointments the user holds a place
	 *                            in (see isScheduledIn). The strictest step of
	 *                            the same axis, so it also implies $onlyForMe.
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
		$appointments = $showPastAppointments
			? $this->getPastAppointments()
			: $this->getUpcomingAppointments();

		// "Unanswered only" makes no sense on past appointments — silently
		// suppress to keep the API surface single-purpose.
		$unansweredOnly = $unansweredOnly && !$showPastAppointments;
		// "Unanswered" only makes sense for appointments actually addressed to
		// the user. For managers, the visibility check otherwise lets through
		// every unanswered appointment in the system, which defeats the inbox.
		$onlyForMe = $onlyForMe || $unansweredOnly || $notScheduledOut || $onlyScheduled;

		$globalManage = $this->permissionService->canManageAppointments($userId);
		$result = [];

		foreach ($appointments as $appointment) {
			if (!$this->visibilityService->canUserSeeAppointment($appointment, $userId)) {
				continue;
			}
			if ($onlyForMe && !$this->visibilityService->isUserTargetAttendee($appointment, $userId)) {
				continue;
			}
			if ($unansweredOnly && ($appointment->isClosed() || $appointment->isCancelled())) {
				continue;
			}
			if ($notScheduledOut && $this->bookingService->isScheduledOut($appointment, $userId)) {
				continue;
			}
			if ($onlyScheduled && !$this->bookingService->isScheduledIn($appointment, $userId)) {
				continue;
			}

			$userResponse = $this->getUserResponse($appointment->getId(), $userId);
			$hasResponse = $userResponse && $userResponse->getResponse() !== null;
			if ($unansweredOnly && $hasResponse) {
				continue;
			}

			$myPermissions = $this->buildMyPermissions(
				$appointment,
				$userId,
				$globalManage,
				$includeResponseSummary,
				$includeComments,
				$includeResponseCounts,
			);

			$appointmentData = $appointment->jsonSerialize();
			$appointmentData = $this->enrichVisibilityData($appointmentData);
			$appointmentData = $this->enrichSeriesCount($appointmentData, $appointment);
			$appointmentData['userResponse'] = $this->serializeUserResponse($userResponse);
			$responseSummary = $this->buildResponseSummaryFor($myPermissions, $appointment->getId());
			if ($responseSummary !== null) {
				$appointmentData['responseSummary'] = $responseSummary;
			}
			$appointmentData['attachments'] = $this->attachmentService->getAttachments($appointment->getId());
			$appointmentData['myPermissions'] = $myPermissions;
			$result[] = $appointmentData;
		}

		return $result;
	}

	/**
	 * Get upcoming appointments for the dashboard widget. Restricted to
	 * appointments the user is a target attendee of (incl. unrestricted
	 * "everyone" appointments) — without admin bypass, so managers don't
	 * see noise from every appointment in the system.
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

			$appointmentData = $appointment->jsonSerialize();
			$appointmentData = $this->enrichVisibilityData($appointmentData);
			$userResponse = $this->getUserResponse($appointment->getId(), $userId);
			$appointmentData['userResponse'] = $this->serializeUserResponse($userResponse);
			$appointmentData['attachments'] = $this->attachmentService->getAttachments($appointment->getId());
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

		// Get all responses for this appointment
		$responses = $this->responseMapper->findByAppointment($appointmentId);
		$respondedUserIds = [];
		foreach ($responses as $response) {
			$respondedUserIds[$response->getUserId()] = true;
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

		$visibleUsersList = $visibleUsers ? json_decode($visibleUsers, true) : [];
		$visibleGroupsList = $visibleGroups ? json_decode($visibleGroups, true) : [];
		$visibleTeamsList = $visibleTeams ? json_decode($visibleTeams, true) : [];

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
		$enrichedUsers = [];
		foreach ($appointmentData['visibleUsers'] ?? [] as $userId) {
			$enrichedUsers[] = $this->enrichUserRef($userId)
				+ ['isGuest' => $this->guestService->isGuestUser($userId)];
		}
		$appointmentData['visibleUsers'] = $enrichedUsers;

		$enrichedGroups = [];
		foreach ($appointmentData['visibleGroups'] ?? [] as $groupId) {
			$group = $this->groupManager->get($groupId);
			$enrichedGroups[] = [
				'id' => $groupId,
				'label' => $group ? $group->getDisplayName() : $groupId,
				'type' => 'group',
			];
		}
		$appointmentData['visibleGroups'] = $enrichedGroups;

		$enrichedTeams = [];
		foreach ($appointmentData['visibleTeams'] ?? [] as $teamId) {
			$teamInfo = $this->visibilityService->getTeamInfo($teamId);
			$enrichedTeams[] = $teamInfo ?? [
				'id' => $teamId,
				'label' => $teamId,
				'type' => 'team',
			];
		}
		$appointmentData['visibleTeams'] = $enrichedTeams;

		$appointmentData['organizers'] = array_map(
			fn (string $userId) => $this->enrichUserRef($userId),
			$appointmentData['organizers'] ?? [],
		);

		return $appointmentData;
	}

	/**
	 * @return array{id: string, label: string, type: string}
	 */
	private function enrichUserRef(string $userId): array {
		$user = $this->userManager->get($userId);
		return [
			'id' => $userId,
			'label' => $user ? $user->getDisplayName() : $userId,
			'type' => 'user',
		];
	}

	/**
	 * Strip HTML tags from markdown text to prevent stored XSS.
	 * Preserves markdown formatting but removes raw HTML that users may embed.
	 */
	private function stripHtmlFromMarkdown(string $text): string {
		return strip_tags($text);
	}
}
