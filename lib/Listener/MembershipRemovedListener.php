<?php

declare(strict_types=1);

namespace OCA\Attendance\Listener;

use OCA\Attendance\Service\AppointmentService;
use OCA\Circles\Events\CircleMemberRemovedEvent;
use OCA\Circles\Model\Member;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\UserRemovedEvent;
use Psr\Log\LoggerInterface;

/**
 * Somebody left a group or team: their answers come off the upcoming
 * appointments they are no longer part of (issue #326). Past appointments
 * keep them as history.
 *
 * @template-implements IEventListener<Event>
 */
final class MembershipRemovedListener implements IEventListener {
	public function __construct(
		private AppointmentService $appointmentService,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		$userId = self::leaverOf($event);
		if ($userId === null) {
			return;
		}
		try {
			$withdrawn = $this->appointmentService->withdrawFromAppointmentsLeft($userId);
			if ($withdrawn > 0) {
				$this->logger->info('Withdrew the answers of a user who left a group or team', [
					'userId' => $userId,
					'answers' => $withdrawn,
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Failed to withdraw the answers of a user who left a group or team', [
				'userId' => $userId,
				'error' => $e->getMessage(),
			]);
		}
	}

	private static function leaverOf(Event $event): ?string {
		if ($event instanceof UserRemovedEvent) {
			return $event->getUser()->getUID();
		}
		// instanceof never autoloads, so this is safe without the Circles app.
		if ($event instanceof CircleMemberRemovedEvent && $event->getMember()->getUserType() === Member::TYPE_USER) {
			return $event->getMember()->getUserId();
		}
		return null;
	}
}
