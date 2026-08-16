<?php

declare(strict_types=1);

namespace OCA\Attendance\BackgroundJob;

use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Service\TalkRoomService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Safety net behind the immediate participant sync: membership of a room is a
 * confidentiality promise, so it must not hinge on a single write succeeding.
 */
final class TalkRoomSyncJob extends TimedJob {
	/** @var int Interval in seconds (1 hour) */
	private const INTERVAL = 3600;

	/** @var int How far past its end an appointment still gets reconciled */
	private const GRACE_SECONDS = 7 * 24 * 3600;

	public function __construct(
		ITimeFactory $time,
		private AppointmentMapper $appointmentMapper,
		private TalkRoomService $talkRoomService,
	) {
		parent::__construct($time);

		$this->setInterval(self::INTERVAL);
	}

	#[\Override]
	protected function run(mixed $argument): void {
		if (!$this->talkRoomService->isAvailable()) {
			return;
		}

		// syncParticipants() swallows and logs its own failures, so one bad
		// appointment cannot strand the rest.
		$endedAfter = gmdate('Y-m-d H:i:s', $this->time->getTime() - self::GRACE_SECONDS);
		foreach ($this->appointmentMapper->findWithTalkRoom($endedAfter) as $appointment) {
			$this->talkRoomService->syncParticipants($appointment);
		}
	}
}
