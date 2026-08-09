<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

/**
 * The selected range holds more appointments than the evaluation will process.
 * Carries both numbers so the UI can say what to narrow down to.
 */
class StatisticsRangeException extends \RuntimeException {
	public function __construct(
		private int $found,
		private int $limit,
	) {
		parent::__construct(sprintf('Range holds %d appointments, limit is %d', $found, $limit));
	}

	public function getFound(): int {
		return $this->found;
	}

	public function getLimit(): int {
		return $this->limit;
	}
}
