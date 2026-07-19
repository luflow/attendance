<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Notification;

use OCA\Attendance\Db\AttendanceResponse;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Notification\Notifier;
use OCA\Attendance\Service\QuickResponseTokenService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
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

	private Notifier $notifier;

	protected function setUp(): void {
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->tokenService = $this->createMock(QuickResponseTokenService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->responseMapper = $this->createMock(AttendanceResponseMapper::class);

		$this->config->method('getUserValue')->willReturn('');

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params),
		);
		$this->l10nFactory->method('get')->willReturn($l);

		$this->notifier = new Notifier(
			$this->l10nFactory,
			$this->urlGenerator,
			$this->tokenService,
			$this->config,
			$this->responseMapper,
		);
	}

	private function mockReminderNotification(array $subjectParameters): INotification|MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('attendance');
		$notification->method('getSubject')->willReturn('appointment_reminder');
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
}
