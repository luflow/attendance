<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Bind calendar-linked appointments to the numeric ID of their source calendar,
 * so a same-UID event in another user's "personal" calendar no longer matches.
 */
final class Version000028Date20261122120000 extends SimpleMigrationStep {
	private const APP_ID = 'attendance';
	private const ORG_UID_PREFIX = 'attendance-org-';

	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
	) {
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('att_appointments')) {
			return null;
		}

		$table = $schema->getTable('att_appointments');
		$changed = false;
		if (!$table->hasColumn('calendar_id')) {
			$table->addColumn('calendar_id', Types::BIGINT, [
				'notnull' => false,
				'default' => null,
			]);
			$changed = true;
		}
		if (!$table->hasIndex('att_appt_calendar')) {
			$table->addIndex(['calendar_id'], 'att_appt_calendar');
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$this->db->tableExists('calendars')) {
			return;
		}

		$orgUserId = $this->appConfig->getValueString(self::APP_ID, 'org_calendar_user_id');

		$select = $this->db->getQueryBuilder();
		$select->select('id', 'created_by', 'calendar_uri', 'calendar_event_uid')
			->from('att_appointments')
			->where($select->expr()->isNull('calendar_id'))
			->andWhere($select->expr()->isNotNull('calendar_uri'))
			->andWhere($select->expr()->isNotNull('calendar_event_uid'))
			->andWhere($select->expr()->neq('calendar_event_uid', $select->createNamedParameter('')));
		$result = $select->executeQuery();

		$update = $this->db->getQueryBuilder();
		$update->update('att_appointments')
			->set('calendar_id', $update->createParameter('calendarId'))
			->where($update->expr()->eq('id', $update->createParameter('id')))
			->andWhere($update->expr()->isNull('calendar_id'));

		// Pushed events live in the organization calendar's home, imports in the creator's.
		/** @var array<string, int|null> $calendarIds */
		$calendarIds = [];
		$bound = 0;
		foreach ($result->fetchAll() as $row) {
			if (!is_array($row)) {
				continue;
			}
			$id = self::intOrNull($row['id'] ?? null);
			$createdBy = $row['created_by'] ?? null;
			$uri = $row['calendar_uri'] ?? null;
			$uid = $row['calendar_event_uid'] ?? null;
			if ($id === null || !is_string($createdBy) || !is_string($uri) || !is_string($uid)) {
				continue;
			}

			$owner = str_starts_with($uid, self::ORG_UID_PREFIX) && $orgUserId !== '' ? $orgUserId : $createdBy;
			$key = $owner . "\0" . $uri;
			if (!array_key_exists($key, $calendarIds)) {
				$calendarIds[$key] = $this->findCalendarId('principals/users/' . $owner, $uri);
			}
			if ($calendarIds[$key] === null) {
				continue;
			}

			$update->setParameter('calendarId', $calendarIds[$key]);
			$update->setParameter('id', $id);
			$bound += $update->executeStatement();
		}
		$result->closeCursor();

		if ($bound > 0) {
			$output->info('Bound ' . $bound . ' calendar-linked appointment(s) to their calendar');
		}
	}

	private function findCalendarId(string $principalUri, string $uri): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('calendars')
			->where($qb->expr()->eq('principaluri', $qb->createNamedParameter($principalUri)))
			->andWhere($qb->expr()->eq('uri', $qb->createNamedParameter($uri)));
		return self::intOrNull($qb->executeQuery()->fetchOne());
	}

	private static function intOrNull(mixed $value): ?int {
		return is_numeric($value) ? (int)$value : null;
	}
}
