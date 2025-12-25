<?php

declare(strict_types=1);

namespace OCA\Attendance\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<IcalToken>
 */
class IcalTokenMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'att_ical_tokens', IcalToken::class);
	}

	/**
	 * Find token by user ID
	 *
	 * @param string $userId
	 * @return IcalToken|null
	 */
	public function findByUserId(string $userId): ?IcalToken {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Find token by token string
	 *
	 * @param string $token
	 * @return IcalToken|null
	 */
	public function findByToken(string $token): ?IcalToken {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Delete token for user (used before regenerating)
	 *
	 * @param string $userId
	 * @return int Number of deleted rows
	 */
	public function deleteByUserId(string $userId): int {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $qb->executeStatement();
	}
}
