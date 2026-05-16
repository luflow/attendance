<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Audit\AuditEventDispatcher;
use OCA\Attendance\Audit\Verb;
use OCA\Attendance\Db\AuditEvent;
use OCA\Attendance\Db\AuditEventMapper;
use OCA\Attendance\Service\AuditEventService;
use OCA\Attendance\Service\ConfigService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AuditEventServiceTest extends TestCase {
	private AuditEventMapper|MockObject $mapper;
	private AuditEventDispatcher|MockObject $dispatcher;
	private ConfigService|MockObject $configService;
	private IUserSession|MockObject $userSession;
	private LoggerInterface|MockObject $logger;
	private AuditEventService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(AuditEventMapper::class);
		$this->dispatcher = $this->createMock(AuditEventDispatcher::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new AuditEventService(
			$this->mapper,
			$this->dispatcher,
			$this->configService,
			$this->userSession,
			$this->logger,
		);

		$this->configService->method('isAuditLogEnabled')->willReturn(true);
		$this->mapper->method('insert')->willReturnArgument(0);
	}

	public function testRecordDefaultsActorToSessionUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$captured = null;
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (AuditEvent $event) use (&$captured) {
				$captured = $event;
				return $event;
			});

		$this->service->recordAppointmentLifecycle(Verb::APPOINTMENT_CREATED, 42, Verb::SOURCE_APP);

		$this->assertSame('alice', $captured->getActorId());
		$this->assertSame(Verb::APPOINTMENT_CREATED, $captured->getVerb());
		$this->assertSame(Verb::SOURCE_APP, $captured->getSource());
	}

	public function testRecordFallsBackToNullActorWhenNoSession(): void {
		// Background-job context: IUserSession::getUser() returns null per
		// the OCP contract. The audit row must persist that as actor=null so
		// the SOURCE marker is what tells the reader "no user did this".
		$this->userSession->method('getUser')->willReturn(null);

		$captured = null;
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (AuditEvent $event) use (&$captured) {
				$captured = $event;
				return $event;
			});

		$this->service->recordAppointmentLifecycle(Verb::APPOINTMENT_CLOSED, 7, Verb::SOURCE_AUTO_CLOSE);

		$this->assertNull($captured->getActorId());
		$this->assertSame(Verb::SOURCE_AUTO_CLOSE, $captured->getSource());
	}

	public function testRecordHonoursExplicitActorOverride(): void {
		// Session lookup must not happen at all when the caller provides an
		// actor — otherwise we'd silently override the explicit value.
		$this->userSession->expects($this->never())->method('getUser');

		$captured = null;
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (AuditEvent $event) use (&$captured) {
				$captured = $event;
				return $event;
			});

		$this->service->record(Verb::RESPONSE_SUBMITTED, 1, 'bob', 'bob', ['response' => 'yes'], Verb::SOURCE_QUICK_LINK);

		$this->assertSame('bob', $captured->getActorId());
	}

	public function testRecordSkipsWhenAuditDisabled(): void {
		// Kill switch: when disabled the service must not touch the DB or the
		// session, regardless of caller intent.
		$config = $this->createMock(ConfigService::class);
		$config->method('isAuditLogEnabled')->willReturn(false);
		$service = new AuditEventService(
			$this->mapper,
			$this->dispatcher,
			$config,
			$this->userSession,
			$this->logger,
		);

		$this->mapper->expects($this->never())->method('insert');
		$this->userSession->expects($this->never())->method('getUser');

		$this->assertNull($service->recordAppointmentLifecycle(Verb::APPOINTMENT_CREATED, 1, Verb::SOURCE_APP));
	}
}
