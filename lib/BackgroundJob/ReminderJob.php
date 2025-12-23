<?php

declare(strict_types=1);

namespace OCA\Attendance\BackgroundJob;

use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Db\ReminderLog;
use OCA\Attendance\Db\ReminderLogMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

class ReminderJob extends TimedJob {
	private AppointmentMapper $appointmentMapper;
	private AttendanceResponseMapper $responseMapper;
	private ReminderLogMapper $reminderLogMapper;
	private IUserManager $userManager;
	private IConfig $config;
	private INotificationManager $notificationManager;
	private IURLGenerator $urlGenerator;
	private LoggerInterface $logger;

	public function __construct(
		ITimeFactory $time,
		AppointmentMapper $appointmentMapper,
		AttendanceResponseMapper $responseMapper,
		ReminderLogMapper $reminderLogMapper,
		IUserManager $userManager,
		IConfig $config,
		INotificationManager $notificationManager,
		IURLGenerator $urlGenerator,
		LoggerInterface $logger
	) {
		parent::__construct($time);
		
		$this->appointmentMapper = $appointmentMapper;
		$this->responseMapper = $responseMapper;
		$this->reminderLogMapper = $reminderLogMapper;
		$this->userManager = $userManager;
		$this->config = $config;
		$this->notificationManager = $notificationManager;
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;

		// Run daily
		$this->setInterval(24 * 60 * 60);
	}

