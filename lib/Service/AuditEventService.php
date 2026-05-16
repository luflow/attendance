<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Audit\AuditEventDispatcher;
use OCA\Attendance\Audit\Verb;
use OCA\Attendance\Db\AuditEvent;
use OCA\Attendance\Db\AuditEventMapper;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Records audit events for response and check-in changes. The master kill
 * switch is enforced inside record() so callers always invoke the service
 * unconditionally — disabling the feature instantly stops both writes and
 * notification dispatch.
 *
 * When a caller passes no actorId, the current session user is used. In a
 * background-job context IUserSession::getUser() returns null, which we
 * persist as-is — the source field (e.g. SOURCE_AUTO_CLOSE) carries the
 * "no actor" context for the timeline renderer.
 */
class AuditEventService {
	private AuditEventMapper $mapper;
	private AuditEventDispatcher $dispatcher;
	private ConfigService $configService;
	private IUserSession $userSession;
	private LoggerInterface $logger;

	public function __construct(
		AuditEventMapper $mapper,
		AuditEventDispatcher $dispatcher,
		ConfigService $configService,
		IUserSession $userSession,
		LoggerInterface $logger,
	) {
		$this->mapper = $mapper;
		$this->dispatcher = $dispatcher;
		$this->configService = $configService;
		$this->userSession = $userSession;
		$this->logger = $logger;
	}

	/**
	 * Persist a single audit event. Returns null when the feature is disabled
	 * via the kill switch, or when persistence fails — audit is best-effort
	 * logging and must never break the calling mutation (e.g. a response
	 * submit) just because the table is missing during an upgrade window.
	 *
	 * Pass $actorId=null to default to the current session user. Background
	 * jobs end up with null after the session lookup, which is the desired
	 * "no actor" state.
	 */
	public function record(
		string $verb,
		int $appointmentId,
		?string $actorId = null,
		?string $subjectId = null,
		array $meta = [],
		?string $source = null,
	): ?AuditEvent {
		if (!$this->configService->isAuditLogEnabled()) {
			return null;
		}

		$actorId ??= $this->userSession->getUser()?->getUID();

		try {
			$event = new AuditEvent();
			$event->setAppointmentId($appointmentId);
			$event->setVerb($verb);
			$event->setActorId($actorId);
			$event->setSubjectId($subjectId);
			$event->setMetaArray($meta);
			$event->setSource($source);
			$event->setCreatedAt(gmdate('Y-m-d H:i:s'));

			$saved = $this->mapper->insert($event);
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to record audit event', [
				'verb' => $verb,
				'appointmentId' => $appointmentId,
				'error' => $e->getMessage(),
			]);
			return null;
		}

		try {
			$this->dispatcher->dispatch($saved);
		} catch (\Throwable $e) {
			$this->logger->warning('Audit event listener failed', [
				'verb' => $verb,
				'appointmentId' => $appointmentId,
				'error' => $e->getMessage(),
			]);
		}

		return $saved;
	}

	/**
	 * Classify a response mutation and record the matching verb.
	 *
	 * @param ?string $beforeResponse the response value before the mutation, or null if no row existed
	 * @param ?string $afterResponse  the response value after the mutation, or null if the row was withdrawn
	 */
	public function recordResponseChange(
		int $appointmentId,
		string $subjectUserId,
		?string $beforeResponse,
		string $beforeComment,
		?string $afterResponse,
		string $afterComment,
		string $source,
		?string $actorUserId = null,
	): ?AuditEvent {
		$actor = $actorUserId ?? $subjectUserId;
		$classification = $this->classifyResponseChange($beforeResponse, $beforeComment, $afterResponse, $afterComment);
		if ($classification === null) {
			return null;
		}
		[$verb, $meta] = $classification;
		return $this->record($verb, $appointmentId, $actor, $subjectUserId, $meta, $source);
	}

	/**
	 * @return array{0: string, 1: array<string, mixed>}|null  [verb, meta], or null when nothing changed
	 */
	private function classifyResponseChange(
		?string $beforeResponse,
		string $beforeComment,
		?string $afterResponse,
		string $afterComment,
	): ?array {
		if ($beforeResponse === null && $afterResponse !== null) {
			return [Verb::RESPONSE_SUBMITTED, ['response' => $afterResponse, 'comment' => $afterComment]];
		}
		if ($beforeResponse !== null && $afterResponse === null) {
			return [Verb::RESPONSE_RESCINDED, ['from' => $beforeResponse]];
		}
		if ($beforeResponse === null && $afterResponse === null) {
			return null;
		}
		if ($beforeResponse !== $afterResponse) {
			return [Verb::RESPONSE_CHANGED, [
				'from' => $beforeResponse,
				'to' => $afterResponse,
				'commentChanged' => $beforeComment !== $afterComment,
			]];
		}
		if ($beforeComment !== $afterComment) {
			return [Verb::RESPONSE_COMMENT_UPDATED, [
				'response' => $afterResponse,
				'comment_from' => $beforeComment,
				'comment_to' => $afterComment,
			]];
		}
		return null;
	}

	/**
	 * Record an appointment lifecycle event (created / closed / reopened).
	 * Actor is taken from the current session — null in background-job
	 * contexts (e.g. AutoCloseJob), which is what we want: the audit row
	 * then reflects "system closed it" via the $source field.
	 */
	public function recordAppointmentLifecycle(
		string $verb,
		int $appointmentId,
		string $source,
	): ?AuditEvent {
		return $this->record($verb, $appointmentId, null, null, [], $source);
	}

	/**
	 * Record an appointment edit. Callers must filter out no-op edits before
	 * invoking — passing an empty $fields list would write a meaningless row.
	 *
	 * @param list<string> $fields changed field keys ('name', 'description',
	 *   'time', 'visibility', 'deadline'). Before/after values are not stored —
	 *   the field list is enough for the timeline and keeps the audit row lean.
	 */
	public function recordAppointmentUpdate(
		int $appointmentId,
		array $fields,
		string $source = Verb::SOURCE_APP,
	): ?AuditEvent {
		return $this->record(
			Verb::APPOINTMENT_UPDATED,
			$appointmentId,
			null,
			null,
			['fields' => $fields],
			$source,
		);
	}

	public function recordCheckin(
		int $appointmentId,
		string $adminUserId,
		string $targetUserId,
		?string $beforeCheckin,
		?string $afterCheckin,
		string $afterComment,
	): ?AuditEvent {
		$verb = ($beforeCheckin === null || $beforeCheckin === '')
			? Verb::CHECKIN_RECORDED
			: Verb::CHECKIN_CHANGED;

		$meta = [
			'checkinState' => $afterCheckin,
			'checkinComment' => $afterComment,
		];
		if ($verb === Verb::CHECKIN_CHANGED) {
			$meta['from'] = $beforeCheckin;
		}

		return $this->record(
			$verb,
			$appointmentId,
			$adminUserId,
			$targetUserId,
			$meta,
			Verb::SOURCE_ADMIN_CHECKIN,
		);
	}
}
