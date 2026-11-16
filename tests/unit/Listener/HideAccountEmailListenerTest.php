<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Listener;

use OCA\Attendance\Listener\HideAccountEmailListener;
use OCA\Attendance\Service\ConfigService;
use OCP\Collaboration\AutoComplete\AutoCompleteFilterEvent;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HideAccountEmailListenerTest extends TestCase {
	private ConfigService|MockObject $configService;
	private HideAccountEmailListener $listener;

	protected function setUp(): void {
		$this->configService = $this->createMock(ConfigService::class);
		$this->listener = new HideAccountEmailListener($this->configService);
	}

	private function account(string $userId, string $unique): array {
		return [
			'label' => ucfirst($userId),
			'value' => ['shareType' => 0, 'shareWith' => $userId],
			'shareWithDisplayNameUnique' => $unique,
		];
	}

	/** The shape core hands to the event: wide matches on top, exact ones nested. */
	private function search(): AutoCompleteFilterEvent {
		return new AutoCompleteFilterEvent([
			'exact' => [
				'users' => [$this->account('ada', 'ada@example.org')],
				'emails' => [$this->account('guest@example.org', 'guest@example.org')],
			],
			'users' => [$this->account('adam', 'adam@example.org')],
			'groups' => [['label' => 'Choir', 'value' => ['shareType' => 1, 'shareWith' => 'choir']]],
		], 'ada', null, null, null, [0, 1, 4], 10);
	}

	public function testAccountsShowTheirUserIdInsteadOfTheAddress(): void {
		$this->configService->method('isAccountEmailHidden')->willReturn(true);
		$event = $this->search();

		$this->listener->handle($event);

		$results = $event->getResults();
		$this->assertSame([$this->account('ada', 'ada')], $results['exact']['users']);
		$this->assertSame([$this->account('adam', 'adam')], $results['users']);
	}

	/** An address somebody is invited by is not an account's and stays what was typed. */
	public function testOtherKindsOfResultsAreLeftAlone(): void {
		$this->configService->method('isAccountEmailHidden')->willReturn(true);
		$event = $this->search();
		$before = $event->getResults();

		$this->listener->handle($event);

		$results = $event->getResults();
		$this->assertSame($before['exact']['emails'], $results['exact']['emails']);
		$this->assertSame($before['groups'], $results['groups']);
	}

	public function testNothingChangesWhileTheSettingIsOff(): void {
		$this->configService->method('isAccountEmailHidden')->willReturn(false);
		$event = $this->search();
		$before = $event->getResults();

		$this->listener->handle($event);

		$this->assertSame($before, $event->getResults());
	}

	public function testOtherEventsAreIgnored(): void {
		$this->configService->expects($this->never())->method('isAccountEmailHidden');

		$this->listener->handle($this->createMock(UserDeletedEvent::class));
	}
}
