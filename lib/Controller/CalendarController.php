<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Service\CalendarService;
use OCA\Attendance\Service\PermissionService;
use OCP\AppFramework\Controller;
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
	 * Check if calendar feature is available.
	 *
	 * @NoAdminRequired
	 */
	public function isAvailable(): DataResponse {
		return new DataResponse([
			'available' => $this->calendarService->isCalendarAvailable(),
		]);
	}

	/**
	 * Get calendars for current user.
	 *
	 * @NoAdminRequired
	 */
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
	 * Get events from a specific calendar.
	 *
	 * @NoAdminRequired
	 */
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
