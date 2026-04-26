<?php

declare(strict_types=1);

namespace OCA\Attendance\BackgroundJob;

use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Db\ReminderLog;
use OCA\Attendance\Db\ReminderLogMapper;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\VisibilityService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

class ReminderJob extends TimedJob {
	/** @var int Interval in seconds (24 hours) */
	private const INTERVAL_DAILY = 86400;

	/** @var int Default number of days before appointment to start reminding */
	private const DEFAULT_REMINDER_DAYS = 7;

	/** @var int Default reminder frequency (0 = remind once only) */
	private const DEFAULT_REMINDER_FREQUENCY = 0;

	private AppointmentMapper $appointmentMapper;
	private AttendanceResponseMapper $responseMapper;
	private ReminderLogMapper $reminderLogMapper;
	private VisibilityService $visibilityService;
	private ConfigService $configService;
	private IConfig $config;
	private INotificationManager $notificationManager;
	private IURLGenerator $urlGenerator;
	private LoggerInterface $logger;

	public function __construct(
		ITimeFactory $time,
		AppointmentMapper $appointmentMapper,
		AttendanceResponseMapper $responseMapper,
		ReminderLogMapper $reminderLogMapper,
		VisibilityService $visibilityService,
		ConfigService $configService,
		IConfig $config,
		INotificationManager $notificationManager,
		IURLGenerator $urlGenerator,
		LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->appointmentMapper = $appointmentMapper;
		$this->responseMapper = $responseMapper;
		$this->reminderLogMapper = $reminderLogMapper;
		$this->visibilityService = $visibilityService;
		$this->configService = $configService;
		$this->config = $config;
		$this->notificationManager = $notificationManager;
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;

		$this->setInterval(self::INTERVAL_DAILY);
	}

