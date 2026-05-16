<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Audit\Verb;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AuditEventMapper;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

class AuditController extends Controller {
	private IUserSession $userSession;
	private IUserManager $userManager;
	private PermissionService $permissionService;
	private ConfigService $configService;
	private AuditEventMapper $auditEventMapper;
	private AppointmentMapper $appointmentMapper;

	public function __construct(
		string $appName,
		IRequest $request,
		IUserSession $userSession,
		IUserManager $userManager,
		PermissionService $permissionService,
		ConfigService $configService,
		AuditEventMapper $auditEventMapper,
		AppointmentMapper $appointmentMapper,
	) {
		parent::__construct($appName, $request);
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->permissionService = $permissionService;
		$this->configService = $configService;
		$this->auditEventMapper = $auditEventMapper;
		$this->appointmentMapper = $appointmentMapper;
	}

	/**
	 * List audit events for an appointment, newest-first
	 *
	 * @param int $id Appointment ID
	 * @param ?string $verb Optional verb filter (supports trailing wildcard, e.g. `response.*`)
	 * @param ?string $subject Optional subject (user) filter
	 * @param int $limit Maximum number of events to return (1-200)
	 * @param int $offset Pagination offset
	 * @return DataResponse<Http::STATUS_OK, AttendanceAuditPage, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>|DataResponse<Http::STATUS_PRECONDITION_FAILED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function index(int $id, ?string $verb = null, ?string $subject = null, int $limit = 50, int $offset = 0): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if (!$this->configService->isAuditLogEnabled()) {
			return new DataResponse(['error' => 'Audit log is disabled'], Http::STATUS_PRECONDITION_FAILED);
		}

		$visibility = $this->configService->getAuditLogVisibility();
		$canRead = $visibility === ConfigService::AUDIT_LOG_VISIBILITY_ALL_WITH_OVERVIEW
			? $this->permissionService->canSeeResponseOverview($user->getUID())
			: $this->permissionService->canManageAppointments($user->getUID());

		if (!$canRead) {
			return new DataResponse(['error' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
		}

		try {
			$this->appointmentMapper->find($id);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'Appointment not found'], Http::STATUS_NOT_FOUND);
		}

		$limit = max(1, min(200, $limit));
		$offset = max(0, $offset);

		$events = $this->auditEventMapper->findByAppointment($id, $verb, $subject, $limit, $offset);
		$total = $this->auditEventMapper->countByAppointment($id, $verb, $subject);

		$displayNames = $this->resolveDisplayNames($events);
		$items = [];
		foreach ($events as $event) {
			$serialized = $event->jsonSerialize();
			$serialized['actor'] = $this->refFor($event->getActorId(), $displayNames);
			$serialized['subject'] = $this->refFor($event->getSubjectId(), $displayNames);
			$items[] = $serialized;
		}

		return new DataResponse([
			'items' => $items,
			'total' => $total,
			'hasMore' => ($offset + count($items)) < $total,
		]);
	}

	/**
	 * Single pass over the page collects every distinct user ID, then one
	 * userManager lookup per unique ID — avoids 2× find() per row.
	 *
	 * @param list<\OCA\Attendance\Db\AuditEvent> $events
	 * @return array<string, string> userId => displayName (uid as fallback when unknown)
	 */
	private function resolveDisplayNames(array $events): array {
		$ids = [];
		foreach ($events as $event) {
			foreach ([$event->getActorId(), $event->getSubjectId()] as $id) {
				if ($id !== null && $id !== '' && $id !== Verb::ANONYMISED_USER) {
					$ids[$id] = true;
				}
			}
		}
		$names = [];
		foreach (array_keys($ids) as $uid) {
			$user = $this->userManager->get($uid);
			$names[$uid] = $user ? $user->getDisplayName() : $uid;
		}
		return $names;
	}

	/**
	 * @param array<string, string> $displayNames
	 * @return ?array{userId: string, displayName: string}
	 */
	private function refFor(?string $userId, array $displayNames): ?array {
		if ($userId === null || $userId === '') {
			return null;
		}
		if ($userId === Verb::ANONYMISED_USER) {
			return ['userId' => $userId, 'displayName' => '(deleted user)'];
		}
		return ['userId' => $userId, 'displayName' => $displayNames[$userId] ?? $userId];
	}
}
