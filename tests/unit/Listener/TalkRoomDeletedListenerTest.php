<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Listener;

use OCA\Attendance\Listener\TalkRoomDeletedListener;
use OCA\Attendance\Service\TalkRoomService;
use OCA\Talk\Events\RoomDeletedEvent;
use OCA\Talk\Room;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TalkRoomDeletedListenerTest extends TestCase {
	private TalkRoomService|MockObject $talkRoomService;
	private LoggerInterface|MockObject $logger;
	private TalkRoomDeletedListener $listener;

	protected function setUp(): void {
		$this->talkRoomService = $this->createMock(TalkRoomService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new TalkRoomDeletedListener($this->talkRoomService, $this->logger);
	}

	private function roomDeletedEvent(string $token): RoomDeletedEvent {
		$room = $this->createMock(Room::class);
		$room->method('getToken')->willReturn($token);

		$event = $this->createMock(RoomDeletedEvent::class);
		$event->method('getRoom')->willReturn($room);

		return $event;
	}

	public function testForwardsTheTokenToTheService(): void {
		$this->talkRoomService->expects($this->once())
			->method('handleRoomDeleted')
			->with('tok123');

		$this->listener->handle($this->roomDeletedEvent('tok123'));
	}

	public function testIgnoresEventsOfAnyOtherType(): void {
		$this->talkRoomService->expects($this->never())->method('handleRoomDeleted');

		$this->listener->handle($this->createMock(UserDeletedEvent::class));
	}

	public function testLogsRatherThanThrowsWhenTheServiceFails(): void {
		$this->talkRoomService->method('handleRoomDeleted')
			->willThrowException(new \RuntimeException('boom'));

		$this->logger->expects($this->once())->method('error');

		$this->listener->handle($this->roomDeletedEvent('tok123'));
	}
}
