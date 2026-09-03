<?php

declare(strict_types=1);

namespace OCA\Attendance\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Attendance limit with an optional waitlist.
 *
 * Who holds a spot is derived, not stored: the yes-responses ordered by
 * spot_claimed_at, the first max_attendees of them in, the rest waiting. That
 * keeps the queue from drifting away from the answers it is built out of, and
 * makes promotion a consequence of somebody leaving rather than a write of its
 * own. spot_claimed_at is set when a response becomes a yes and cleared when it
 * stops being one, so re-answering yes goes to the back of the queue.
 *
 * All columns nullable and additive, so older mobile clients keep working.
 */
final class Version000024Date20260903130000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('att_appointments')) {
			$table = $schema->getTable('att_appointments');

			if (!$table->hasColumn('max_attendees')) {
				$table->addColumn('max_attendees', 'integer', [
					'notnull' => false,
					'default' => null,
				]);
			}

			if (!$table->hasColumn('waitlist_enabled')) {
				$table->addColumn('waitlist_enabled', 'boolean', [
					'notnull' => false,
					'default' => true,
				]);
			}
		}

		if ($schema->hasTable('att_responses')) {
			$table = $schema->getTable('att_responses');

			if (!$table->hasColumn('spot_claimed_at')) {
				$table->addColumn('spot_claimed_at', 'datetime', [
					'notnull' => false,
					'default' => null,
				]);
			}

			// Same shape as booking_notified_*: a spot that frees up and fills
			// again must not notify the same person twice.
			if (!$table->hasColumn('waitlist_notified_status')) {
				$table->addColumn('waitlist_notified_status', 'string', [
					'notnull' => false,
					'length' => 32,
				]);
			}

			if (!$table->hasColumn('waitlist_notified_at')) {
				$table->addColumn('waitlist_notified_at', 'datetime', [
					'notnull' => false,
					'default' => null,
				]);
			}
		}

		return $schema;
	}

	/**
	 * Existing yes-responses need an ordering key, or an organizer who sets a
	 * limit on a running appointment would find everybody tied for last place.
	 * When they answered is the fairest thing on record.
	 *
	 * Guarded on IS NULL, so a re-run after a failed update is a no-op and a
	 * later claim is never overwritten.
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if (!$schema->hasTable('att_responses')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update('att_responses')
			->set('spot_claimed_at', $qb->createFunction('responded_at'))
			->where($qb->expr()->eq('response', $qb->createNamedParameter('yes')))
			->andWhere($qb->expr()->isNull('spot_claimed_at'));

		$updated = $qb->executeStatement();
		$output->info("Seeded {$updated} existing yes-responses with a spot-claim time.");
	}
}
