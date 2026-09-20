<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Service\AppointmentService;
use OCA\Attendance\Service\VacationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class VacationController extends Controller {
	private IUserSession $userSession;
	private VacationService $vacationService;
	private AppointmentService $appointmentService;

	public function __construct(
		string $appName,
		IRequest $request,
		IUserSession $userSession,
		VacationService $vacationService,
		AppointmentService $appointmentService,
	) {
		parent::__construct($appName, $request);
		$this->userSession = $userSession;
		$this->vacationService = $vacationService;
		$this->appointmentService = $appointmentService;
	}

	/**
	 * List the current user's own vacation periods.
	 *
	 * @return DataResponse<Http::STATUS_OK, list<AttendanceVacationData>, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function index(): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return new DataResponse($this->vacationService->getForUser($user->getUID()));
	}

	/**
	 * Create a vacation period for the current user.
	 *
	 * @param string $startDate Start date, Y-m-d
	 * @param string $endDate End date, Y-m-d (inclusive, must not be before start)
	 * @param ?string $note Optional free-text reason, visible to the whole team on the overview
	 * @return DataResponse<Http::STATUS_CREATED, AttendanceVacationData, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function create(string $startDate, string $endDate, ?string $note = null): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new DataResponse($this->vacationService->create($user->getUID(), $startDate, $endDate, $note), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Update one of the current user's own vacation periods.
	 *
	 * @param int $id Vacation ID
	 * @param string $startDate Start date, Y-m-d
	 * @param string $endDate End date, Y-m-d (inclusive, must not be before start)
	 * @param ?string $note Optional free-text reason, visible to the whole team on the overview
	 * @return DataResponse<Http::STATUS_OK, AttendanceVacationData, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function update(int $id, string $startDate, string $endDate, ?string $note = null): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new DataResponse($this->vacationService->update($id, $user->getUID(), $startDate, $endDate, $note));
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'Vacation not found'], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Delete one of the current user's own vacation periods.
	 *
	 * @param int $id Vacation ID
	 * @return DataResponse<Http::STATUS_OK, array{success: bool}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function destroy(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->vacationService->delete($id, $user->getUID());
			return new DataResponse(['success' => true]);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'Vacation not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Answer "no" to the upcoming, still-open appointments inside one of the
	 * current user's own vacations that they have not answered yet. Opt-in,
	 * asked for after saving a vacation; answers already given are kept.
	 *
	 * @param int $id Vacation ID
	 * @return DataResponse<Http::STATUS_OK, array{count: int}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function applyToAppointments(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$vacation = $this->vacationService->findOwnedBy($id, $user->getUID());
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'Vacation not found'], Http::STATUS_NOT_FOUND);
		}

		$count = $this->appointmentService->answerNoDuringVacation($user->getUID(), $vacation->getStartDate(), $vacation->getEndDate());

		return new DataResponse(['count' => $count]);
	}

	/**
	 * Who among a proposed audience can't attend a proposed appointment window —
	 * the "X people can't attend" hint on the create screen. The audience is
	 * described the way an appointment describes it; with all three empty it is
	 * everyone, exactly as for a saved appointment.
	 *
	 * @param string $startDatetime ISO 8601 start of the proposed appointment
	 * @param string $endDatetime ISO 8601 end of the proposed appointment
	 * @param list<string> $visibleUsers Individually selected users
	 * @param list<string> $visibleGroups Selected groups
	 * @param list<string> $visibleTeams Selected teams
	 * @return DataResponse<Http::STATUS_OK, AttendanceVacationConflicts, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function conflicts(string $startDatetime, string $endDatetime, array $visibleUsers = [], array $visibleGroups = [], array $visibleTeams = []): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$audience = $this->appointmentService->previewAudience($visibleUsers, $visibleGroups, $visibleTeams);
		$conflicts = $this->vacationService->findConflicts($startDatetime, $endDatetime, $audience);

		return new DataResponse([
			'count' => count($conflicts),
			'users' => $conflicts,
		]);
	}

	/**
	 * The team-wide vacation calendar: every whitelisted member's vacation
	 * periods overlapping the given window, ordered by start date.
	 *
	 * @param ?string $from Y-m-d, defaults to today
	 * @param ?string $to Y-m-d, defaults to 180 days after $from
	 * @return DataResponse<Http::STATUS_OK, list<AttendanceVacationEntry>, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function overview(?string $from = null, ?string $to = null): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$fromDate = $from ?? gmdate('Y-m-d');
		$toDate = $to ?? gmdate('Y-m-d', strtotime($fromDate . ' +180 days'));
		$candidates = $this->appointmentService->getAllWhitelistedUsers();

		return new DataResponse($this->vacationService->getOverview($fromDate, $toDate, $candidates));
	}
}
