<?php

declare(strict_types=1);

namespace OCA\Attendance\Listener;

use OCA\Attendance\Audit\Verb;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AuditEvent;
use OCA\Attendance\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Dispatches a notification to every manage_appointments user who opted in
 * via personal settings, whenever a response is submitted, changed, or
 * rescinded.
 */
class ResponseChangeNotificationListener {
	private const SUBJECT_BY_VERB = [
		Verb::RESPONSE_SUBMITTED => 'response_submitted',
		Verb::RESPONSE_CHANGED => 'response_changed',
		Verb::RESPONSE_RESCINDED => 'response_rescinded',
	];

	private PermissionService $permissionService;
	private IConfig $config;
	private AppointmentMapper $appointmentMapper;
	private INotificationManager $notificationManager;
	private IURLGenerator $urlGenerator;
	private LoggerInterface $logger;

	public function __construct(
		PermissionService $permissionService,
		IConfig $config,
		AppointmentMapper $appointmentMapper,
		INotificationManager $notificationManager,
		IURLGenerator $urlGenerator,
		LoggerInterface $logger,
	) {
		$this->permissionService = $permissionService;
		$this->config = $config;
		$this->appointmentMapper = $appointmentMapper;
		$this->notificationManager = $notificationManager;
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
	}

	public function handle(AuditEvent $event): void {
		$subject = self::SUBJECT_BY_VERB[$event->getVerb()] ?? null;
		if ($subject === null) {
			return;
		}

		try {
			$appointment = $this->appointmentMapper->find($event->getAppointmentId());
		} catch (DoesNotExistException $e) {
			return;
		}

		$recipients = $this->resolveOptedInRecipients($event->getActorId());
		if ($recipients === []) {
			return;
		}

		$appointmentUrl = $this->urlGenerator->linkToRouteAbsolute(
			'attendance.page.appointment',
			['id' => $appointment->getId()]
		);
		$subjectParams = $this->subjectParametersFor($event, $appointment->getName());

		$shouldFlush = $this->notificationManager->defer();
		try {
			foreach ($recipients as $recipientId) {
				try {
					$notification = $this->notificationManager->createNotification();
					$notification->setApp('attendance')
						->setUser($recipientId)
						->setDateTime(new \DateTime())
						->setObject('appointment', (string)$appointment->getId())
						->setSubject($subject, $subjectParams)
						->setLink($appointmentUrl);
					$this->notificationManager->notify($notification);
				} catch (\Throwable $e) {
					$this->logger->warning('Failed to dispatch response-change notification', [
						'recipient' => $recipientId,
						'event' => $event->getVerb(),
						'error' => $e->getMessage(),
					]);
				}
			}
		} finally {
			if ($shouldFlush) {
				$this->notificationManager->flush();
			}
		}
	}

	/**
	 * Bulk-fetch the notify_response_changes opt-in for every manager and
	 * filter to those who actually want pushes, minus the actor (no self-notify).
	 *
	 * @return list<string>
	 */
	private function resolveOptedInRecipients(?string $actorId): array {
		$managers = $this->permissionService->getUsersWith(PermissionService::PERMISSION_MANAGE_APPOINTMENTS);
		if ($managers === []) {
			return [];
		}
		// One bulk read instead of N getUserValue() calls.
		$optInValues = $this->config->getUserValueForUsers('attendance', 'notify_response_changes', $managers);
		$recipients = [];
		foreach ($managers as $uid) {
			if ($uid === $actorId) {
				continue;
			}
			if (($optInValues[$uid] ?? 'no') === 'yes') {
				$recipients[] = $uid;
			}
		}
		return $recipients;
	}

	private function subjectParametersFor(AuditEvent $event, string $appointmentName): array {
		$meta = $event->getMetaArray();
		return [
			'appointmentId' => $event->getAppointmentId(),
			'appointmentName' => $appointmentName,
			'actor' => $event->getActorId() ?? '',
			'from' => (string)($meta['from'] ?? ''),
			'to' => (string)($meta['to'] ?? ($meta['response'] ?? '')),
		];
	}
}
