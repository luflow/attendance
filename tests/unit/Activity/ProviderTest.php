<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Activity;

use OCA\Attendance\Activity\EventMessages;
use OCA\Attendance\Activity\Provider;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProviderTest extends TestCase {
	/** @var IFactory|MockObject */
	private $l10nFactory;
	/** @var IURLGenerator|MockObject */
	private $urlGenerator;
	private Provider $provider;

	protected function setUp(): void {
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('imagePath')->willReturn('/img/attendance/app-dark.svg');
		$this->urlGenerator->method('getAbsoluteURL')->willReturnArgument(0);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params),
		);
		$l->method('n')->willReturnCallback(
			static fn (string $one, string $many, int $count, array $params = []): string
				=> vsprintf($count === 1 ? $one : $many, $params),
		);
		$this->l10nFactory->method('get')->willReturn($l);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('');

		$this->provider = new Provider($this->l10nFactory, $this->urlGenerator, new EventMessages($config));
	}

	private function mockEvent(string $app, string $type, array $params, string $affectedUser = 'alice'): IEvent|MockObject {
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn($app);
		$event->method('getType')->willReturn($type);
		$event->method('getSubjectParameters')->willReturn($params);
		$event->method('getAffectedUser')->willReturn($affectedUser);
		$event->method('setParsedSubject')->willReturnSelf();
		$event->method('setParsedMessage')->willReturnSelf();
		$event->method('setIcon')->willReturnSelf();
		return $event;
	}

	public function testRendersCancelledEvent(): void {
		$event = $this->mockEvent('attendance', 'appointment_cancelled', [
			'name' => 'Rehearsal',
			'startDatetime' => '2030-08-01 18:00:00',
		]);
		$event->expects($this->once())
			->method('setParsedMessage')
			->with('It will not take place, so the time is yours again.')
			->willReturnSelf();

		$this->provider->parse('de', $event);
	}

	public function testRendersSeriesUpdatedEvent(): void {
		$event = $this->mockEvent('attendance', 'appointments_series_updated', [
			'count' => 3,
			'name' => 'Rehearsal',
			'changed' => ['location'],
		]);
		$event->expects($this->once())
			->method('setParsedSubject')
			->with('3 appointments changed in "Rehearsal"')
			->willReturnSelf();

		$this->provider->parse('de', $event);
	}

	public function testThrowsForUnknownType(): void {
		$event = $this->mockEvent('attendance', 'appointment_reminder', []);

		$this->expectException(UnknownActivityException::class);
		$this->provider->parse('de', $event);
	}

	public function testThrowsForOtherApps(): void {
		$event = $this->mockEvent('files', 'appointment_cancelled', []);

		$this->expectException(UnknownActivityException::class);
		$this->provider->parse('de', $event);
	}
}
