<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

/**
 * The filter a statistics run is scoped to. Carried as one object because the
 * evaluation and its export have to agree on it exactly — the export is meant
 * to be the table the user is looking at.
 */
final class StatisticsFilter {
	public const GROUP_BY_GROUPS = 'groups';
	public const GROUP_BY_TEAMS = 'teams';

	/**
	 * @param ?string $startDate Y-m-d, inclusive
	 * @param ?string $endDate Y-m-d, inclusive
	 * @param list<int> $categoryIds Categories to include; empty means every category
	 * @param bool $includeUncategorized Also include appointments without a category
	 */
	private function __construct(
		public readonly ?string $startDate,
		public readonly ?string $endDate,
		public readonly array $categoryIds,
		public readonly bool $includeUncategorized,
		public readonly string $groupBy,
	) {
	}

	/**
	 * Build from wire values, dropping anything malformed rather than failing —
	 * a stale bookmark with a removed category should still render a table.
	 *
	 * @param list<int> $categoryIds
	 */
	public static function fromWire(
		?string $startDate,
		?string $endDate,
		array $categoryIds = [],
		bool $includeUncategorized = false,
		string $groupBy = self::GROUP_BY_GROUPS,
	): self {
		return new self(
			self::normalizeDate($startDate),
			self::normalizeDate($endDate),
			array_values(array_unique(array_filter($categoryIds, static fn (int $id): bool => $id > 0))),
			$includeUncategorized,
			$groupBy === self::GROUP_BY_TEAMS ? self::GROUP_BY_TEAMS : self::GROUP_BY_GROUPS,
		);
	}

	public function groupsByTeams(): bool {
		return $this->groupBy === self::GROUP_BY_TEAMS;
	}

	private static function normalizeDate(?string $date): ?string {
		if ($date === null || $date === '') {
			return null;
		}
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		return $parsed !== false && $parsed->format('Y-m-d') === $date ? $date : null;
	}
}
