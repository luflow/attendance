<?php

declare(strict_types=1);

namespace OCA\Attendance\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getCurrentStreak()
 * @method void setCurrentStreak(int $currentStreak)
 * @method int getLongestStreak()
 * @method void setLongestStreak(int $longestStreak)
 * @method string|null getStreakStartDate()
 * @method void setStreakStartDate(?string $streakStartDate)
 * @method string|null getLongestStreakDate()
 * @method void setLongestStreakDate(?string $longestStreakDate)
 * @method string|null getLastCalculatedAt()
 * @method void setLastCalculatedAt(?string $lastCalculatedAt)
 */
class Streak extends Entity implements JsonSerializable {
	protected $userId = '';
	protected $currentStreak = 0;
	protected $longestStreak = 0;
	protected $streakStartDate = null;
	protected $longestStreakDate = null;
	protected $lastCalculatedAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('userId', 'string');
		$this->addType('currentStreak', 'integer');
		$this->addType('longestStreak', 'integer');
		$this->addType('streakStartDate', 'string');
		$this->addType('longestStreakDate', 'string');
		$this->addType('lastCalculatedAt', 'string');
	}

	/**
	 * Get streak level based on current streak count.
	 *
	 * @return string 'none'|'starting'|'consistent'|'on_fire'|'unstoppable'
	 */
	public function getStreakLevel(): string {
		$streak = $this->getCurrentStreak();
		if ($streak <= 0) {
			return 'none';
		}
		if ($streak <= 4) {
			return 'starting';
		}
		if ($streak <= 9) {
			return 'consistent';
		}
		if ($streak <= 24) {
			return 'on_fire';
		}
		return 'unstoppable';
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'currentStreak' => $this->getCurrentStreak(),
			'longestStreak' => $this->getLongestStreak(),
			'streakStartDate' => $this->getStreakStartDate(),
			'longestStreakDate' => $this->getLongestStreakDate(),
			'lastCalculatedAt' => $this->getLastCalculatedAt(),
			'streakLevel' => $this->getStreakLevel(),
		];
	}
}
