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
	 *
	 * @param list<string> $userIds Addressed attendees to notify
	 */
	public function sendNewAppointmentNotifications(Appointment $appointment, array $userIds): void {
		$this->sendLifecycleWave($appointment, $userIds, 'appointment_created');
	}

	/**
	 * Notify addressed attendees that an appointment has been cancelled (the
	 * event will not take place). Mirrors sendNewAppointmentNotifications.
	 *
	 * @param Appointment $appointment The cancelled appointment
	 * @param list<string> $userIds Addressed attendees to notify
	 */
	public function sendCancellationNotifications(Appointment $appointment, array $userIds): void {
		$this->sendLifecycleWave($appointment, $userIds, 'appointment_cancelled');
	}

	/**
	 * Notify addressed attendees that a cancelled appointment takes place after
	 * all. The counterpart to sendCancellationNotifications — those people were
	 * told it was off, so they need to hear that it is back on.
	 *
	 * @param Appointment $appointment The reactivated appointment
	 * @param list<string> $userIds Addressed attendees to notify
	 */
	public function sendReactivationNotifications(Appointment $appointment, array $userIds): void {
		$this->sendLifecycleWave($appointment, $userIds, 'appointment_reactivated');
	}

	/**
	 * Notify addressed attendees that details of an appointment they already
	 * know about have changed.
	 *
	 * @param Appointment $appointment The edited appointment, with the new values
	 * @param list<string> $userIds Addressed attendees to notify
	 * @param list<string> $changedFields Which of the notified fields moved, e.g. ['time', 'location']
	 */
	public function sendUpdateNotifications(Appointment $appointment, array $userIds, array $changedFields): void {
		$this->sendLifecycleWave($appointment, $userIds, 'appointment_updated', ['changed' => $changedFields]);
	}

	/**
	 * Shared body of the lifecycle waves: one notification per addressed
	 * attendee, all carrying the appointment as their object so a client's
	 * deep link and markAppointmentNotificationsProcessed() keep working.
	 *
	 * @param list<string> $userIds
	 * @param array<string, mixed> $extraParameters Merged into the subject parameters
	 */
	private function sendLifecycleWave(
		Appointment $appointment,
		array $userIds,
		string $subject,
		array $extraParameters = [],
	): void {
		if (!$this->isNotificationsAppEnabled()) {
			$this->logger->warning('Cannot send notifications - notifications app is not enabled');
			return;
		}

		if (empty($userIds)) {
			return;
		}

		$appointmentUrl = $this->urlGenerator->linkToRouteAbsolute(
			'attendance.page.appointment',
			['id' => $appointment->getId()]
		);
		$subjectParameters = array_merge([
			'appointmentId' => $appointment->getId(),
			'name' => $appointment->getName(),
			'startDatetime' => $appointment->getStartDatetime(),
		], $extraParameters);

		$shouldFlush = $this->notificationManager->defer();

		$sentCount = 0;
		foreach ($userIds as $userId) {
			try {
				$notification = $this->notificationManager->createNotification();
				$notification->setApp('attendance')
					->setUser($userId)
					->setDateTime(new \DateTime())
					->setObject('appointment', (string)$appointment->getId())
					->setSubject($subject, $subjectParameters)
					->setLink($appointmentUrl);

				$this->notificationManager->notify($notification);
				$sentCount++;
			} catch (\Exception $e) {
				$this->logger->error('Failed to send appointment notification', [
					'subject' => $subject,
					'userId' => $userId,
					'appointmentId' => $appointment->getId(),
					'error' => $e->getMessage(),
				]);
			}
		}

		if ($shouldFlush) {
			$this->notificationManager->flush();
		}

		$this->logger->info('Finished sending appointment notifications', [
			'subject' => $subject,
			'appointmentId' => $appointment->getId(),
			'totalUsers' => count($userIds),
			'sentCount' => $sentCount,
		]);
	}

	/**
	 * Notify a single attendee about their booking outcome when an appointment
	 * is closed: booked ("you are planned in") or not ("someone else this time").
	 *
	 * @param Appointment $appointment The closed appointment
	 * @param string $userId The attendee to notify
	 * @param string $status 'booked' or 'declined'
	 */
	public function sendBookingNotification(Appointment $appointment, string $userId, string $status): void {
		if (!$this->isNotificationsAppEnabled()) {
			return;
		}

		$subject = $status === 'booked' ? 'booking_confirmed' : 'booking_declined';
		$appointmentUrl = $this->urlGenerator->linkToRouteAbsolute(
			'attendance.page.appointment',
			['id' => $appointment->getId()]
		);

		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp('attendance')
				->setUser($userId)
				->setDateTime(new \DateTime())
				->setObject('appointment', (string)$appointment->getId())
				->setSubject($subject, [
					'appointmentId' => $appointment->getId(),
					'name' => $appointment->getName(),
					'startDatetime' => $appointment->getStartDatetime(),
				])
				->setLink($appointmentUrl);

			$this->notificationManager->notify($notification);
		} catch (\Exception $e) {
			$this->logger->error('Failed to send booking notification', [
				'userId' => $userId,
				'appointmentId' => $appointment->getId(),
				'status' => $status,
				'error' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Send an appointment reminder notification to a single user.
	 * Uses the same notification format as ReminderJob but does NOT write to ReminderLogMapper.
	 *
	 * @param \OCA\Attendance\Db\Appointment $appointment The appointment to remind about
	 * @param string $userId The user to send the reminder to
	 */
	public function sendReminderToUser(Appointment $appointment, string $userId, bool $isTest = false): void {
		if (!$this->isNotificationsAppEnabled()) {
			$this->logger->warning('Cannot send reminder - notifications app is not enabled');
			return;
		}

		if ($appointment->isClosed()) {
			$this->logger->warning('Refusing to send reminder for closed appointment', [
				'appointmentId' => $appointment->getId(),
				'userId' => $userId,
			]);
			return;
		}

		$this->sendReminderNotification($appointment, $userId, $isTest);
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

		if ($appointment->isClosed()) {
			$this->logger->warning('Refusing to send reminders for closed appointment', [
				'appointmentId' => $appointment->getId(),
				'userCount' => count($userIds),
			]);
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
	private function sendReminderNotification(Appointment $appointment, string $userId, bool $isTest = false): void {
		$appointmentUrl = $this->urlGenerator->linkToRouteAbsolute(
			'attendance.page.appointment',
			['id' => $appointment->getId()]
		);

		$subjectParameters = [
			'appointmentId' => $appointment->getId(),
			'name' => $appointment->getName(),
			'startDatetime' => $appointment->getStartDatetime(),
		];
		// Test reminders bypass the already-responded dismissal in the Notifier,
		// so admins can verify the delivery chain regardless of their own response.
		if ($isTest) {
			$subjectParameters['test'] = true;
		}

		$notification = $this->notificationManager->createNotification();
		$notification->setApp('attendance')
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('appointment', (string)$appointment->getId())
			->setSubject('appointment_reminder', $subjectParameters)
			->setLink($appointmentUrl);

		$this->notificationManager->notify($notification);

		$this->logger->debug('Sent reminder notification', [
			'userId' => $userId,
			'appointmentId' => $appointment->getId(),
		]);
	}

	/**
	 * Send a single notification about multiple new appointments
	 *
	 * @param list<string> $userIds Addressed attendees to notify
	 */
	public function sendBulkAppointmentNotifications(int $count, string $firstName, array $userIds): void {
		$this->sendAggregateWave('appointments_bulk_created', [
			'count' => $count,
			'firstName' => $firstName,
		], $userIds);
	}

	/**
	 * Notify addressed attendees that appointments across a series moved. One
	 * notification covers the whole series — see
	 * AppointmentService::notifyAboutSeriesUpdate().
	 *
	 * @param int $count How many upcoming appointments in the series moved
	 * @param string $name The series' shared appointment name
	 * @param list<string> $changedFields Which of the notified fields moved
	 * @param list<string> $userIds Addressed attendees to notify
	 */
	public function sendSeriesUpdateNotifications(int $count, string $name, array $changedFields, array $userIds): void {
		$this->sendAggregateWave('appointments_series_updated', [
			'count' => $count,
			'name' => $name,
			'changed' => $changedFields,
		], $userIds);
	}

	/**
	 * Shared body of the waves that speak about several appointments at once.
	 * They carry no single appointment, so they link to the list and group
	 * under their own object type rather than 'appointment'.
	 *
	 * @param array<string, mixed> $subjectParameters
	 * @param list<string> $userIds
	 */
	private function sendAggregateWave(string $subject, array $subjectParameters, array $userIds): void {
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
					->setSubject($subject, $subjectParameters)
					->setLink($appUrl);

				$this->notificationManager->notify($notification);
				$sentCount++;
			} catch (\Exception $e) {
				$this->logger->error('Failed to send aggregate appointment notification', [
					'subject' => $subject,
					'userId' => $userId,
					'error' => $e->getMessage(),
				]);
			}
		}

		if ($shouldFlush) {
			$this->notificationManager->flush();
		}

		$this->logger->info('Finished sending aggregate appointment notifications', [
			'subject' => $subject,
			'totalUsers' => count($userIds),
			'sentCount' => $sentCount,
		]);
	}
}
