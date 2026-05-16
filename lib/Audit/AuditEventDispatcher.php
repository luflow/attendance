<?php

declare(strict_types=1);

namespace OCA\Attendance\Audit;

use OCA\Attendance\Db\AuditEvent;

/**
 * In-process callback registry for audit events. Listeners register at app
 * boot and receive every persisted event so they can dispatch notifications,
 * mirror to external systems, etc.
 */
class AuditEventDispatcher {
	/** @var array<int, callable(AuditEvent): void> */
	private array $listeners = [];

	public function register(callable $listener): void {
		$this->listeners[] = $listener;
	}

	public function dispatch(AuditEvent $event): void {
		foreach ($this->listeners as $listener) {
			$listener($event);
		}
	}
}
