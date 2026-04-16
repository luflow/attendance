<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Appointment;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

class NotificationService {
	private INotificationManager $notificationManager;
	private IAppManager $appManager;
	private IURLGenerator $urlGenerator;
	private IDBConnection $db;
	private LoggerInterface $logger;

	public function __construct(
		INotificationManager $notificationManager,
		IAppManager $appManager,
		IURLGenerator $urlGenerator,
		IDBConnection $db,
		LoggerInterface $logger,
	) {
		$this->notificationManager = $notificationManager;
		$this->appManager = $appManager;
		$this->urlGenerator = $urlGenerator;
		$this->db = $db;
		$this->logger = $logger;
	}

	/**
	 * Check if the notifications app is enabled
	 */
	public function isNotificationsAppEnabled(): bool {
		return $this->appManager->isEnabledForUser('notifications');
	}

	/**
	 * Count how many push devices (from the notifications app) are registered for a user.
	 * Returns 0 if the notifications app is not installed or the query fails.
	 */
	public function countPushDevices(string $uid): int {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'device_count'))
				->from('notifications_pushhash')
				->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
			$result = $qb->executeQuery();
			$count = (int)$result->fetchOne();
			$result->closeCursor();
			return $count;
		} catch (\Exception $e) {
			$this->logger->debug('Could not query push devices: ' . $e->getMessage());
			return 0;
		}
	}

	/**
	 * Check whether a user has at least one push device registered.
	 * Cheaper than counting when the caller only needs a boolean.
	 */
	public function hasPushDevice(string $uid): bool {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('uid')
				->from('notifications_pushhash')
				->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
				->setMaxResults(1);
			$result = $qb->executeQuery();
			$found = $result->fetchOne() !== false;
			$result->closeCursor();
			return $found;
		} catch (\Exception $e) {
			$this->logger->debug('Could not query push devices: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Mark all attendance notifications as processed for a user on a specific appointment.
	 * Should be called when a user submits a response.
	 *
	 * @param int $appointmentId The appointment ID
	 * @param string $userId The user ID
	 */
	public function markAppointmentNotificationsProcessed(int $appointmentId, string $userId): void {
		if (!$this->isNotificationsAppEnabled()) {
			return;
		}

		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp('attendance')
				->setUser($userId)
				->setObject('appointment', (string)$appointmentId);
			$this->notificationManager->markProcessed($notification);
		} catch (\Exception $e) {
			$this->logger->error('Failed to mark notifications as processed', [
				'userId' => $userId,
				'appointmentId' => $appointmentId,
				'error' => $e->getMessage(),
			]);
		}
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

		$shouldFlush = $this->notificationManager->defer();

		$sentCount = 0;
		foreach ($userIds as $userId) {
			try {
				$notification = $this->notificationManager->createNotification();
				$notification->setApp('attendance')
					->setUser($userId)
					->setDateTime(new \DateTime())
					->setObject('appointment', (string)$appointment->getId())
					->setSubject('appointment_created', [
						'appointmentId' => $appointment->getId(),
						'name' => $appointment->getName(),
						'startDatetime' => $appointment->getStartDatetime(),
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

		if ($shouldFlush) {
			$this->notificationManager->flush();
		}

		$this->logger->info('Finished sending new appointment notifications', [
			'appointmentId' => $appointment->getId(),
			'totalUsers' => count($userIds),
			'sentCount' => $sentCount,
		]);
	}

	/**
	 * Send an appointment reminder notification to a single user.
	 * Uses the same notification format as ReminderJob but does NOT write to ReminderLogMapper.
	 *
	 * @param \OCA\Attendance\Db\Appointment $appointment The appointment to remind about
	 * @param string $userId The user to send the reminder to
	 */
	public function sendReminderToUser(Appointment $appointment, string $userId): void {
		if (!$this->isNotificationsAppEnabled()) {
			$this->logger->warning('Cannot send reminder - notifications app is not enabled');
			return;
		}

		$this->sendReminderNotification($appointment, $userId);
	}

	/**
	 * Send appointment reminder notifications to multiple users.
	 *
	 * @param \OCA\Attendance\Db\Appointment $appointment The appointment to remind about
	 * @param list<string> $userIds The users to send reminders to
	 * @return int Number of reminders sent
	 */
	public function sendReminderToUsers(Appointment $appointment, array $userIds): int {
		if (!$this->isNotificationsAppEnabled()) {
			$this->logger->warning('Cannot send reminders - notifications app is not enabled');
			return 0;
		}

		if (empty($userIds)) {
			return 0;
		}

		$shouldFlush = $this->notificationManager->defer();

		$sentCount = 0;
		foreach ($userIds as $userId) {
			try {
				$this->sendReminderNotification($appointment, $userId);
				$sentCount++;
			} catch (\Exception $e) {
				$this->logger->error('Failed to send manual reminder', [
					'userId' => $userId,
					'appointmentId' => $appointment->getId(),
					'error' => $e->getMessage(),
				]);
			}
		}

		if ($shouldFlush) {
			$this->notificationManager->flush();
		}

		$this->logger->info('Finished sending manual reminders', [
			'appointmentId' => $appointment->getId(),
			'totalUsers' => count($userIds),
			'sentCount' => $sentCount,
		]);

		return $sentCount;
	}

	/**
	 * Internal: create and send a single reminder notification.
	 * Does not check isNotificationsAppEnabled — callers must check first.
	 */
	private function sendReminderNotification(Appointment $appointment, string $userId): void {
		$appointmentUrl = $this->urlGenerator->linkToRouteAbsolute(
			'attendance.page.appointment',
			['id' => $appointment->getId()]
		);

		$notification = $this->notificationManager->createNotification();
		$notification->setApp('attendance')
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('appointment', (string)$appointment->getId())
			->setSubject('appointment_reminder', [
				'appointmentId' => $appointment->getId(),
				'name' => $appointment->getName(),
				'startDatetime' => $appointment->getStartDatetime(),
			])
			->setLink($appointmentUrl);

		$this->notificationManager->notify($notification);

		$this->logger->debug('Sent reminder notification', [
			'userId' => $userId,
			'appointmentId' => $appointment->getId(),
		]);
	}

	/**
	 * Send a single notification about multiple new appointments
	 */
	public function sendBulkAppointmentNotifications(int $count, string $firstName, array $userIds): void {
		if (!$this->isNotificationsAppEnabled()) {
			$this->logger->warning('Cannot send notifications - notifications app is not enabled');
			return;
		}

		if (empty($userIds)) {
			return;
		}

		$appUrl = $this->urlGenerator->linkToRouteAbsolute('attendance.page.index');

		$shouldFlush = $this->notificationManager->defer();

		$sentCount = 0;
		foreach ($userIds as $userId) {
			try {
				$notification = $this->notificationManager->createNotification();
				$notification->setApp('attendance')
					->setUser($userId)
					->setDateTime(new \DateTime())
					->setObject('appointment_bulk', uniqid())
					->setSubject('appointments_bulk_created', [
						'count' => $count,
						'firstName' => $firstName,
					])
					->setLink($appUrl);

				$this->notificationManager->notify($notification);
				$sentCount++;
			} catch (\Exception $e) {
				$this->logger->error('Failed to send bulk appointment notification', [
					'userId' => $userId,
					'error' => $e->getMessage(),
				]);
			}
		}

		if ($shouldFlush) {
			$this->notificationManager->flush();
		}

		$this->logger->info('Finished sending bulk appointment notifications', [
			'count' => $count,
			'totalUsers' => count($userIds),
			'sentCount' => $sentCount,
		]);
	}
}
