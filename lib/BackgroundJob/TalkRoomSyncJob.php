<?php

declare(strict_types=1);

namespace OCA\Attendance\BackgroundJob;

use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Service\TalkRoomService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Safety net behind the immediate participant sync.
 *
 * Membership of a conversation is a confidentiality promise, so it must not
 * depend on a single write succeeding: a Talk hiccup while someone was
 * unscheduled would otherwise leave them reading the final details forever.
 * This walks every linked conversation and reconciles it again.
 *
 * It also clears tokens whose room is gone — Talk deletes event rooms 28 days
 * after they end, so that is the normal end of life rather than an error.
 */
final class TalkRoomSyncJob extends TimedJob {
	/** @var int Interval in seconds (1 hour) */
	private const INTERVAL = 3600;

	public function __construct(
		ITimeFactory $time,
		private AppointmentMapper $appointmentMapper,
		private TalkRoomService $talkRoomService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(self::INTERVAL);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		if (!$this->talkRoomService->isAvailable()) {
			return;
		}

		foreach ($this->appointmentMapper->findWithTalkRoom() as $appointment) {
			// TalkRoomService already swallows and logs its own failures; this
			// guards against anything thrown around it.
			try {
				$this->talkRoomService->syncParticipants($appointment);
			} catch (\Throwable $e) {
				$this->logger->error('Reconciling a Talk conversation failed', [
					'app' => 'attendance',
					'appointmentId' => $appointment->getId(),
					'exception' => $e,
				]);
			}
		}
	}
}
