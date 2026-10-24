<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Listener;

use OCA\Attendance\Listener\MembershipRemovedListener;
use OCA\Attendance\Service\AppointmentService;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MembershipRemovedListenerTest extends TestCase {
	private AppointmentService|MockObject $appointmentService;
	private LoggerInterface|MockObject $logger;
	private MembershipRemovedListener $listener;

	protected function setUp(): void {
		$this->appointmentService = $this->createMock(AppointmentService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new MembershipRemovedListener($this->appointmentService, $this->logger);
	}

	private function userRemovedEvent(string $userId): UserRemovedEvent {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$event = $this->createMock(UserRemovedEvent::class);
		$event->method('getUser')->willReturn($user);

		return $event;
	}

	public function testALeaverHasTheirAnswersWithdrawn(): void {
		$this->appointmentService->expects($this->once())
			->method('withdrawFromAppointmentsLeft')
			->with('leaver');

		$this->listener->handle($this->userRemovedEvent('leaver'));
	}

	public function testIgnoresEventsOfAnyOtherType(): void {
		$this->appointmentService->expects($this->never())->method('withdrawFromAppointmentsLeft');

		$this->listener->handle($this->createMock(UserDeletedEvent::class));
	}

	public function testLogsRatherThanThrowsWhenTheServiceFails(): void {
		$this->appointmentService->method('withdrawFromAppointmentsLeft')
			->willThrowException(new \RuntimeException('boom'));

		$this->logger->expects($this->once())->method('error');

		$this->listener->handle($this->userRemovedEvent('leaver'));
	}
}
