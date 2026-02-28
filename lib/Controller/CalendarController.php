<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Service\CalendarService;
use OCA\Attendance\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for calendar integration (importing events).
 */
class CalendarController extends Controller {
	private CalendarService $calendarService;
	private PermissionService $permissionService;
	private IUserSession $userSession;

	public function __construct(
		string $appName,
		IRequest $request,
		CalendarService $calendarService,
		PermissionService $permissionService,
		IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
		$this->calendarService = $calendarService;
		$this->permissionService = $permissionService;
		$this->userSession = $userSession;
	}

	/**
	 * Check if calendar feature is available
	 *
	 * @return DataResponse<Http::STATUS_OK, array{available: bool}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function isAvailable(): DataResponse {
		return new DataResponse([
			'available' => $this->calendarService->isCalendarAvailable(),
		]);
	}

	/**
	 * Get calendars for current user
	 *
	 * @return DataResponse<Http::STATUS_OK, list<array{id: string, uri: string, displayName: string, color: string}>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function getCalendars(): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// User must be able to create appointments to use calendar import
		if (!$this->permissionService->canManageAppointments($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions'], 403);
		}

		if (!$this->calendarService->isCalendarAvailable()) {
			return new DataResponse(['error' => 'Calendar app not available'], 400);
		}

		try {
			$calendars = $this->calendarService->getCalendarsForUser($user->getUID());
			return new DataResponse($calendars);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Get events from a specific calendar
	 *
	 * @param string $calendarUri URI of the calendar to fetch events from
	 * @param int $days Number of days ahead to fetch events (1-90, default 60)
	 * @return DataResponse<Http::STATUS_OK, array{events: list<array<string, mixed>>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function getEvents(string $calendarUri, int $days = 60): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// User must be able to create appointments to use calendar import
		if (!$this->permissionService->canManageAppointments($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions'], 403);
		}

		if (!$this->calendarService->isCalendarAvailable()) {
			return new DataResponse(['error' => 'Calendar app not available'], 400);
		}

		// Validate days parameter (1-90)
		$days = max(1, min($days, 90));

		try {
			$events = $this->calendarService->getEventsFromCalendar(
				$user->getUID(),
				$calendarUri,
				$days
			);
			return new DataResponse(['events' => $events]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}
}
