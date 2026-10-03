<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

/**
 * What one appointment-list request asks for: the scope, the filters on top of
 * it and the slice to return. One object because paged and unpaged requests
 * have to read the same rules.
 */
final class AppointmentListQuery {
	public const TIMEFRAME_UPCOMING = 'upcoming';
	public const TIMEFRAME_PAST = 'past';
	public const TIMEFRAME_ALL = 'all';

	public const STATUS_OPEN = 'open';
	public const STATUS_CLOSED = 'closed';
	public const STATUS_CANCELLED = 'cancelled';

	public const RESPONSE_NONE = 'none';

	public const ROLE_ATTENDEE = 'attendee';
	public const ROLE_ORGANIZER = 'organizer';
	public const ROLE_UNINVOLVED = 'uninvolved';
	public const ROLES = [self::ROLE_ATTENDEE, self::ROLE_ORGANIZER, self::ROLE_UNINVOLVED];

	public const MAX_LIMIT = 100;

	/**
	 * @param list<string> $locations Locations to keep; empty means every location
	 * @param list<int> $categoryIds Categories to keep; empty means every category
	 * @param ?int $limit Page size; null returns everything
	 */
	private function __construct(
		public readonly string $timeframe,
		public readonly bool $unansweredOnly,
		public readonly bool $onlyForMe,
		public readonly bool $notScheduledOut,
		public readonly bool $onlyScheduled,
		public readonly string $search,
		public readonly ?string $status,
		public readonly ?string $response,
		public readonly ?string $role,
		public readonly array $locations,
		public readonly array $categoryIds,
		public readonly bool $cancelledLast,
		public readonly ?int $limit,
		public readonly int $offset,
	) {
	}

	/**
	 * Build from wire values, dropping anything malformed rather than failing —
	 * a filter stored by a newer or older client should still render a list.
	 *
	 * @param array<array-key, mixed> $locations
	 * @param array<array-key, mixed> $categoryIds
	 */
	public static function fromWire(
		string $timeframe = self::TIMEFRAME_UPCOMING,
		bool $unansweredOnly = false,
		bool $onlyForMe = false,
		bool $notScheduledOut = false,
		bool $onlyScheduled = false,
		string $search = '',
		?string $status = null,
		?string $response = null,
		?string $role = null,
		array $locations = [],
		array $categoryIds = [],
		bool $cancelledLast = false,
		?int $limit = null,
		int $offset = 0,
	): self {
		$timeframe = in_array($timeframe, [self::TIMEFRAME_PAST, self::TIMEFRAME_ALL], true)
			? $timeframe
			: self::TIMEFRAME_UPCOMING;

		return new self(
			$timeframe,
			// Nothing is left to answer once an appointment is over.
			$unansweredOnly && $timeframe === self::TIMEFRAME_UPCOMING,
			// Both scheduling filters are about the user's own place in an
			// appointment, so letting through other people's would be odd.
			$onlyForMe || $notScheduledOut || $onlyScheduled,
			$notScheduledOut,
			$onlyScheduled,
			trim($search),
			in_array($status, [self::STATUS_OPEN, self::STATUS_CLOSED, self::STATUS_CANCELLED], true) ? $status : null,
			in_array($response, ['yes', 'maybe', 'no', self::RESPONSE_NONE], true) ? $response : null,
			in_array($role, self::ROLES, true) ? $role : null,
			array_values(array_unique(array_filter(
				array_map(static fn (mixed $location): string => is_string($location) ? $location : '', $locations),
				static fn (string $location): bool => $location !== '',
			))),
			array_values(array_unique(array_filter(
				array_map(static fn (mixed $id): int => is_numeric($id) ? (int)$id : 0, $categoryIds),
				static fn (int $id): bool => $id > 0,
			))),
			$cancelledLast,
			$limit === null ? null : max(1, min(self::MAX_LIMIT, $limit)),
			max(0, $offset),
		);
	}

	public function isPaged(): bool {
		return $this->limit !== null;
	}

	public function coversUpcoming(): bool {
		return $this->timeframe !== self::TIMEFRAME_PAST;
	}

	public function coversPast(): bool {
		return $this->timeframe !== self::TIMEFRAME_UPCOMING;
	}
}
