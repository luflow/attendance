<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\AppointmentAttachment;
use OCA\Attendance\Db\AppointmentAttachmentMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;

class AttachmentService {
	private AppointmentAttachmentMapper $attachmentMapper;
	private IRootFolder $rootFolder;
	private IURLGenerator $urlGenerator;

	public function __construct(
		AppointmentAttachmentMapper $attachmentMapper,
		IRootFolder $rootFolder,
		IURLGenerator $urlGenerator
	) {
		$this->attachmentMapper = $attachmentMapper;
		$this->rootFolder = $rootFolder;
		$this->urlGenerator = $urlGenerator;
	}

	/**
	 * Add an attachment to an appointment.
	 *
	 * @param int $appointmentId
	 * @param int $fileId
	 * @param string $userId
	 * @return AppointmentAttachment
	 * @throws NotFoundException if file doesn't exist or user has no access
	 */
	public function addAttachment(int $appointmentId, int $fileId, string $userId): AppointmentAttachment {
		// Verify file exists and user has access
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$nodes = $userFolder->getById($fileId);

		if (empty($nodes)) {
			throw new NotFoundException('File not found or no access');
		}

		$file = $nodes[0];

		// Check if attachment already exists
		try {
			$existing = $this->attachmentMapper->findByAppointmentAndFile($appointmentId, $fileId);
			// Already exists, return it
			return $existing;
		} catch (DoesNotExistException $e) {
			// Continue to create new attachment
		}

		// Create attachment
		$attachment = new AppointmentAttachment();
		$attachment->setAppointmentId($appointmentId);
		$attachment->setFileId($fileId);
		$attachment->setFileName($file->getName());
		$attachment->setFilePath($userFolder->getRelativePath($file->getPath()) ?? $file->getPath());
		$attachment->setAddedBy($userId);
		$attachment->setAddedAt(date('Y-m-d H:i:s'));

		return $this->attachmentMapper->insert($attachment);
	}

	/**
	 * Remove an attachment from an appointment.
	 *
	 * @param int $appointmentId
	 * @param int $fileId
	 * @throws DoesNotExistException if attachment doesn't exist
	 */
	public function removeAttachment(int $appointmentId, int $fileId): void {
		$attachment = $this->attachmentMapper->findByAppointmentAndFile($appointmentId, $fileId);
		$this->attachmentMapper->delete($attachment);
	}

	/**
	 * Get all attachments for an appointment.
	 *
	 * @param int $appointmentId
	 * @return array
	 */
	public function getAttachments(int $appointmentId): array {
		$attachments = $this->attachmentMapper->findByAppointment($appointmentId);

		return array_map(function (AppointmentAttachment $attachment) {
			$data = $attachment->jsonSerialize();
			$data['downloadUrl'] = $this->getAttachmentDownloadUrl($attachment->getFileId());
			return $data;
		}, $attachments);
	}

	/**
	 * Get download URL for a file by ID.
	 *
	 * @param int $fileId
	 * @return string
	 */
	public function getAttachmentDownloadUrl(int $fileId): string {
		return $this->urlGenerator->getAbsoluteURL('/f/' . $fileId);
	}

	/**
	 * Delete all attachments for an appointment.
	 *
	 * @param int $appointmentId
	 */
	public function deleteAllAttachments(int $appointmentId): void {
		$this->attachmentMapper->deleteByAppointment($appointmentId);
	}

	/**
	 * Copy attachments from one appointment to another.
	 *
	 * @param int $sourceAppointmentId
	 * @param int $targetAppointmentId
	 * @param string $userId
	 * @return array The copied attachments
	 */
	public function copyAttachments(int $sourceAppointmentId, int $targetAppointmentId, string $userId): array {
		$sourceAttachments = $this->attachmentMapper->findByAppointment($sourceAppointmentId);
		$copiedAttachments = [];

		foreach ($sourceAttachments as $sourceAttachment) {
			$attachment = new AppointmentAttachment();
			$attachment->setAppointmentId($targetAppointmentId);
			$attachment->setFileId($sourceAttachment->getFileId());
			$attachment->setFileName($sourceAttachment->getFileName());
			$attachment->setFilePath($sourceAttachment->getFilePath());
			$attachment->setAddedBy($userId);
			$attachment->setAddedAt(date('Y-m-d H:i:s'));

			$copiedAttachments[] = $this->attachmentMapper->insert($attachment);
		}

		return $copiedAttachments;
	}
}