	protected function run($argument): void {
		$this->logger->info('Reminder job starting...');
		
		// Check if reminders are enabled
		$enabled = $this->config->getAppValue('attendance', 'reminders_enabled', 'no') === 'yes';
		$this->logger->info('Reminders enabled check', ['enabled' => $enabled ? 'yes' : 'no']);
		
		if (!$enabled) {
			$this->logger->info('Reminder job stopped - reminders are disabled');
			return;
		}

		// Get reminder days setting (how far in advance to remind)
		$reminderDays = (int)$this->config->getAppValue('attendance', 'reminder_days', '7');
		$this->logger->info('Reminder days configuration', ['days' => $reminderDays]);

		// Get reminder frequency setting (how often to remind in days)
		$reminderFrequency = (int)$this->config->getAppValue('attendance', 'reminder_frequency', '0');
		$this->logger->info('Reminder frequency configuration', ['frequency_days' => $reminderFrequency]);

		// Calculate date range: today until X days in the future
		$today = new \DateTime();
		$today->setTime(0, 0, 0);
		$todayStr = $today->format('Y-m-d H:i:s');
		
		$maxDate = new \DateTime();
		$maxDate->modify("+{$reminderDays} days");
		$maxDate->setTime(23, 59, 59);
		$maxDateStr = $maxDate->format('Y-m-d H:i:s');

		$this->logger->info('Checking appointments in date range', [
			'today' => $today->format('Y-m-d'),
			'maxDate' => $maxDate->format('Y-m-d'),
			'reminderDays' => $reminderDays,
		]);

		// Find all appointments in the next X days
		$appointments = $this->appointmentMapper->findAll();
		$this->logger->info('Found appointments in database', ['total' => count($appointments)]);
		
		$processedCount = 0;
		$sentCount = 0;

		foreach ($appointments as $appointment) {
			$appointmentDate = $appointment->getStartDatetime();
			
			$inRange = ($appointmentDate >= $todayStr && $appointmentDate <= $maxDateStr);
			
			$this->logger->debug('Checking appointment', [
				'id' => $appointment->getId(),
				'name' => $appointment->getName(),
				'startDatetime' => $appointmentDate,
				'inRange' => $inRange ? 'true' : 'false',
			]);
			
			// Check if appointment is in the next X days (including today)
			if (!$inRange) {
				continue;
			}

			$processedCount++;
			$this->logger->info('Processing appointment for reminders', [
				'id' => $appointment->getId(),
				'name' => $appointment->getName(),
				'startDatetime' => $appointmentDate,
			]);

			// Get all responses for this appointment
			$responses = $this->responseMapper->findByAppointment($appointment->getId());
			$respondedUserIds = [];
			foreach ($responses as $response) {
				$respondedUserIds[$response->getUserId()] = true;
			}

			$this->logger->info('Responses found for appointment', [
				'appointmentId' => $appointment->getId(),
				'responseCount' => count($responses),
				'respondedUsers' => array_keys($respondedUserIds),
			]);

			// Batch fetch all reminder logs for this appointment (N+1 fix)
			$allReminderLogs = $this->reminderLogMapper->findByAppointment($appointment->getId());
			$latestReminderByUser = [];
			foreach ($allReminderLogs as $log) {
				$userId = $log->getUserId();
				// Keep only the latest reminder per user (already sorted DESC by reminded_at)
				if (!isset($latestReminderByUser[$userId])) {
					$latestReminderByUser[$userId] = $log;
				}
			}

			// Get all users from the system
			$allUsers = $this->userManager->search('');
			$this->logger->info('Total users in system', ['count' => count($allUsers)]);

			$skippedCount = 0;

			foreach ($allUsers as $user) {
				$userId = $user->getUID();

				// Skip if user already responded (O(1) lookup with hash map)
				if (isset($respondedUserIds[$userId])) {
					$skippedCount++;
					$this->logger->debug('Skipping user - already responded', ['userId' => $userId]);
					continue;
				}

				// Check if user was recently reminded (O(1) lookup from pre-fetched map)
				$lastReminder = $latestReminderByUser[$userId] ?? null;
				if ($lastReminder !== null && $reminderFrequency > 0) {
					$lastReminderDate = new \DateTime($lastReminder->getRemindedAt());
					$daysSinceReminder = (int)$today->diff($lastReminderDate)->days;

					if ($daysSinceReminder < $reminderFrequency) {
						$skippedCount++;
						$this->logger->debug('Skipping user - recently reminded', [
							'userId' => $userId,
							'daysSinceReminder' => $daysSinceReminder,
							'requiredFrequency' => $reminderFrequency,
						]);
						continue;
					}
				} elseif ($lastReminder !== null && $reminderFrequency === 0) {
					// Frequency 0 means only remind once
					$skippedCount++;
					$this->logger->debug('Skipping user - already reminded once', [
						'userId' => $userId,
						'remindedAt' => $lastReminder->getRemindedAt(),
					]);
					continue;
				}

				$this->logger->info('Sending notification to user', [
					'userId' => $userId,
					'appointmentId' => $appointment->getId(),
				]);

				// Send notification
				try {
					$notification = $this->notificationManager->createNotification();
					// Link directly to appointment detail page
					$appointmentUrl = $this->urlGenerator->linkToRouteAbsolute(
						'attendance.page.appointment',
						['id' => $appointment->getId()]
					);
					
					$notification->setApp('attendance')
						->setUser($userId)
						->setDateTime(new \DateTime())
						->setObject('appointment', (string)$appointment->getId())
						->setSubject('appointment_reminder', [
							'name' => $appointment->getName(),
							'date' => date('d.m.Y H:i', strtotime($appointment->getStartDatetime())),
						])
						->setLink($appointmentUrl);
					
					$this->notificationManager->notify($notification);
					
					// Log the reminder
					$reminderLog = new ReminderLog();
					$reminderLog->setAppointmentId($appointment->getId());
					$reminderLog->setUserId($userId);
					$reminderLog->setRemindedAt(date('Y-m-d H:i:s'));
					$this->reminderLogMapper->insert($reminderLog);
					
					$sentCount++;
					
					$this->logger->info('Successfully sent notification and logged reminder', [
						'userId' => $userId,
						'appointmentId' => $appointment->getId(),
					]);
				} catch (\Exception $e) {
					$this->logger->error('Failed to send notification', [
						'userId' => $userId,
						'appointmentId' => $appointment->getId(),
						'error' => $e->getMessage(),
					]);
				}
			}
		
			$this->logger->info('Finished processing appointment', [
				'appointmentId' => $appointment->getId(),
				'totalUsers' => count($allUsers),
				'skippedResponded' => $skippedCount,
				'sentNotifications' => $sentCount,
			]);
		}

		$this->logger->info('Reminder job completed', [
			'processedAppointments' => $processedCount,
			'sentNotifications' => $sentCount,
		]);
	}
}
