<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Dashboard;

use OCA\Attendance\Activity\EventMessages;
use OCA\Attendance\Dashboard\Widget;
use OCA\Attendance\Service\AppointmentService;
use OCA\Attendance\Service\ConfigService;
use OCP\AppFramework\Services\IInitialState;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WidgetTest extends TestCase {
	private AppointmentService|MockObject $appointmentService;
	private IURLGenerator|MockObject $urlGenerator;
	private Widget $widget;

	protected function setUp(): void {
		$this->appointmentService = $this->createMock(AppointmentService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->with('alice', 'core', 'timezone', '')->willReturn('Europe/Berlin');

		$this->widget = new Widget(
			$this->createMock(IL10N::class),
			$this->appointmentService,
			$this->createMock(ConfigService::class),
			$this->createMock(IInitialState::class),
			$this->urlGenerator,
			new EventMessages($config, $this->createMock(IUserManager::class)),
			'alice',
		);
	}

	// The dashboard API maps every item through a WidgetItem-typed closure.
	public function testGetItemsReturnsWidgetItems(): void {
		$this->appointmentService->expects($this->once())
			->method('getUpcomingAppointmentsForWidget')
			->with('alice', 5)
			->willReturn([
				['id' => 42, 'name' => 'Rehearsal', 'startDatetime' => '2026-12-01T18:00:00Z', 'isAllDay' => false],
			]);
		$this->urlGenerator->method('linkToRouteAbsolute')
			->with('attendance.page.appointment', ['id' => '42'])
			->willReturn('https://cloud.example/apps/attendance/appointment/42');
		$this->urlGenerator->method('getAbsoluteURL')->willReturn('https://cloud.example/icon.svg');

		$items = $this->widget->getItems('alice', null, 5);

		$this->assertCount(1, $items);
		$this->assertInstanceOf(WidgetItem::class, $items[0]);
		$this->assertSame('Rehearsal', $items[0]->getTitle());
		$this->assertSame('01.12.2026 19:00', $items[0]->getSubtitle());
		$this->assertSame('https://cloud.example/apps/attendance/appointment/42', $items[0]->getLink());
		$this->assertSame('https://cloud.example/icon.svg', $items[0]->getIconUrl());
		$this->assertSame('42', $items[0]->getSinceId());
	}
}
