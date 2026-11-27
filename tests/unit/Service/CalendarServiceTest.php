<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Service\CalendarService;
use OCP\App\IAppManager;
use OCP\Calendar\ICalendarQuery;
use OCP\Calendar\IManager as ICalendarManager;
use PHPUnit\Framework\TestCase;

class CalendarServiceTest extends TestCase {
	/**
	 * @param list<array<string, mixed>> $objects In the shape CalDavBackend::search() returns
	 */
	private function serviceReturning(array $objects): CalendarService {
		$manager = $this->createMock(ICalendarManager::class);
		$manager->method('newQuery')->willReturn($this->createMock(ICalendarQuery::class));
		$manager->method('searchForPrincipal')->willReturn([
			['calendar-uri' => 'personal', 'uri' => 'event.ics', 'objects' => $objects],
		]);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(true);

		return new CalendarService($manager, $appManager);
	}

	public function testRepeatablePropertiesLikeCategoriesYieldTheirFirstValue(): void {
		// Single-valued properties come as [value, params]; repeatable ones as a list of those.
		$service = $this->serviceReturning([[
			'UID' => ['uid-1', []],
			'SUMMARY' => ['Rehearsal', []],
			'CATEGORIES' => [['Choir, Concert', []], ['Other', []]],
			'DTSTART' => [new \DateTimeImmutable('2026-12-01 18:00:00', new \DateTimeZone('UTC')), []],
			'DTEND' => [new \DateTimeImmutable('2026-12-01 20:00:00', new \DateTimeZone('UTC')), []],
		]]);

		$events = $service->getEventsFromCalendar('alice', 'personal', '2026-12-01', '2026-12-31');

		$this->assertCount(1, $events);
		$this->assertSame('Choir', $events[0]['category']);
		$this->assertSame('Rehearsal', $events[0]['summary']);
		$this->assertSame('2026-12-01T18:00:00Z', $events[0]['dtstart']);
		$this->assertSame('2026-12-01T20:00:00Z', $events[0]['dtend']);
		$this->assertSame('uid-1_20261201180000', $events[0]['id']);
	}
}
