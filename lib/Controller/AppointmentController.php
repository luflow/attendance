<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Service\AppointmentService;
use OCA\Attendance\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IGroupManager;

class AppointmentController extends Controller {
	private AppointmentService $appointmentService;
	private PermissionService $permissionService;
	private IUserSession $userSession;
	private IGroupManager $groupManager;

	public function __construct(
		string $appName,
		IRequest $request,
		AppointmentService $appointmentService,
		PermissionService $permissionService,
		IUserSession $userSession,
		IGroupManager $groupManager
	) {
		parent::__construct($appName, $request);
		$this->appointmentService = $appointmentService;
		$this->permissionService = $permissionService;
		$this->userSession = $userSession;
		$this->groupManager = $groupManager;
	}

	/**
	 * @NoAdminRequired
	 */
	public function index(): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		$showPastAppointments = $this->request->getParam('showPastAppointments', 'false') === 'true';
		$appointments = $this->appointmentService->getAppointmentsWithUserResponses($user->getUID(), $showPastAppointments);
		return new DataResponse($appointments);
	}

	/**
	 * @NoAdminRequired
	 */
	public function create(
		string $name,
		string $description,
		string $startDatetime,
		string $endDatetime
	): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user can manage appointments
		if (!$this->permissionService->canManageAppointments($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions to create appointments'], 403);
		}

		try {
			$appointment = $this->appointmentService->createAppointment(
				$name,
				$description,
				$startDatetime,
				$endDatetime,
				$user->getUID()
			);
			return new DataResponse($appointment);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function update(
		int $id,
		string $name,
		string $description,
		string $startDatetime,
		string $endDatetime
	): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user can manage appointments or is creator
		try {
			$appointment = $this->appointmentService->getAppointment($id);
			if (!$this->permissionService->canManageAppointments($user->getUID()) && $appointment->getCreatedBy() !== $user->getUID()) {
				return new DataResponse(['error' => 'Insufficient permissions to update appointments'], 403);
			}
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Appointment not found'], 404);
		}

		try {
			$appointment = $this->appointmentService->updateAppointment(
				$id,
				$name,
				$description,
				$startDatetime,
				$endDatetime,
				$user->getUID()
			);
			return new DataResponse($appointment);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function destroy(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user can manage appointments or is creator
		try {
			$appointment = $this->appointmentService->getAppointment($id);
			if (!$this->permissionService->canManageAppointments($user->getUID()) && $appointment->getCreatedBy() !== $user->getUID()) {
				return new DataResponse(['error' => 'Insufficient permissions to delete appointments'], 403);
			}
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Appointment not found'], 404);
		}

		try {
			$this->appointmentService->deleteAppointment($id, $user->getUID());
			return new DataResponse(['success' => true]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Get detailed responses for an appointment (admin only)
	 * @NoAdminRequired
	 */
	public function getResponses(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user can manage appointments
		if (!$this->permissionService->canManageAppointments($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions to view detailed responses'], 403);
		}

		try {
			$responses = $this->appointmentService->getAppointmentResponsesWithUsers($id, $user->getUID());
			return new DataResponse($responses);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 403);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function respond(int $id, string $response, string $comment = ''): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		try {
			$attendanceResponse = $this->appointmentService->submitResponse(
				$id,
				$user->getUID(),
				$response,
				$comment
			);
			return new DataResponse($attendanceResponse);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Get upcoming appointments for dashboard widget
	 * @NoAdminRequired
	 */
	public function widget(): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		try {
			$appointments = $this->appointmentService->getUpcomingAppointmentsForWidget($user->getUID(), 5);
			return new DataResponse($appointments);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Set Checkin for a user (admin only)
	 *
	 * @NoAdminRequired
	 */
	public function checkinResponse(int $appointmentId, string $targetUserId): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user can do checkins
		if (!$this->permissionService->canCheckin($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions to checkin responses'], 403);
		}

		$response = $this->request->getParam('response');
		$comment = $this->request->getParam('comment');

		try {
			$result = $this->appointmentService->checkinResponse(
				$appointmentId,
				$targetUserId,
				$response,
				$comment,
				$user->getUID()
			);

			return new DataResponse($result);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function getPermissions(): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		return new DataResponse([
			'canManageAppointments' => $this->permissionService->canManageAppointments($user->getUID()),
			'canCheckin' => $this->permissionService->canCheckin($user->getUID()),
		]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function getCheckinData(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user can do checkins
		if (!$this->permissionService->canCheckin($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions to access check-in data'], 403);
		}

		try {
			$checkinData = $this->appointmentService->getCheckinData($id);
			return new DataResponse($checkinData);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

}
