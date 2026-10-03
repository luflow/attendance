<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Activity;

use OCA\Attendance\Activity\EventMessages;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class EventMessagesTest extends TestCase {
	/** @var IConfig|MockObject */
	private $config;
	private IL10N $l;
	private EventMessages $eventMessages;

	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')->willReturn('');

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params),
		);
		$l->method('n')->willReturnCallback(
			static fn (string $one, string $many, int $count, array $params = []): string
				=> vsprintf($count === 1 ? $one : $many, $params),
		);
		$this->l = $l;

		$this->eventMessages = new EventMessages($this->config);
	}

	public function testForCancelled(): void {
		$rendered = $this->eventMessages->forCancelled($this->l, [
			'name' => 'Rehearsal',
			'startDatetime' => '2030-08-01 18:00:00',
		], 'alice');

		$this->assertSame('It will not take place, so the time is yours again.', $rendered['message']);
		$this->assertStringContainsString('Rehearsal', $rendered['subject']);
	}

	public function testForReactivated(): void {
		$rendered = $this->eventMessages->forReactivated($this->l, [
			'name' => 'Rehearsal',
			'startDatetime' => '2030-08-01 18:00:00',
		], 'alice');

		$this->assertSame(
			'We have withdrawn the cancellation. If you have made other plans since, please update your response.',
			$rendered['message']
		);
	}

	public function testForBookingConfirmedAndDeclinedDiffer(): void {
		$params = ['name' => 'Rehearsal', 'startDatetime' => '2030-08-01 18:00:00'];

		$confirmed = $this->eventMessages->forBookingConfirmed($this->l, $params, 'alice');
		$declined = $this->eventMessages->forBookingDeclined($this->l, $params, 'alice');

		$this->assertSame('You have a place, so please plan to be there. Thanks for answering.', $confirmed['message']);
		$this->assertSame('We had more volunteers than places this time. Thanks for answering.', $declined['message']);
		$this->assertNotSame($confirmed['subject'], $declined['subject']);
	}

	public function testForSeriesUpdatedUsesPluralAndDescribesTheChange(): void {
		$rendered = $this->eventMessages->forSeriesUpdated($this->l, [
			'count' => 12,
			'name' => 'Rehearsal',
			'changed' => ['time'],
		]);

		$this->assertSame('12 appointments changed in "Rehearsal"', $rendered['subject']);
		$this->assertSame('The date has changed. Please check whether your response still fits.', $rendered['message']);
	}

	public function testDescribeUpdateNamesBothAspectsWhenBothChanged(): void {
		$this->assertSame(
			'Date and location have changed. Please check whether your response still fits.',
			$this->eventMessages->describeUpdate(['time', 'location'], $this->l)
		);
	}

	public function testDescribeUpdateFallsBackForUnknownChanges(): void {
		$this->assertSame(
			'Please check the appointment.',
			$this->eventMessages->describeUpdate(['something_else'], $this->l)
		);
	}

	public function testFormatDateForUserFallsBackToUnknownForEmptyInput(): void {
		$this->assertSame('Unknown', $this->eventMessages->formatDateForUser('', 'alice'));
	}
}
