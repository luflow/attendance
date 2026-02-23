<?php

declare(strict_types=1);

namespace OCA\Attendance\Command;

use OCA\Attendance\Db\StreakMapper;
use OCA\Attendance\Service\StreakService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RecalculateStreaksCommand extends Command {
	private StreakService $streakService;
	private StreakMapper $streakMapper;

	public function __construct(
		StreakService $streakService,
		StreakMapper $streakMapper,
	) {
		parent::__construct();
		$this->streakService = $streakService;
		$this->streakMapper = $streakMapper;
	}

	protected function configure(): void {
		$this
			->setName('attendance:recalculate-streaks')
			->setDescription('Recalculate attendance streaks for all users');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userIds = $this->streakMapper->getAllResponseUserIds();

		if (empty($userIds)) {
			$output->writeln('No users with responses found.');
			return 0;
		}

		$output->writeln(sprintf('Recalculating streaks for %1$d users …', count($userIds)));

		$count = 0;
		foreach ($userIds as $userId) {
			try {
				$this->streakService->recalculateStreak($userId);
				$count++;
			} catch (\Throwable $e) {
				$output->writeln(sprintf('  Error for user %1$s: %2$s', $userId, $e->getMessage()));
			}
		}

		$output->writeln(sprintf('Recalculated streaks for %1$d users.', $count));
		return 0;
	}
}
