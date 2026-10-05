<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

/**
 * Everything one export run was asked for: which appointments go in, and which
 * columns each of them gets. Carried as one object because the two halves are
 * decided together in the dialog and have to stay together — the column choice
 * drives the merged header cells, which only line up if the appointment set
 * behind them is the same one the filter produced.
 */
final class ExportOptions {
	/**
	 * @param ?list<int> $appointmentIds Specific appointments, or null for no ID filter
	 * @param ?string $startDate Y-m-d, inclusive
	 * @param ?string $endDate Y-m-d, inclusive
	 * @param ?list<string> $seriesIds Series to export in full, or null for no series filter
	 */
	private function __construct(
		public readonly ?array $appointmentIds,
		public readonly ?string $startDate,
		public readonly ?string $endDate,
		public readonly ?array $seriesIds,
		public readonly bool $includeRsvp,
		public readonly bool $includeCheckin,
		public readonly bool $includeComments,
		public readonly bool $includeOrganizers,
		public readonly bool $includeGroup,
		public readonly bool $includeLocation,
	) {
	}

	/**
	 * Build from wire values. Empty filter arrays collapse to null so that
	 * "selected nothing" cannot silently mean "selected everything" further
	 * down — the mapper treats both the same, the caller should not have to
	 * know that.
	 *
	 * RSVP, check-in, the group column and the location default to on and comments to off,
	 * which is what the export produced before the columns became switchable —
	 * an older client that sends neither still gets the same file.
	 *
	 * @param ?list<int> $appointmentIds
	 * @param ?list<string> $seriesIds
	 */
	public static function fromWire(
		?array $appointmentIds = null,
		?string $startDate = null,
		?string $endDate = null,
		?array $seriesIds = null,
		bool $includeRsvp = true,
		bool $includeCheckin = true,
		bool $includeComments = false,
		bool $includeOrganizers = false,
		bool $includeGroup = true,
		bool $includeLocation = true,
	): self {
		return new self(
			$appointmentIds === [] ? null : $appointmentIds,
			$startDate,
			$endDate,
			$seriesIds === [] ? null : $seriesIds,
			$includeRsvp,
			$includeCheckin,
			$includeComments,
			$includeOrganizers,
			$includeGroup,
			$includeLocation,
		);
	}

	/**
	 * The identity columns every row starts with: the name, and the group when
	 * it is switched on.
	 */
	public function leadingColumns(): int {
		return 1 + (int)$this->includeGroup;
	}

	/**
	 * Columns each appointment spans. The organizer row reuses this span as a
	 * single merged cell, so it stays correct whichever columns are on.
	 */
	public function columnsPerAppointment(): int {
		return (int)$this->includeRsvp + (int)$this->includeCheckin + (int)$this->includeComments;
	}

	/**
	 * Whether any per-appointment column is switched on. All three off would
	 * produce a sheet of names against empty header groups.
	 */
	public function hasAnyColumn(): bool {
		return $this->columnsPerAppointment() > 0;
	}
}
