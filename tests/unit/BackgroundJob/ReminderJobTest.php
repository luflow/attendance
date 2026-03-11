<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\BackgroundJob;

use OCA\Attendance\BackgroundJob\ReminderJob;
use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Db\ReminderLog;
use OCA\Attendance\Db\ReminderLogMapper;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\VisibilityService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReminderJobTest extends TestCase {
	private AppointmentMapper|MockObject $appointmentMapper;
	private AttendanceResponseMapper|MockObject $responseMapper;
	private ReminderLogMapper|MockObject $reminderLogMapper;
	private VisibilityService|MockObject $visibilityService;
	private ConfigService|MockObject $configService;
	private IConfig|MockObject $config;
	private INotificationManager|MockObject $notificationManager;
	private IURLGenerator|MockObject $urlGenerator;
	private LoggerInterface|MockObject $logger;
	private ITimeFactory|MockObject $timeFactory;
	private ReminderJob $job;

	protected function setUp(): void {
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);
		$this->reminderLogMapper = $this->createMock(ReminderLogMapper::class);
		$this->visibilityService = $this->createMock(VisibilityService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);

		$this->job = new ReminderJob(
			$this->timeFactory,
			$this->appointmentMapper,
			$this->responseMapper,
			$this->reminderLogMapper,
			$this->visibilityService,
			$this->configService,
			$this->config,
			$this->notificationManager,
			$this->urlGenerator,
			$this->logger,
		);
	}

	/**
	 * Helper: invoke the protected run() method directly.
	 */
	private function runJob(): void {
		$reflection = new \ReflectionMethod($this->job, 'run');
		$reflection->invoke($this->job, null);
	}

	/**
	 * Helper: create an Appointment mock with the given properties.
	 */
	private function makeAppointment(int $id, string $name, string $startDatetime): Appointment {
		$appointment = new Appointment();
		$appointment->setId($id);
		$appointment->setName($name);
		$appointment->setStartDatetime($startDatetime);
		$appointment->setEndDatetime($startDatetime); // not used in reminder logic
		$appointment->setIsActive(1);
		return $appointment;
	}

	/**
	 * Helper: create a ReminderLog with the given properties.
	 */
	private function makeReminderLog(int $appointmentId, string $userId, string $remindedAt): ReminderLog {
		$log = new ReminderLog();
		$log->setAppointmentId($appointmentId);
		$log->setUserId($userId);
		$log->setRemindedAt($remindedAt);
		return $log;
	}

	/**
	 * Helper: create a mock IUser.
	 */
	private function makeUser(string $uid): IUser|MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	/**
	 * Helper: set up the standard config mock for enabled reminders.
	 */
	private function configureReminders(bool $enabled, int $days, int $frequency): void {
		$this->config->method('getAppValue')
			->willReturnCallback(function (string $app, string $key, string $default) use ($enabled, $days, $frequency) {
				return match ($key) {
					'reminders_enabled' => $enabled ? 'yes' : 'no',
					'reminder_days' => (string)$days,
					'reminder_frequency' => (string)$frequency,
					default => $default,
				};
			});
	}

	/**
	 * Helper: set up standard notification mocking (returns a fluent INotification mock).
	 */
	private function mockNotifications(): INotification|MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$notification->method('setLink')->willReturnSelf();
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.com/appointment/1');
		return $notification;
	}

	// =========================================================================
	// Basic enable/disable
	// =========================================================================

	public function testRemindersDisabledSendsNothing(): void {
		$this->configureReminders(false, 7, 0);

		$this->appointmentMapper->expects($this->never())->method('findStartingBetween');
		$this->notificationManager->expects($this->never())->method('notify');

		$this->runJob();
	}

	// =========================================================================
	// First reminder (no previous reminders)
	// =========================================================================

	public function testFirstReminderIsSentWhenNoResponseAndNoPriorReminder(): void {
		$this->configureReminders(true, 4, 2);
		$this->mockNotifications();

		$utc = new \DateTimeZone('UTC');
		$appointmentDate = (new \DateTime('now', $utc))->modify('+3 days')->format('Y-m-d H:i:s');
		$appointment = $this->makeAppointment(1, 'Test', $appointmentDate);

		$this->appointmentMapper->method('findStartingBetween')->willReturn([$appointment]);
		$this->responseMapper->method('findByAppointment')->willReturn([]);
		$this->reminderLogMapper->method('findByAppointment')->willReturn([]);
		$this->configService->method('getWhitelistedGroups')->willReturn(['group1']);
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['alice' => $this->makeUser('alice')]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);

		$this->notificationManager->expects($this->once())->method('notify');
		$this->reminderLogMapper->expects($this->once())->method('insert');

		$this->runJob();
	}

	// =========================================================================
	// Frequency = 0 (remind once only)
	// =========================================================================

	public function testFrequencyZeroSkipsAfterFirstReminder(): void {
		$this->configureReminders(true, 7, 0);
		$this->mockNotifications();

		$utc = new \DateTimeZone('UTC');
		$appointmentDate = (new \DateTime('now', $utc))->modify('+3 days')->format('Y-m-d H:i:s');
		$appointment = $this->makeAppointment(1, 'Test', $appointmentDate);

		// User was already reminded yesterday
		$yesterdayUtc = (new \DateTime('now', $utc))->modify('-1 day')->format('Y-m-d H:i:s');
		$reminderLog = $this->makeReminderLog(1, 'alice', $yesterdayUtc);

		$this->appointmentMapper->method('findStartingBetween')->willReturn([$appointment]);
		$this->responseMapper->method('findByAppointment')->willReturn([]);
		$this->reminderLogMapper->method('findByAppointment')->willReturn([$reminderLog]);
		$this->configService->method('getWhitelistedGroups')->willReturn(['group1']);
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['alice' => $this->makeUser('alice')]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);

		$this->notificationManager->expects($this->never())->method('notify');

		$this->runJob();
	}

	// =========================================================================
	// Frequency = 2 (every 2 days) - the user's reported scenario
	// =========================================================================

	public function testFrequencyTwoSendsReminderAfterExactlyTwoDays(): void {
		$this->configureReminders(true, 4, 2);
		$this->mockNotifications();

		$utc = new \DateTimeZone('UTC');
		$appointmentDate = (new \DateTime('now', $utc))->modify('+1 day')->format('Y-m-d H:i:s');
		$appointment = $this->makeAppointment(1, 'Test', $appointmentDate);

		// Last reminder was exactly 2 calendar days ago (should trigger again)
		$twoDaysAgo = (new \DateTime('now', $utc))->modify('-2 days')->format('Y-m-d H:i:s');
		$reminderLog = $this->makeReminderLog(1, 'alice', $twoDaysAgo);

		$this->appointmentMapper->method('findStartingBetween')->willReturn([$appointment]);
		$this->responseMapper->method('findByAppointment')->willReturn([]);
		$this->reminderLogMapper->method('findByAppointment')->willReturn([$reminderLog]);
		$this->configService->method('getWhitelistedGroups')->willReturn(['group1']);
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['alice' => $this->makeUser('alice')]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);

		$this->notificationManager->expects($this->once())->method('notify');

		$this->runJob();
	}

	public function testFrequencyTwoSkipsAfterOnlyOneDay(): void {
		$this->configureReminders(true, 4, 2);
		$this->mockNotifications();

		$utc = new \DateTimeZone('UTC');
		$appointmentDate = (new \DateTime('now', $utc))->modify('+2 days')->format('Y-m-d H:i:s');
		$appointment = $this->makeAppointment(1, 'Test', $appointmentDate);

		// Last reminder was only 1 day ago (should NOT trigger yet)
		$oneDayAgo = (new \DateTime('now', $utc))->modify('-1 day')->format('Y-m-d H:i:s');
		$reminderLog = $this->makeReminderLog(1, 'alice', $oneDayAgo);

		$this->appointmentMapper->method('findStartingBetween')->willReturn([$appointment]);
		$this->responseMapper->method('findByAppointment')->willReturn([]);
		$this->reminderLogMapper->method('findByAppointment')->willReturn([$reminderLog]);
		$this->configService->method('getWhitelistedGroups')->willReturn(['group1']);
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['alice' => $this->makeUser('alice')]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);

		$this->notificationManager->expects($this->never())->method('notify');

		$this->runJob();
	}

	// =========================================================================
	// Cron timing jitter: ensure calendar-day comparison, not 24h periods
	// =========================================================================

	/**
	 * Simulate: reminder was sent late evening, cron runs early morning 2 days later.
	 * Less than 48 hours apart but 2 calendar days — should still trigger.
	 */
	public function testCalendarDayComparisonIgnoresCronTimeJitter(): void {
		$this->configureReminders(true, 7, 2);
		$this->mockNotifications();

		$utc = new \DateTimeZone('UTC');
		$appointmentDate = (new \DateTime('now', $utc))->modify('+3 days')->format('Y-m-d H:i:s');
		$appointment = $this->makeAppointment(1, 'Test', $appointmentDate);

		// Reminder sent at 23:55 two calendar days ago (less than 48h ago)
		$twoDaysAgoLateEvening = (new \DateTime('now', $utc))
			->modify('-2 days')
			->setTime(23, 55, 0)
			->format('Y-m-d H:i:s');
		$reminderLog = $this->makeReminderLog(1, 'alice', $twoDaysAgoLateEvening);

		$this->appointmentMapper->method('findStartingBetween')->willReturn([$appointment]);
		$this->responseMapper->method('findByAppointment')->willReturn([]);
		$this->reminderLogMapper->method('findByAppointment')->willReturn([$reminderLog]);
		$this->configService->method('getWhitelistedGroups')->willReturn(['group1']);
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['alice' => $this->makeUser('alice')]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);

		// Must send despite being < 48 hours, because it's 2 calendar days
		$this->notificationManager->expects($this->once())->method('notify');

		$this->runJob();
	}

	/**
	 * Simulate: reminder sent early morning, cron runs late evening same day.
	 * Same calendar day — should NOT trigger even though many hours passed.
	 */
	public function testSameCalendarDayDoesNotRetrigger(): void {
		$this->configureReminders(true, 7, 1);
		$this->mockNotifications();

		$utc = new \DateTimeZone('UTC');
		$appointmentDate = (new \DateTime('now', $utc))->modify('+3 days')->format('Y-m-d H:i:s');
		$appointment = $this->makeAppointment(1, 'Test', $appointmentDate);

		// Reminder was sent earlier today (same calendar day)
		$earlierToday = (new \DateTime('now', $utc))
			->setTime(1, 0, 0)
			->format('Y-m-d H:i:s');
		$reminderLog = $this->makeReminderLog(1, 'alice', $earlierToday);

		$this->appointmentMapper->method('findStartingBetween')->willReturn([$appointment]);
		$this->responseMapper->method('findByAppointment')->willReturn([]);
		$this->reminderLogMapper->method('findByAppointment')->willReturn([$reminderLog]);
		$this->configService->method('getWhitelistedGroups')->willReturn(['group1']);
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['alice' => $this->makeUser('alice')]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);

		$this->notificationManager->expects($this->never())->method('notify');

		$this->runJob();
	}

	// =========================================================================
	// Cron runs every few hours: multiple runs per day should not duplicate
	// =========================================================================

	public function testMultipleCronRunsSameDayOnlySendOnce(): void {
		$this->configureReminders(true, 4, 2);

		$utc = new \DateTimeZone('UTC');
		$appointmentDate = (new \DateTime('now', $utc))->modify('+3 days')->format('Y-m-d H:i:s');
		$appointment = $this->makeAppointment(1, 'Test', $appointmentDate);

		$this->appointmentMapper->method('findStartingBetween')->willReturn([$appointment]);
		$this->responseMapper->method('findByAppointment')->willReturn([]);
		$this->configService->method('getWhitelistedGroups')->willReturn(['group1']);
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['alice' => $this->makeUser('alice')]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);

		// First run: no prior reminder → sends
		$this->reminderLogMapper->method('findByAppointment')->willReturn([]);
		$notification = $this->mockNotifications();
		$this->notificationManager->expects($this->once())->method('notify');
		$this->reminderLogMapper->expects($this->once())->method('insert');

		$this->runJob();
	}

	/**
	 * Second cron run after first already sent today — should skip.
	 */
	public function testSecondCronRunSameDaySkipsBecauseAlreadySentToday(): void {
		$this->configureReminders(true, 4, 2);
		$this->mockNotifications();

		$utc = new \DateTimeZone('UTC');
		$appointmentDate = (new \DateTime('now', $utc))->modify('+3 days')->format('Y-m-d H:i:s');
		$appointment = $this->makeAppointment(1, 'Test', $appointmentDate);

		// Reminder was already sent earlier today
		$sentToday = (new \DateTime('now', $utc))->setTime(2, 0, 0)->format('Y-m-d H:i:s');
		$reminderLog = $this->makeReminderLog(1, 'alice', $sentToday);

		$this->appointmentMapper->method('findStartingBetween')->willReturn([$appointment]);
		$this->responseMapper->method('findByAppointment')->willReturn([]);
		$this->reminderLogMapper->method('findByAppointment')->willReturn([$reminderLog]);
		$this->configService->method('getWhitelistedGroups')->willReturn(['group1']);
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['alice' => $this->makeUser('alice')]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);

		// daysSinceReminder = 0, which is < 2 → skip
		$this->notificationManager->expects($this->never())->method('notify');

		$this->runJob();
	}

	// =========================================================================
	// Users who already responded should be skipped
	// =========================================================================

	public function testUserWhoRespondedIsSkipped(): void {
		$this->configureReminders(true, 4, 2);
		$this->mockNotifications();

		$utc = new \DateTimeZone('UTC');
		$appointmentDate = (new \DateTime('now', $utc))->modify('+3 days')->format('Y-m-d H:i:s');
		$appointment = $this->makeAppointment(1, 'Test', $appointmentDate);

		$response = new AttendanceResponse();
		$response->setUserId('alice');

		$this->appointmentMapper->method('findStartingBetween')->willReturn([$appointment]);
		$this->responseMapper->method('findByAppointment')->willReturn([$response]);
		$this->reminderLogMapper->method('findByAppointment')->willReturn([]);
		$this->configService->method('getWhitelistedGroups')->willReturn(['group1']);
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['alice' => $this->makeUser('alice')]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);

		$this->notificationManager->expects($this->never())->method('notify');

		$this->runJob();
	}

	// =========================================================================
	// Mixed users: some responded, some reminded recently, some need reminder
	// =========================================================================

	public function testMixedUsersOnlyUnrespondedUnremindedGetNotified(): void {
		$this->configureReminders(true, 7, 3);
		$this->mockNotifications();

		$utc = new \DateTimeZone('UTC');
		$appointmentDate = (new \DateTime('now', $utc))->modify('+2 days')->format('Y-m-d H:i:s');
		$appointment = $this->makeAppointment(1, 'Meeting', $appointmentDate);

		// alice responded
		$response = new AttendanceResponse();
		$response->setUserId('alice');

		// bob was reminded 1 day ago (too recent for frequency=3)
		$oneDayAgo = (new \DateTime('now', $utc))->modify('-1 day')->format('Y-m-d H:i:s');
		$bobLog = $this->makeReminderLog(1, 'bob', $oneDayAgo);

		// carol was reminded 3 days ago (should get reminded again)
		$threeDaysAgo = (new \DateTime('now', $utc))->modify('-3 days')->format('Y-m-d H:i:s');
		$carolLog = $this->makeReminderLog(1, 'carol', $threeDaysAgo);

		// dave has never been reminded
		// (no log entry)

		$this->appointmentMapper->method('findStartingBetween')->willReturn([$appointment]);
		$this->responseMapper->method('findByAppointment')->willReturn([$response]);
		$this->reminderLogMapper->method('findByAppointment')->willReturn([$bobLog, $carolLog]);
		$this->configService->method('getWhitelistedGroups')->willReturn(['group1']);
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn([
				'alice' => $this->makeUser('alice'),
				'bob' => $this->makeUser('bob'),
				'carol' => $this->makeUser('carol'),
				'dave' => $this->makeUser('dave'),
			]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);

		// Only carol and dave should get notified (alice responded, bob too recent)
		$this->notificationManager->expects($this->exactly(2))->method('notify');
		$this->reminderLogMapper->expects($this->exactly(2))->method('insert');

		$this->runJob();
	}

	// =========================================================================
	// Date range: appointment outside reminder window should not get reminders
	// =========================================================================

	public function testAppointmentOutsideReminderWindowGetsNoReminders(): void {
		$this->configureReminders(true, 4, 2);
		$this->mockNotifications();

		// No appointments in the window
		$this->appointmentMapper->method('findStartingBetween')->willReturn([]);

		$this->notificationManager->expects($this->never())->method('notify');

		$this->runJob();
	}

	// =========================================================================
	// Frequency = 1 (daily reminders)
	// =========================================================================

	public function testDailyFrequencySendsEveryDay(): void {
		$this->configureReminders(true, 7, 1);
		$this->mockNotifications();

		$utc = new \DateTimeZone('UTC');
		$appointmentDate = (new \DateTime('now', $utc))->modify('+3 days')->format('Y-m-d H:i:s');
		$appointment = $this->makeAppointment(1, 'Test', $appointmentDate);

		// Last reminder was yesterday
		$yesterday = (new \DateTime('now', $utc))->modify('-1 day')->format('Y-m-d H:i:s');
		$reminderLog = $this->makeReminderLog(1, 'alice', $yesterday);

		$this->appointmentMapper->method('findStartingBetween')->willReturn([$appointment]);
		$this->responseMapper->method('findByAppointment')->willReturn([]);
		$this->reminderLogMapper->method('findByAppointment')->willReturn([$reminderLog]);
		$this->configService->method('getWhitelistedGroups')->willReturn(['group1']);
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['alice' => $this->makeUser('alice')]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);

		// daysSinceReminder = 1, frequency = 1 → 1 < 1 is false → send
		$this->notificationManager->expects($this->once())->method('notify');

		$this->runJob();
	}

	public function testDailyFrequencySkipsSameDay(): void {
		$this->configureReminders(true, 7, 1);
		$this->mockNotifications();

		$utc = new \DateTimeZone('UTC');
		$appointmentDate = (new \DateTime('now', $utc))->modify('+3 days')->format('Y-m-d H:i:s');
		$appointment = $this->makeAppointment(1, 'Test', $appointmentDate);

		// Reminder already sent today
		$today = (new \DateTime('now', $utc))->format('Y-m-d H:i:s');
		$reminderLog = $this->makeReminderLog(1, 'alice', $today);

		$this->appointmentMapper->method('findStartingBetween')->willReturn([$appointment]);
		$this->responseMapper->method('findByAppointment')->willReturn([]);
		$this->reminderLogMapper->method('findByAppointment')->willReturn([$reminderLog]);
		$this->configService->method('getWhitelistedGroups')->willReturn(['group1']);
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['alice' => $this->makeUser('alice')]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);

		// daysSinceReminder = 0, frequency = 1 → 0 < 1 → skip
		$this->notificationManager->expects($this->never())->method('notify');

		$this->runJob();
	}

	// =========================================================================
	// Large frequency (e.g., every 7 days within a 30-day window)
	// =========================================================================

	public function testLargeFrequencyRespected(): void {
		$this->configureReminders(true, 30, 7);
		$this->mockNotifications();

		$utc = new \DateTimeZone('UTC');
		$appointmentDate = (new \DateTime('now', $utc))->modify('+10 days')->format('Y-m-d H:i:s');
		$appointment = $this->makeAppointment(1, 'Test', $appointmentDate);

		// Last reminder was 5 days ago (not yet 7)
		$fiveDaysAgo = (new \DateTime('now', $utc))->modify('-5 days')->format('Y-m-d H:i:s');
		$reminderLog = $this->makeReminderLog(1, 'alice', $fiveDaysAgo);

		$this->appointmentMapper->method('findStartingBetween')->willReturn([$appointment]);
		$this->responseMapper->method('findByAppointment')->willReturn([]);
		$this->reminderLogMapper->method('findByAppointment')->willReturn([$reminderLog]);
		$this->configService->method('getWhitelistedGroups')->willReturn(['group1']);
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['alice' => $this->makeUser('alice')]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);

		$this->notificationManager->expects($this->never())->method('notify');

		$this->runJob();
	}

	public function testLargeFrequencySendsWhenDue(): void {
		$this->configureReminders(true, 30, 7);
		$this->mockNotifications();

		$utc = new \DateTimeZone('UTC');
		$appointmentDate = (new \DateTime('now', $utc))->modify('+10 days')->format('Y-m-d H:i:s');
		$appointment = $this->makeAppointment(1, 'Test', $appointmentDate);

		// Last reminder was 7 days ago (exactly due)
		$sevenDaysAgo = (new \DateTime('now', $utc))->modify('-7 days')->format('Y-m-d H:i:s');
		$reminderLog = $this->makeReminderLog(1, 'alice', $sevenDaysAgo);

		$this->appointmentMapper->method('findStartingBetween')->willReturn([$appointment]);
		$this->responseMapper->method('findByAppointment')->willReturn([]);
		$this->reminderLogMapper->method('findByAppointment')->willReturn([$reminderLog]);
		$this->configService->method('getWhitelistedGroups')->willReturn(['group1']);
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['alice' => $this->makeUser('alice')]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);

		$this->notificationManager->expects($this->once())->method('notify');

		$this->runJob();
	}

	// =========================================================================
	// Full scenario: "4 days before, every 2 days" across multiple cron runs
	// This simulates the user's reported broken scenario
	// =========================================================================

	/**
	 * @dataProvider fourDaysTwoDayFrequencyProvider
	 */
	public function testFourDaysBeforeEveryTwoDays(int $daysUntilAppointment, ?int $lastReminderDaysAgo, bool $expectNotification): void {
		$this->configureReminders(true, 4, 2);
		$this->mockNotifications();

		$utc = new \DateTimeZone('UTC');
		$appointmentDate = (new \DateTime('now', $utc))
			->modify("+{$daysUntilAppointment} days")
			->setTime(10, 0, 0)
			->format('Y-m-d H:i:s');
		$appointment = $this->makeAppointment(1, 'Meeting', $appointmentDate);

		$this->appointmentMapper->method('findStartingBetween')->willReturn([$appointment]);
		$this->responseMapper->method('findByAppointment')->willReturn([]);
		$this->configService->method('getWhitelistedGroups')->willReturn(['group1']);
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['alice' => $this->makeUser('alice')]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);

		if ($lastReminderDaysAgo !== null) {
			$lastReminderDate = (new \DateTime('now', $utc))
				->modify("-{$lastReminderDaysAgo} days")
				->setTime(15, 30, 0) // simulate cron ran at different time
				->format('Y-m-d H:i:s');
			$reminderLog = $this->makeReminderLog(1, 'alice', $lastReminderDate);
			$this->reminderLogMapper->method('findByAppointment')->willReturn([$reminderLog]);
		} else {
			$this->reminderLogMapper->method('findByAppointment')->willReturn([]);
		}

		if ($expectNotification) {
			$this->notificationManager->expects($this->once())->method('notify');
		} else {
			$this->notificationManager->expects($this->never())->method('notify');
		}

		$this->runJob();
	}

	/**
	 * Data provider for the "4 days before, every 2 days" scenario.
	 *
	 * @return array<string, array{int, ?int, bool}>
	 */
	public static function fourDaysTwoDayFrequencyProvider(): array {
		return [
			'Day -4: first reminder, no prior' => [4, null, true],
			'Day -3: 1 day since last, skip' => [3, 1, false],
			'Day -2: 2 days since last, send' => [2, 2, true],
			'Day -1: 1 day since last, skip' => [1, 1, false],
			'Day 0 (today): 2 days since last, send' => [0, 2, true],
			'Day -4: same day re-run, skip' => [4, 0, false],
			'Day -2: 3 days since last (delayed cron), send' => [2, 3, true],
		];
	}

	// =========================================================================
	// Multiple appointments in same run
	// =========================================================================

	public function testMultipleAppointmentsProcessedIndependently(): void {
		$this->configureReminders(true, 7, 0);
		$this->mockNotifications();

		$utc = new \DateTimeZone('UTC');
		$date1 = (new \DateTime('now', $utc))->modify('+2 days')->format('Y-m-d H:i:s');
		$date2 = (new \DateTime('now', $utc))->modify('+5 days')->format('Y-m-d H:i:s');
		$appointment1 = $this->makeAppointment(1, 'Meeting A', $date1);
		$appointment2 = $this->makeAppointment(2, 'Meeting B', $date2);

		$this->appointmentMapper->method('findStartingBetween')
			->willReturn([$appointment1, $appointment2]);

		// alice has no response and no prior reminder for either
		$this->responseMapper->method('findByAppointment')->willReturn([]);
		$this->reminderLogMapper->method('findByAppointment')->willReturn([]);
		$this->configService->method('getWhitelistedGroups')->willReturn(['group1']);
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn(['alice' => $this->makeUser('alice')]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);

		// Should send one notification per appointment
		$this->notificationManager->expects($this->exactly(2))->method('notify');
		$this->reminderLogMapper->expects($this->exactly(2))->method('insert');

		$this->runJob();
	}

	// =========================================================================
	// Notification failure should not crash the job
	// =========================================================================

	public function testNotificationFailureDoesNotStopOtherUsers(): void {
		$this->configureReminders(true, 7, 0);

		$utc = new \DateTimeZone('UTC');
		$appointmentDate = (new \DateTime('now', $utc))->modify('+3 days')->format('Y-m-d H:i:s');
		$appointment = $this->makeAppointment(1, 'Test', $appointmentDate);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$notification->method('setLink')->willReturnSelf();
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.com');

		// First notify call throws, second succeeds
		$this->notificationManager->expects($this->exactly(2))
			->method('notify')
			->willReturnOnConsecutiveCalls(
				$this->throwException(new \Exception('Notification service down')),
				null,
			);

		$this->appointmentMapper->method('findStartingBetween')->willReturn([$appointment]);
		$this->responseMapper->method('findByAppointment')->willReturn([]);
		$this->reminderLogMapper->method('findByAppointment')->willReturn([]);
		$this->configService->method('getWhitelistedGroups')->willReturn(['group1']);
		$this->visibilityService->method('getRelevantUsersForAppointment')
			->willReturn([
				'alice' => $this->makeUser('alice'),
				'bob' => $this->makeUser('bob'),
			]);
		$this->visibilityService->method('hasRestrictedVisibility')->willReturn(false);

		// Only bob's reminder should be logged (alice's notification failed)
		$this->reminderLogMapper->expects($this->once())->method('insert');

		$this->runJob();
	}
}
