<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Db\AppointmentMapper;
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
	private AppointmentMapper $appointmentMapper;

	public function __construct(
		string $appName,
		IRequest $request,
		CalendarService $calendarService,
		PermissionService $permissionService,
		IUserSession $userSession,
		AppointmentMapper $appointmentMapper,
	) {
		parent::__construct($appName, $request);
		$this->calendarService = $calendarService;
		$this->permissionService = $permissionService;
		$this->userSession = $userSession;
		$this->appointmentMapper = $appointmentMapper;
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
	 * Get events from a specific calendar within a date range
	 *
	 * @param string $calendarUri URI of the calendar to fetch events from
	 * @param string $from Start date in Y-m-d format
	 * @param string $to End date in Y-m-d format
	 * @return DataResponse<Http::STATUS_OK, array{events: list<array<string, mixed>>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function getEvents(string $calendarUri, string $from = '', string $to = ''): DataResponse {
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

		// Default: today to 60 days from now
		$today = new \DateTimeImmutable('now');
		if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
			$from = $today->format('Y-m-d');
		}
		if ($to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
			$to = $today->modify('+60 days')->format('Y-m-d');
		}

		// Ensure from <= to
		if ($from > $to) {
			return new DataResponse(['error' => 'Start date must be before end date'], 400);
		}

		try {
			$events = $this->calendarService->getEventsFromCalendar(
				$user->getUID(),
				$calendarUri,
				$from,
				$to
			);

			$importedIds = [];
			if (!empty($events)) {
				$imported = $this->appointmentMapper->findImportedByCalendarUri($calendarUri);
				foreach ($imported as $row) {
					$importedIds[] = CalendarService::buildOccurrenceId(
						$row['calendar_event_uid'],
						$row['start_datetime']
					);
				}
			}

			return new DataResponse([
				'events' => $events,
				'importedIds' => $importedIds,
			]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}
}
