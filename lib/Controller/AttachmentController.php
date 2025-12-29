<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Service\AppointmentService;
use OCA\Attendance\Service\AttachmentService;
use OCA\Attendance\Service\PermissionService;
use OCA\Attendance\Service\VisibilityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

class AttachmentController extends Controller {
	private AttachmentService $attachmentService;
	private AppointmentService $appointmentService;
	private PermissionService $permissionService;
	private VisibilityService $visibilityService;
	private IUserSession $userSession;

	public function __construct(
		string $appName,
		IRequest $request,
		AttachmentService $attachmentService,
		AppointmentService $appointmentService,
		PermissionService $permissionService,
		VisibilityService $visibilityService,
		IUserSession $userSession
	) {
		parent::__construct($appName, $request);
		$this->attachmentService = $attachmentService;
		$this->appointmentService = $appointmentService;
		$this->permissionService = $permissionService;
		$this->visibilityService = $visibilityService;
		$this->userSession = $userSession;
	}

	/**
	 * List attachments for an appointment
	 * @NoAdminRequired
	 */
	public function list(int $appointmentId): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		try {
			$appointment = $this->appointmentService->getAppointment($appointmentId);

			// Check if user can see the appointment
			if (!$this->visibilityService->canUserSeeAppointment($appointment, $user->getUID())) {
				return new DataResponse(['error' => 'Appointment not found or not visible'], 404);
			}

			$attachments = $this->attachmentService->getAttachments($appointmentId);
			return new DataResponse($attachments);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'Appointment not found'], 404);
		}
	}

	/**
	 * Add an attachment to an appointment
	 * @NoAdminRequired
	 */
	public function add(int $appointmentId, int $fileId): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user can manage appointments
		if (!$this->permissionService->canManageAppointments($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions to add attachments'], 403);
		}

		try {
			// Verify appointment exists
			$this->appointmentService->getAppointment($appointmentId);

			$attachment = $this->attachmentService->addAttachment(
				$appointmentId,
				$fileId,
				$user->getUID()
			);

			$data = $attachment->jsonSerialize();
			$data['downloadUrl'] = $this->attachmentService->getAttachmentDownloadUrl($attachment->getFileId());

			return new DataResponse($data);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'Appointment not found'], 404);
		} catch (NotFoundException $e) {
			return new DataResponse(['error' => 'File not found or no access'], 404);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}

	/**
	 * Remove an attachment from an appointment
	 * @NoAdminRequired
	 */
	public function remove(int $appointmentId, int $fileId): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user can manage appointments
		if (!$this->permissionService->canManageAppointments($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions to remove attachments'], 403);
		}

		try {
			// Verify appointment exists
			$this->appointmentService->getAppointment($appointmentId);

			$this->attachmentService->removeAttachment($appointmentId, $fileId);
			return new DataResponse(['success' => true]);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'Attachment not found'], 404);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 400);
		}
	}
}
