<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Audit\AuditEventDispatcher;
use OCA\Attendance\Audit\Verb;
use OCA\Attendance\Db\AuditEvent;
use OCA\Attendance\Db\AuditEventMapper;
use Psr\Log\LoggerInterface;

/**
 * Records audit events for response and check-in changes. The master kill
 * switch is enforced inside record() so callers always invoke the service
 * unconditionally — disabling the feature instantly stops both writes and
 * notification dispatch.
 */
class AuditEventService {
	private AuditEventMapper $mapper;
	private AuditEventDispatcher $dispatcher;
	private ConfigService $configService;
	private LoggerInterface $logger;

	public function __construct(
		AuditEventMapper $mapper,
		AuditEventDispatcher $dispatcher,
		ConfigService $configService,
		LoggerInterface $logger,
	) {
		$this->mapper = $mapper;
		$this->dispatcher = $dispatcher;
		$this->configService = $configService;
		$this->logger = $logger;
	}

	/**
	 * Persist a single audit event. Returns null when the feature is disabled
	 * via the kill switch, or when persistence fails — audit is best-effort
	 * logging and must never break the calling mutation (e.g. a response
	 * submit) just because the table is missing during an upgrade window.
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
