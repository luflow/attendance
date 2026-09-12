<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Service\NotificationService;
use OCP\Activity\ActivitySettings;
use OCP\Activity\IEvent;
use OCP\Activity\IManager as IActivityManager;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NotificationServiceTest extends TestCase {
	/** @var INotificationManager|MockObject */
	private $notificationManager;
	/** @var IActivityManager|MockObject */
	private $activityManager;
	/** @var IAppManager|MockObject */
	private $appManager;
	/** @var IURLGenerator|MockObject */
	private $urlGenerator;
	/** @var IDBConnection|MockObject */
	private $db;
	/** @var LoggerInterface|MockObject */
	private $logger;

	private NotificationService $service;

	protected function setUp(): void {
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->activityManager = $this->createMock(IActivityManager::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.test/appointment');

		$this->service = new NotificationService(
			$this->notificationManager,
			$this->activityManager,
			$this->appManager,
			$this->urlGenerator,
			$this->db,
			$this->logger,
		);
	}

	private function setAppsEnabled(bool $notifications, bool $activity): void {
		$this->appManager->method('isEnabledForUser')->willReturnCallback(
			static fn (string $appId): bool => match ($appId) {
				'notifications' => $notifications,
				'activity' => $activity,
				default => false,
			}
		);
	}

	private function appointment(): Appointment {
		$appointment = new Appointment();
		$appointment->setId(5);
		$appointment->setName('Rehearsal');
		$appointment->setStartDatetime('2030-08-01 18:00:00');
		return $appointment;
	}

	private function mockNotification(): INotification|MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$notification->method('setLink')->willReturnSelf();
		return $notification;
	}

	private function mockEvent(): IEvent|MockObject {
		$event = $this->createMock(IEvent::class);
		$event->method('setApp')->willReturnSelf();
		$event->method('setType')->willReturnSelf();
		$event->method('setSubject')->willReturnSelf();
		$event->method('setObject')->willReturnSelf();
		$event->method('setLink')->willReturnSelf();
		$event->method('setTimestamp')->willReturnSelf();
		return $event;
	}

	public function testCancellationFallsBackToDirectNotificationWhenActivityAppDisabled(): void {
		$this->setAppsEnabled(notifications: true, activity: false);
		$this->notificationManager->method('createNotification')->willReturn($this->mockNotification());

		$this->notificationManager->expects($this->once())->method('notify');
		$this->activityManager->expects($this->never())->method('bulkPublish');

		$this->service->sendCancellationNotifications($this->appointment(), ['alice']);
	}

	public function testCancellationPublishesActivityEventWhenActivityAppEnabled(): void {
		$this->setAppsEnabled(notifications: true, activity: true);
		$event = $this->mockEvent();
		$this->activityManager->method('generateEvent')->willReturn($event);
		$this->activityManager->method('getSettingById')->with('appointment_cancelled')
			->willReturn($this->createMock(ActivitySettings::class));

		$this->activityManager->expects($this->once())->method('bulkPublish')
			->with($event, ['alice', 'bob'], $this->anything());
		$this->notificationManager->expects($this->never())->method('notify');

		$this->service->sendCancellationNotifications($this->appointment(), ['alice', 'bob']);
	}

	public function testCancellationFallsBackWhenPublishingThrows(): void {
		$this->setAppsEnabled(notifications: true, activity: true);
		$this->activityManager->method('generateEvent')->willReturn($this->mockEvent());
		$this->activityManager->method('getSettingById')->willThrowException(new \RuntimeException('boom'));
		$this->notificationManager->method('createNotification')->willReturn($this->mockNotification());

		$this->notificationManager->expects($this->once())->method('notify');

		$this->service->sendCancellationNotifications($this->appointment(), ['alice']);
	}

	public function testReactivationPublishesActivityEventWhenActivityAppEnabled(): void {
		$this->setAppsEnabled(notifications: true, activity: true);
		$event = $this->mockEvent();
		$this->activityManager->method('generateEvent')->willReturn($event);
		$this->activityManager->method('getSettingById')->with('appointment_reactivated')
			->willReturn($this->createMock(ActivitySettings::class));

		$this->activityManager->expects($this->once())->method('bulkPublish');
		$this->notificationManager->expects($this->never())->method('notify');

		$this->service->sendReactivationNotifications($this->appointment(), ['alice']);
	}

	public function testBookingNotificationFallsBackToDirectNotificationWhenActivityAppDisabled(): void {
		$this->setAppsEnabled(notifications: true, activity: false);
		$this->notificationManager->method('createNotification')->willReturn($this->mockNotification());

		$this->notificationManager->expects($this->once())->method('notify');
		$this->activityManager->expects($this->never())->method('bulkPublish');

		$this->service->sendBookingNotification($this->appointment(), 'alice', 'booked');
	}

	public function testBookingNotificationPublishesActivityEventWhenActivityAppEnabled(): void {
		$this->setAppsEnabled(notifications: true, activity: true);
		$event = $this->mockEvent();
		$this->activityManager->method('generateEvent')->willReturn($event);
		$this->activityManager->method('getSettingById')->with('booking_declined')
			->willReturn($this->createMock(ActivitySettings::class));

		$this->activityManager->expects($this->once())->method('bulkPublish')
			->with($event, ['alice'], $this->anything());
		$this->notificationManager->expects($this->never())->method('notify');

		$this->service->sendBookingNotification($this->appointment(), 'alice', 'declined');
	}

	public function testSeriesUpdateFallsBackToDirectNotificationWhenActivityAppDisabled(): void {
		$this->setAppsEnabled(notifications: true, activity: false);
		$this->notificationManager->method('createNotification')->willReturn($this->mockNotification());

		$this->notificationManager->expects($this->once())->method('notify');
		$this->activityManager->expects($this->never())->method('bulkPublish');

		$this->service->sendSeriesUpdateNotifications(3, 'Rehearsal', ['time'], ['alice']);
	}

	public function testSeriesUpdatePublishesActivityEventWhenActivityAppEnabled(): void {
		$this->setAppsEnabled(notifications: true, activity: true);
		$event = $this->mockEvent();
		$this->activityManager->method('generateEvent')->willReturn($event);
		$this->activityManager->method('getSettingById')->with('appointments_series_updated')
			->willReturn($this->createMock(ActivitySettings::class));

		$this->activityManager->expects($this->once())->method('bulkPublish');
		$this->notificationManager->expects($this->never())->method('notify');

		$this->service->sendSeriesUpdateNotifications(3, 'Rehearsal', ['time'], ['alice']);
	}

	/**
	 * appointment_created carries quick-response actions that only the
	 * app's own Notifier can render — it must never be rerouted through
	 * Activity, regardless of whether that app is enabled.
	 */
	public function testNewAppointmentNeverUsesActivityEvenWhenEnabled(): void {
		$this->setAppsEnabled(notifications: true, activity: true);
		$this->notificationManager->method('createNotification')->willReturn($this->mockNotification());

		$this->activityManager->expects($this->never())->method('generateEvent');
		$this->activityManager->expects($this->never())->method('bulkPublish');
		$this->notificationManager->expects($this->once())->method('notify');

		$this->service->sendNewAppointmentNotifications($this->appointment(), ['alice']);
	}

	/**
	 * Same guarantee as above for the other three interactive subjects.
	 */
	public function testBulkAppointmentCreatedNeverUsesActivityEvenWhenEnabled(): void {
		$this->setAppsEnabled(notifications: true, activity: true);
		$this->notificationManager->method('createNotification')->willReturn($this->mockNotification());

		$this->activityManager->expects($this->never())->method('generateEvent');
		$this->notificationManager->expects($this->once())->method('notify');

		$this->service->sendBulkAppointmentNotifications(2, 'Alice', ['alice']);
	}
}
