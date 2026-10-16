<?php

declare(strict_types=1);

namespace OCA\Attendance\Activity;

use OCA\Attendance\Activity\Setting\ResponsesSetting;
use OCA\Attendance\Service\PermissionService;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Renders the appointment events published via OCP\Activity\IManager. Used
 * both for the activity stream/digest email and — through the "activity"
 * app's own notifier — for push, whenever that app is enabled. See
 * NotificationService for the direct-notification fallback used when it
 * isn't. Responses are published by ResponseActivityListener.
 */
final class Provider implements IProvider {
	private const KNOWN_TYPES = [
		'appointment_cancelled',
		'appointment_reactivated',
		'booking_confirmed',
		'booking_declined',
		'appointments_series_updated',
		ResponsesSetting::IDENTIFIER,
	];

	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
		private EventMessages $eventMessages,
		private PermissionService $permissionService,
	) {
	}

	#[\Override]
	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== 'attendance' || !in_array($event->getType(), self::KNOWN_TYPES, true)) {
			throw new UnknownActivityException();
		}

		$l = $this->l10nFactory->get('attendance', $language);
		$params = $event->getSubjectParameters();
		$userId = $event->getAffectedUser();
		// Rendered per reader, so a comment only reaches those allowed to read comments.
		if (!$this->permissionService->canSeeComments($userId)) {
			unset($params['comment']);
		}

		$rendered = match ($event->getType()) {
			'appointment_cancelled' => $this->eventMessages->forCancelled($l, $params, $userId),
			'appointment_reactivated' => $this->eventMessages->forReactivated($l, $params, $userId),
			'booking_confirmed' => $this->eventMessages->forBookingConfirmed($l, $params, $userId),
			'booking_declined' => $this->eventMessages->forBookingDeclined($l, $params, $userId),
			'appointments_series_updated' => $this->eventMessages->forSeriesUpdated($l, $params),
			ResponsesSetting::IDENTIFIER => $this->eventMessages->forResponseChange($l, $event->getSubject(), $params),
		};

		$event->setParsedSubject($rendered['subject']);
		$event->setParsedMessage($rendered['message']);
		$event->setIcon($this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath('attendance', 'app-dark.svg')
		));

		return $event;
	}
}
