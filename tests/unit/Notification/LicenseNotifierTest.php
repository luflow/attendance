<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Notification;

use OCA\Attendance\Notification\LicenseNotifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LicenseNotifierTest extends TestCase {
	/** @var IURLGenerator|MockObject */
	private $urlGenerator;
	private LicenseNotifier $notifier;

	protected function setUp(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('getAbsoluteURL')->willReturnArgument(0);

		$this->notifier = new LicenseNotifier($l10nFactory, $this->urlGenerator);
	}

	private function notification(string $app, string $subject): INotification&MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getSubject')->willReturn($subject);
		return $notification;
	}

	public function testItIsNotTheAppIdTheMobileAppListensTo(): void {
		// The mobile app shows pushes from "attendance" only; this one must never reach it.
		$this->assertNotSame('attendance', LicenseNotifier::APP);
		$this->assertSame(LicenseNotifier::APP, $this->notifier->getID());
	}

	public function testPurchaseInterestLinksToTheMobileAppsSettings(): void {
		$this->urlGenerator->expects($this->once())
			->method('linkToRouteAbsolute')
			->with('settings.AdminSettings.index', ['section' => 'attendance'])
			->willReturn('https://cloud.example.org/settings/admin/attendance');

		$notification = $this->notification(LicenseNotifier::APP, LicenseNotifier::SUBJECT_PURCHASE_INTEREST);
		$notification->expects($this->once())->method('setParsedSubject')
			->with('A member of your organization would like to use the mobile app');
		$notification->expects($this->once())->method('setParsedMessage')
			->with($this->stringContains('by invoice'));
		$notification->expects($this->once())->method('setLink')
			->with('https://cloud.example.org/settings/admin/attendance#mobile-apps');

		$this->assertSame($notification, $this->notifier->prepare($notification, 'en'));
	}

	public function testOtherNotificationsAreNotItsBusiness(): void {
		$this->expectException(UnknownNotificationException::class);

		$this->notifier->prepare($this->notification('attendance', 'appointment_reminder'), 'en');
	}

	public function testUnknownSubjectIsRejected(): void {
		$this->expectException(UnknownNotificationException::class);

		$this->notifier->prepare($this->notification(LicenseNotifier::APP, 'something_else'), 'en');
	}
}