	protected function run($argument): void {
		$this->logger->info('Reminder job starting...');

		$enabled = $this->config->getAppValue('attendance', 'reminders_enabled', 'no') === 'yes';
		$this->logger->info('Reminders enabled check', ['enabled' => $enabled ? 'yes' : 'no']);

		if (!$enabled) {
			$this->logger->info('Reminder job stopped - reminders are disabled');
			return;
		}

		$reminderDays = (int)$this->config->getAppValue('attendance', 'reminder_days', (string)self::DEFAULT_REMINDER_DAYS);
		$reminderFrequency = (int)$this->config->getAppValue('attendance', 'reminder_frequency', (string)self::DEFAULT_REMINDER_FREQUENCY);
		$reminderTarget = $this->config->getAppValue('attendance', 'reminder_target', 'non_responders');
		if (!in_array($reminderTarget, ConfigService::VALID_REMINDER_TARGETS, true)) {
			$reminderTarget = 'non_responders';
		}
		$this->logger->info('Reminder configuration', [
			'reminderDays' => $reminderDays,
			'reminderFrequencyDays' => $reminderFrequency,
			'reminderTarget' => $reminderTarget,
		]);

		$utc = new \DateTimeZone('UTC');
		$today = new \DateTime('now', $utc);
		$todayStr = $today->format('Y-m-d');
		$maxDateStr = (clone $today)->modify("+{$reminderDays} days")->format('Y-m-d');

		$appointments = $this->appointmentMapper->findRemindable($todayStr, $maxDateStr);
		$this->logger->info('Found appointments in date range', [
			'today' => $todayStr,
			'maxDate' => $maxDateStr,
			'count' => count($appointments),
		]);

		$shouldFlush = $this->notificationManager->defer();

		$processedCount = 0;
		$sentCount = 0;

		foreach ($appointments as $appointment) {
			$processedCount++;
			$this->logger->info('Processing appointment for reminders', [
				'id' => $appointment->getId(),
				'name' => $appointment->getName(),
				'startDatetime' => $appointment->getStartDatetime(),
			]);

			// Get all responses for this appointment (store response value for target-aware filtering)
			$responses = $this->responseMapper->findByAppointment($appointment->getId());
			$respondedUserIds = [];
			foreach ($responses as $response) {
				$respondedUserIds[$response->getUserId()] = $response->getResponse();
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

			// Get only users who should see this appointment (respects visibility settings)
			$whitelistedGroups = $this->configService->getWhitelistedGroups();
			$relevantUsers = $this->visibilityService->getRelevantUsersForAppointment($appointment, $whitelistedGroups);
			$this->logger->info('Relevant users for appointment', [
				'appointmentId' => $appointment->getId(),
				'count' => count($relevantUsers),
				'hasRestrictedVisibility' => $this->visibilityService->hasRestrictedVisibility($appointment),
			]);

			$skippedCount = 0;

			foreach ($relevantUsers as $user) {
				$userId = $user->getUID();

				// Target-aware skip logic
				$userResponse = $respondedUserIds[$userId] ?? null;
				if ($userResponse !== null) {
					// User responded — only remind if they said 'maybe' and target includes maybe
					$shouldRemindMaybe = ($userResponse === 'maybe')
						&& in_array($reminderTarget, ['maybe', 'both'], true);
					if (!$shouldRemindMaybe) {
						$skippedCount++;
						$this->logger->debug('Skipping user - already responded', ['userId' => $userId, 'response' => $userResponse]);
						continue;
					}
				} else {
					// No response — only remind if target includes non_responders
					if (!in_array($reminderTarget, ['non_responders', 'both'], true)) {
						$skippedCount++;
						$this->logger->debug('Skipping user - no response but target excludes non-responders', ['userId' => $userId]);
						continue;
					}
				}

				// Check if user was recently reminded (O(1) lookup from pre-fetched map)
				$lastReminder = $latestReminderByUser[$userId] ?? null;
				if ($lastReminder !== null && $reminderFrequency > 0) {
					// Compare calendar dates only (not exact times) to avoid off-by-one
					// errors caused by cron execution time jitter. Both dates are in UTC
					// since reminded_at is stored via gmdate() and today uses UTC timezone.
					$lastReminderDate = new \DateTime($lastReminder->getRemindedAt(), $utc);
					$lastReminderDate->setTime(0, 0, 0);
					$todayMidnight = (clone $today)->setTime(0, 0, 0);
					$daysSinceReminder = (int)$todayMidnight->diff($lastReminderDate)->days;

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
						->setDateTime(new \DateTime('now', $utc))
						->setObject('appointment', (string)$appointment->getId())
						->setSubject('appointment_reminder', [
							'appointmentId' => $appointment->getId(),
							'name' => $appointment->getName(),
							'startDatetime' => $appointment->getStartDatetime(),
						])
						->setLink($appointmentUrl);

					$this->notificationManager->notify($notification);

					// Log the reminder
					$reminderLog = new ReminderLog();
					$reminderLog->setAppointmentId($appointment->getId());
					$reminderLog->setUserId($userId);
					$reminderLog->setRemindedAt(gmdate('Y-m-d H:i:s'));
					$this->reminderLogMapper->insert($reminderLog);

					$sentCount++;

					$this->logger->info('Successfully sent notification and logged reminder', [
						'userId' => $userId,
						'appointmentId' => $appointment->getId(),
					]);
				} catch (\Throwable $e) {
					$this->logger->error('Failed to send notification', [
						'userId' => $userId,
						'appointmentId' => $appointment->getId(),
						'error' => $e->getMessage(),
					]);
				}
			}

			$this->logger->info('Finished processing appointment', [
				'appointmentId' => $appointment->getId(),
				'relevantUsers' => count($relevantUsers),
				'skippedResponded' => $skippedCount,
				'sentNotifications' => $sentCount,
			]);
		}

		if ($shouldFlush) {
			$this->notificationManager->flush();
		}

		$this->logger->info('Reminder job completed', [
			'processedAppointments' => $processedCount,
			'sentNotifications' => $sentCount,
		]);
	}
}
