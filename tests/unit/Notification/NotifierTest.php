<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Notification;

use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Notification\Notifier;
use OCA\Attendance\Service\QuickResponseTokenService;
use OCA\Attendance\Service\ResponsePolicyService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NotifierTest extends TestCase {
	/** @var IFactory|MockObject */
	private $l10nFactory;
	/** @var IURLGenerator|MockObject */
	private $urlGenerator;
	/** @var QuickResponseTokenService|MockObject */
	private $tokenService;
	/** @var IConfig|MockObject */
	private $config;
	/** @var AttendanceResponseMapper|MockObject */
	private $responseMapper;

	/** @var AppointmentMapper|MockObject */
	private $appointmentMapper;

	/** @var ResponsePolicyService|MockObject */
	private $responsePolicyService;
	/** @var IUserManager|MockObject */
	private $userManager;

	private Notifier $notifier;

	protected function setUp(): void {
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->tokenService = $this->createMock(QuickResponseTokenService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);
		$this->responsePolicyService = $this->createMock(ResponsePolicyService::class);
		// Notifications carry all three quick-response buttons unless a test
		// takes "Maybe" away.
		$this->responsePolicyService->method('isMaybeAllowed')->willReturn(true);

		$this->config->method('getUserValue')->willReturn('');

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params),
		);
		$l->method('n')->willReturnCallback(
			static fn (string $one, string $many, int $count, array $params = []): string
				=> vsprintf($count === 1 ? $one : $many, $params),
		);
		$this->l10nFactory->method('get')->willReturn($l);

		$this->notifier = new Notifier(
			$this->l10nFactory,
			$this->urlGenerator,
			$this->tokenService,
			$this->config,
			$this->responseMapper,
			$this->userManager,
			$this->appointmentMapper,
			$this->responsePolicyService,
		);
	}

	/**
	 * @param array<string, string> $displayNames uid => display name; unknown uids resolve to null
	 */
	private function mockUsers(array $displayNames): void {
		$this->userManager->method('get')->willReturnCallback(
			function (string $uid) use ($displayNames): ?IUser {
				if (!isset($displayNames[$uid])) {
					return null;
				}
				$user = $this->createMock(IUser::class);
				$user->method('getDisplayName')->willReturn($displayNames[$uid]);
				return $user;
			},
		);
	}

	private function mockResponseChangeNotification(string $subject, array $subjectParameters): INotification|MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('attendance');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($subjectParameters);
		$notification->method('getUser')->willReturn('manager');
		$notification->method('setIcon')->willReturnSelf();
		return $notification;
	}

	private function mockAppointmentNotification(string $subject, array $subjectParameters): INotification|MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('attendance');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($subjectParameters);
		$notification->method('getUser')->willReturn('alice');
		$notification->method('setParsedSubject')->willReturnSelf();
		$notification->method('setParsedMessage')->willReturnSelf();
		$notification->method('setIcon')->willReturnSelf();
		$notification->method('createAction')->willReturnCallback(function () {
			$action = $this->createMock(IAction::class);
			$action->method('setLabel')->willReturnSelf();
			$action->method('setParsedLabel')->willReturnSelf();
			$action->method('setLink')->willReturnSelf();
			$action->method('setPrimary')->willReturnSelf();
			return $action;
		});
		return $notification;
	}

	private function mockReminderNotification(array $subjectParameters): INotification|MockObject {
		return $this->mockAppointmentNotification('appointment_reminder', $subjectParameters);
	}

	public function testReminderIsDismissedWhenUserAlreadyResponded(): void {
		$response = new AttendanceResponse();
		$response->setResponse('yes');
		$this->responseMapper->method('findByAppointmentAndUser')
			->with(42, 'alice')
			->willReturn($response);

		$notification = $this->mockReminderNotification([
			'appointmentId' => 42,
			'name' => 'Rehearsal',
			'startDatetime' => '2026-08-01 18:00:00',
		]);

		$this->expectException(AlreadyProcessedException::class);
		$this->notifier->prepare($notification, 'de');
	}

	public function testTestReminderBypassesAlreadyRespondedCheck(): void {
		$this->responseMapper->expects($this->never())
			->method('findByAppointmentAndUser');

		$notification = $this->mockReminderNotification([
			'appointmentId' => 42,
			'name' => 'Rehearsal',
			'startDatetime' => '2026-08-01 18:00:00',
			'test' => true,
		]);

		$result = $this->notifier->prepare($notification, 'de');
		$this->assertSame($notification, $result);
	}

	public function testReminderIsKeptForMaybeResponders(): void {
		$response = new AttendanceResponse();
		$response->setResponse('maybe');
		$this->responseMapper->method('findByAppointmentAndUser')
			->with(42, 'alice')
			->willReturn($response);

		$notification = $this->mockReminderNotification([
			'appointmentId' => 42,
			'name' => 'Rehearsal',
			'startDatetime' => '2026-08-01 18:00:00',
		]);

		$result = $this->notifier->prepare($notification, 'de');
		$this->assertSame($notification, $result);
	}

	public function testUpdateNotificationNamesBothChangedAspects(): void {
		$notification = $this->mockAppointmentNotification('appointment_updated', [
			'appointmentId' => 42,
			'name' => 'Rehearsal',
			'startDatetime' => '2030-08-01 18:00:00',
			'changed' => ['time', 'location'],
		]);
		$notification->expects($this->once())
			->method('setParsedMessage')
			->with('Date and location have changed. Please check whether your response still fits.')
			->willReturnSelf();

		$this->notifier->prepare($notification, 'de');
	}

	public function testUpdateNotificationNamesTheSingleChangedAspect(): void {
		$notification = $this->mockAppointmentNotification('appointment_updated', [
			'appointmentId' => 42,
			'name' => 'Rehearsal',
			'startDatetime' => '2030-08-01 18:00:00',
			'changed' => ['location'],
		]);
		$notification->expects($this->once())
			->method('setParsedMessage')
			->with('The location has changed.')
			->willReturnSelf();

		$this->notifier->prepare($notification, 'de');
	}

	/**
	 * Notifications queued before a change to the notified field set still have
	 * to render into something.
	 */
	public function testUpdateNotificationFallsBackForUnknownChanges(): void {
		$notification = $this->mockAppointmentNotification('appointment_updated', [
			'appointmentId' => 42,
			'name' => 'Rehearsal',
			'startDatetime' => '2030-08-01 18:00:00',
			'changed' => ['deadline'],
		]);
		$notification->expects($this->once())
			->method('setParsedMessage')
			->with('Please check the appointment.')
			->willReturnSelf();

		$this->notifier->prepare($notification, 'de');
	}

	public function testUpdateNotificationReachesUsersWhoAlreadyResponded(): void {
		$this->responseMapper->expects($this->never())
			->method('findByAppointmentAndUser');

		$notification = $this->mockAppointmentNotification('appointment_updated', [
			'appointmentId' => 42,
			'name' => 'Rehearsal',
			'startDatetime' => '2030-08-01 18:00:00',
			'changed' => ['time'],
		]);

		$result = $this->notifier->prepare($notification, 'de');
		$this->assertSame($notification, $result);
	}

	public function testSeriesUpdateNotificationCountsAndDescribes(): void {
		$notification = $this->mockAppointmentNotification('appointments_series_updated', [
			'count' => 12,
			'name' => 'Rehearsal',
			'changed' => ['time'],
		]);
		$notification->expects($this->once())
			->method('setParsedSubject')
			->with('12 appointments changed in "Rehearsal"')
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setParsedMessage')
			->with('The date has changed. Please check whether your response still fits.')
			->willReturnSelf();

		$this->notifier->prepare($notification, 'de');
	}

	public function testReactivationNotificationIsRendered(): void {
		$notification = $this->mockAppointmentNotification('appointment_reactivated', [
			'appointmentId' => 42,
			'name' => 'Rehearsal',
			'startDatetime' => '2030-08-01 18:00:00',
		]);
		$notification->expects($this->once())
			->method('setParsedMessage')
			->with('We have withdrawn the cancellation. If you have made other plans since, please update your response.')
			->willReturnSelf();

		$this->notifier->prepare($notification, 'de');
	}

	public function testResponseNotificationUsesDisplayNames(): void {
		$this->mockUsers(['alice' => 'Alice Anderson']);

		$notification = $this->mockResponseChangeNotification('response_submitted', [
			'appointmentId' => 42,
			'appointmentName' => 'Rehearsal',
			'actor' => 'alice',
			'subject' => 'alice',
			'from' => '',
			'to' => 'yes',
		]);
		$notification->expects($this->once())
			->method('setParsedSubject')
			->with('Alice Anderson answered Yes on "Rehearsal"')
			->willReturnSelf();

		$this->notifier->prepare($notification, 'de');
	}

	public function testResponseNotificationFallsBackToUserIdForUnknownAccounts(): void {
		$this->mockUsers(['alice' => 'Alice Anderson']);

		$notification = $this->mockResponseChangeNotification('response_changed', [
			'appointmentId' => 42,
			'appointmentName' => 'Rehearsal',
			'actor' => 'alice',
			'subject' => 'ghost',
			'from' => 'yes',
			'to' => 'no',
		]);
		$notification->expects($this->once())
			->method('setParsedSubject')
			->with('Alice Anderson changed the response of ghost from Yes to No on "Rehearsal"')
			->willReturnSelf();

		$this->notifier->prepare($notification, 'de');
	}
}
