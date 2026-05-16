<?php

declare(strict_types=1);

namespace OCA\Attendance\Listener;

use OCA\Attendance\Db\AuditEventMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Anonymises audit-log rows when a user is deleted from Nextcloud. The audit
 * chain is preserved (rows are not removed) but actor/subject IDs become
 * __deleted_user__ and PII fields in meta are stripped.
 *
 * @template-implements IEventListener<Event>
 */
class UserDeletedListener implements IEventListener {
	private AuditEventMapper $auditEventMapper;
	private LoggerInterface $logger;

	public function __construct(AuditEventMapper $auditEventMapper, LoggerInterface $logger) {
		$this->auditEventMapper = $auditEventMapper;
		$this->logger = $logger;
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			return;
		}
		$userId = $event->getUser()->getUID();
		try {
			$touched = $this->auditEventMapper->anonymiseUser($userId);
			if ($touched > 0) {
				$this->logger->info('Anonymised audit events for deleted user', [
					'userId' => $userId,
					'rows' => $touched,
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Failed to anonymise audit events for deleted user', [
				'userId' => $userId,
				'error' => $e->getMessage(),
			]);
		}
	}
}
