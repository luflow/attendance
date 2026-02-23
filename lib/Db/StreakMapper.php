<?php

declare(strict_types=1);

namespace OCA\Attendance\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Streak>
 */
class StreakMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'att_streaks', Streak::class);
	}

	/**
	 * @param string $userId
	 * @return Streak
	 * @throws DoesNotExistException
	 */
	public function findByUser(string $userId): Streak {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId))
			);

		return $this->findEntity($qb);
	}

	/**
	 * Find or create a streak record for a user.
	 *
	 * @param string $userId
	 * @return Streak
	 */
	public function findOrCreateByUser(string $userId): Streak {
		try {
			return $this->findByUser($userId);
		} catch (DoesNotExistException $e) {
			$streak = new Streak();
			$streak->setUserId($userId);
			$streak->setCurrentStreak(0);
			$streak->setLongestStreak(0);
			return $this->insert($streak);
		}
	}

	/**
	 * Get top streaks ordered by current_streak descending.
	 *
	 * @param int $limit
	 * @return array<Streak>
	 */
	public function findTopStreaks(int $limit = 10): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->gt('current_streak', $qb->createNamedParameter(0))
			)
			->orderBy('current_streak', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Get all unique user IDs from the responses table.
	 *
	 * @return array<string>
	 */
	public function getAllResponseUserIds(): array {
		$qb = $this->db->getQueryBuilder();

		$qb->selectDistinct('user_id')
			->from('att_responses');

		$result = $qb->executeQuery();
		$userIds = [];
		while ($row = $result->fetch()) {
			$userIds[] = $row['user_id'];
		}
		$result->closeCursor();

		return $userIds;
	}
}
