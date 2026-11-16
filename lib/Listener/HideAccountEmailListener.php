<?php

declare(strict_types=1);

namespace OCA\Attendance\Listener;

use OCA\Attendance\Service\ConfigService;
use OCP\Collaboration\AutoComplete\AutoCompleteFilterEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * The account search shows the email address below each name. With the
 * setting on, it shows the user ID instead, as core does for accounts without one.
 *
 * @template-implements IEventListener<Event>
 */
final class HideAccountEmailListener implements IEventListener {
	public function __construct(
		private ConfigService $configService,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof AutoCompleteFilterEvent || !$this->configService->isAccountEmailHidden()) {
			return;
		}

		$results = $this->withUserIds($event->getResults());
		if (isset($results['exact']) && is_array($results['exact'])) {
			$results['exact'] = $this->withUserIds($results['exact']);
		}
		$event->setResults($results);
	}

	private function withUserIds(array $results): array {
		if (isset($results['users']) && is_array($results['users'])) {
			$results['users'] = array_map(fn (mixed $account): mixed => $this->withUserId($account), $results['users']);
		}
		return $results;
	}

	private function withUserId(mixed $account): mixed {
		if (is_array($account) && isset($account['value']) && is_array($account['value'])
			&& isset($account['value']['shareWith']) && is_string($account['value']['shareWith'])) {
			$account['shareWithDisplayNameUnique'] = $account['value']['shareWith'];
		}
		return $account;
	}
}
