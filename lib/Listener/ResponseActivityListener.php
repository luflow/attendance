<?php

declare(strict_types=1);

namespace OCA\Attendance\Listener;

use OCA\Attendance\Activity\EventMessages;
use OCA\Attendance\Activity\Setting\ResponsesSetting;
use OCA\Attendance\Audit\Verb;
use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AuditEvent;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\NotificationService;
use OCA\Attendance\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;

/**
 * Records every response event in the activity stream of whoever may read the
 * appointment's audit log — that changelog across all appointments (issue #307).
 */
final class ResponseActivityListener {
	private const MAX_COMMENT_LENGTH = 300;

	public function __construct(
		private PermissionService $permissionService,
		private ConfigService $configService,
		private AppointmentMapper $appointmentMapper,
		private NotificationService $notificationService,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function handle(AuditEvent $event): void {
		if (!in_array($event->getVerb(), Verb::ALL_RESPONSE, true)
			|| in_array($event->getSource(), Verb::AUTOMATIC_SOURCES, true)) {
			return;
		}

		try {
			$appointment = $this->appointmentMapper->find($event->getAppointmentId());
		} catch (DoesNotExistException $e) {
			return;
		}

		$readers = $this->auditLogReaders($appointment, $event->getActorId());
		if ($readers === []) {
			return;
		}

		$meta = $event->getMetaArray();
		$comment = (string)($meta['comment_to'] ?? ($meta['comment'] ?? ''));
		if (mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
			// Copied into every reader's row; the full text is one click away.
			$comment = mb_substr($comment, 0, self::MAX_COMMENT_LENGTH) . '…';
		}
		$this->notificationService->publishActivity(
			ResponsesSetting::IDENTIFIER,
			strtr($event->getVerb(), '.', '_'),
			EventMessages::responseParameters($event, $appointment->getName()) + ['comment' => $comment],
			$readers,
			'appointment',
			$appointment->getId(),
			$this->urlGenerator->linkToRouteAbsolute('attendance.page.appointment', ['id' => $appointment->getId()]),
			$event->getActorId() ?? '',
			$appointment->getName(),
		);
	}

	/**
	 * Mirrors the read check of AuditController::index(), minus the actor.
	 *
	 * @return list<string>
	 */
	private function auditLogReaders(Appointment $appointment, ?string $actorId): array {
		$permission = $this->configService->getAuditLogVisibility() === ConfigService::AUDIT_LOG_VISIBILITY_ALL_WITH_OVERVIEW
			? PermissionService::PERMISSION_SEE_RESPONSE_OVERVIEW
			: PermissionService::PERMISSION_MANAGE_APPOINTMENTS;
		// A permission everyone holds (the default) says nothing about who looks
		// after this appointment, and would write a row per account per answer.
		$holders = $this->permissionService->getModeForPermission($permission) === PermissionService::MODE_ALL
			? []
			: $this->permissionService->getUsersWith($permission);
		$organizers = array_filter(
			$appointment->getOrganizersList(),
			fn (string $userId): bool => $this->permissionService->isOrganizer($appointment, $userId),
		);
		$readers = array_unique(array_merge($holders, $organizers));
		return array_values(array_filter($readers, static fn (string $userId): bool => $userId !== $actorId));
	}
}
