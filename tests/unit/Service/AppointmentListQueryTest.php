<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Service\AppointmentListQuery;
use PHPUnit\Framework\TestCase;

class AppointmentListQueryTest extends TestCase {
	public function testDefaultsToTheUnpagedUpcomingList(): void {
		$query = AppointmentListQuery::fromWire();

		$this->assertSame(AppointmentListQuery::TIMEFRAME_UPCOMING, $query->timeframe);
		$this->assertFalse($query->isPaged());
		$this->assertTrue($query->coversUpcoming());
		$this->assertFalse($query->coversPast());
	}

	public function testAllTimeframeCoversBothHalves(): void {
		$query = AppointmentListQuery::fromWire(AppointmentListQuery::TIMEFRAME_ALL);

		$this->assertTrue($query->coversUpcoming());
		$this->assertTrue($query->coversPast());
	}

	public function testUnknownValuesAreDroppedInsteadOfFailing(): void {
		$query = AppointmentListQuery::fromWire(
			timeframe: 'someday',
			status: 'postponed',
			response: 'perhaps',
			role: 'bystander',
		);

		$this->assertSame(AppointmentListQuery::TIMEFRAME_UPCOMING, $query->timeframe);
		$this->assertNull($query->status);
		$this->assertNull($query->response);
		$this->assertNull($query->role);
	}

	public function testLimitAndOffsetAreClamped(): void {
		$this->assertSame(AppointmentListQuery::MAX_LIMIT, AppointmentListQuery::fromWire(limit: 5000)->limit);
		$this->assertSame(1, AppointmentListQuery::fromWire(limit: 0)->limit);
		$this->assertSame(0, AppointmentListQuery::fromWire(limit: 20, offset: -3)->offset);
	}

	public function testSchedulingFiltersImplyTheUsersOwnAppointments(): void {
		$this->assertTrue(AppointmentListQuery::fromWire(notScheduledOut: true)->onlyForMe);
		$this->assertTrue(AppointmentListQuery::fromWire(onlyScheduled: true)->onlyForMe);
		$this->assertFalse(AppointmentListQuery::fromWire()->onlyForMe);
	}

	public function testUnansweredOnlyNeedsTheUpcomingList(): void {
		$this->assertTrue(AppointmentListQuery::fromWire(unansweredOnly: true)->unansweredOnly);
		$this->assertFalse(AppointmentListQuery::fromWire(AppointmentListQuery::TIMEFRAME_PAST, unansweredOnly: true)->unansweredOnly);
		$this->assertFalse(AppointmentListQuery::fromWire(AppointmentListQuery::TIMEFRAME_ALL, unansweredOnly: true)->unansweredOnly);
	}

	public function testListFiltersArriveAsQueryStrings(): void {
		$query = AppointmentListQuery::fromWire(
			search: '  concert ',
			locations: ['Hall', '', 'Hall', ['nested']],
			categoryIds: ['4', '4', 'seven', '0', 7],
		);

		$this->assertSame('concert', $query->search);
		$this->assertSame(['Hall'], $query->locations);
		$this->assertSame([4, 7], $query->categoryIds);
	}
}
