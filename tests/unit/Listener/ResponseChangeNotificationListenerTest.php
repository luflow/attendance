<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Listener;

use OCA\Attendance\Audit\Verb;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AuditEvent;
use OCA\Attendance\Listener\ResponseChangeNotificationListener;
use OCA\Attendance\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResponseChangeNotificationListenerTest extends TestCase {
	/** @var AppointmentMapper|MockObject */
	private $appointmentMapper;

	private ResponseChangeNotificationListener $listener;

	protected function setUp(): void {
		$this->appointmentMapper = $this->createMock(AppointmentMapper::class);

		$this->listener = new ResponseChangeNotificationListener(
			$this->createMock(PermissionService::class),
			$this->createMock(IConfig::class),
			$this->appointmentMapper,
			$this->createMock(INotificationManager::class),
			$this->createMock(IURLGenerator::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function submitted(string $source): AuditEvent {
		$event = new AuditEvent();
		$event->setAppointmentId(5);
		$event->setVerb(Verb::RESPONSE_SUBMITTED);
		$event->setActorId('alice');
		$event->setSubjectId('alice');
		$event->setSource($source);
		return $event;
	}

	public function testAnAnswerSetBecauseOfVacationNotifiesNobody(): void {
		// Not even the appointment is looked up: nobody did anything to report.
		$this->appointmentMapper->expects($this->never())->method('find');

		$this->listener->handle($this->submitted(Verb::SOURCE_VACATION));
	}

	public function testAnAnswerFromTheAppIsStillHandled(): void {
		$this->appointmentMapper->expects($this->once())->method('find')->with(5)
			->willThrowException(new DoesNotExistException('gone'));

		$this->listener->handle($this->submitted(Verb::SOURCE_WEB));
	}
}
