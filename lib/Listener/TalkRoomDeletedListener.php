<?php

declare(strict_types=1);

namespace OCA\Attendance\Listener;

use OCA\Attendance\Service\TalkRoomService;
use OCA\Talk\Events\RoomDeletedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Forgets a linked Talk room the moment it is deleted directly in Talk,
 * instead of waiting on TalkRoomSyncJob's hourly safety net to notice the
 * token is stale.
 *
 * @template-implements IEventListener<Event>
 */
final class TalkRoomDeletedListener implements IEventListener {
	public function __construct(
		private TalkRoomService $talkRoomService,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof RoomDeletedEvent)) {
			return;
		}

		try {
			$this->talkRoomService->handleRoomDeleted($event->getRoom()->getToken());
		} catch (\Throwable $e) {
			$this->logger->error('Handling a deleted Talk room failed', [
				'app' => 'attendance',
				'exception' => $e,
			]);
		}
	}
}
