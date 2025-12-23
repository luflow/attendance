<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

class NotificationService {
	private INotificationManager $notificationManager;
	private IAppManager $appManager;
	private IURLGenerator $urlGenerator;
	private LoggerInterface $logger;

	public function __construct(
		INotificationManager $notificationManager,
		IAppManager $appManager,
		IURLGenerator $urlGenerator,
		LoggerInterface $logger
	) {
		$this->notificationManager = $notificationManager;
		$this->appManager = $appManager;
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
	}

	/**
	 * Check if the notifications app is enabled
	 */
	public function isNotificationsAppEnabled(): bool {
		return $this->appManager->isEnabledForUser('notifications');
	}

	/**
	 * Send notifications about a new appointment to specified users
	 */
	public function sendNewAppointmentNotifications(Appointment $appointment, array $userIds): void {
		if (!$this->isNotificationsAppEnabled()) {
			$this->logger->warning('Cannot send notifications - notifications app is not enabled');
			return;
		}

		if (empty($userIds)) {
			$this->logger->info('No users to notify about new appointment', [
				'appointmentId' => $appointment->getId(),
			]);
			return;
		}

		$appointmentUrl = $this->urlGenerator->linkToRouteAbsolute(
			'attendance.page.appointment',
			['id' => $appointment->getId()]
		);

		$sentCount = 0;
		foreach ($userIds as $userId) {
			try {
				$notification = $this->notificationManager->createNotification();
				$notification->setApp('attendance')
					->setUser($userId)
					->setDateTime(new \DateTime())
					->setObject('appointment', (string)$appointment->getId())
					->setSubject('appointment_created', [
						'name' => $appointment->getName(),
						'date' => date('d.m.Y H:i', strtotime($appointment->getStartDatetime())),
					])
					->setLink($appointmentUrl);

				$this->notificationManager->notify($notification);
				$sentCount++;

				$this->logger->debug('Sent new appointment notification', [
					'userId' => $userId,
					'appointmentId' => $appointment->getId(),
				]);
			} catch (\Exception $e) {
				$this->logger->error('Failed to send new appointment notification', [
					'userId' => $userId,
					'appointmentId' => $appointment->getId(),
					'error' => $e->getMessage(),
				]);
			}
		}

		$this->logger->info('Finished sending new appointment notifications', [
			'appointmentId' => $appointment->getId(),
			'totalUsers' => count($userIds),
			'sentCount' => $sentCount,
		]);
	}
}
