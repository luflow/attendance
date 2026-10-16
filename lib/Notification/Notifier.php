<?php

declare(strict_types=1);

namespace OCA\Attendance\Notification;

use OCA\Attendance\Activity\EventMessages;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Service\QuickResponseTokenService;
use OCA\Attendance\Service\ResponsePolicyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	private IFactory $l10nFactory;
	private IURLGenerator $urlGenerator;
	private QuickResponseTokenService $tokenService;
	private AttendanceResponseMapper $responseMapper;
	private AppointmentMapper $appointmentMapper;
	private ResponsePolicyService $responsePolicyService;
	private EventMessages $eventMessages;

	/** @var array<int, bool> */
	private array $maybeOffered = [];
	private ?string $iconUrl = null;

	public function __construct(
		IFactory $l10nFactory,
		IURLGenerator $urlGenerator,
		QuickResponseTokenService $tokenService,
		AttendanceResponseMapper $responseMapper,
		AppointmentMapper $appointmentMapper,
		ResponsePolicyService $responsePolicyService,
		EventMessages $eventMessages,
	) {
		$this->l10nFactory = $l10nFactory;
		$this->urlGenerator = $urlGenerator;
		$this->tokenService = $tokenService;
		$this->responseMapper = $responseMapper;
		$this->appointmentMapper = $appointmentMapper;
		$this->responsePolicyService = $responsePolicyService;
		$this->eventMessages = $eventMessages;
	}

	public function getID(): string {
		return 'attendance';
	}

	public function getName(): string {
		return $this->l10nFactory->get('attendance')->t('Attendance');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'attendance') {
			throw new UnknownNotificationException();
		}

		// Published via OCP\Activity\IManager (the "activity" app is enabled) —
		// OCA\Attendance\Activity\Provider already rendered the subject/message,
		// so that part is not ours to touch. But the mobile app's push/OCS
		// handling keys navigation off objectType starting with "appointment"
		// and off objectId as the appointment ID, so we restore that shape
		// before stepping aside: setObject() mutates the shared $notification
		// in place, and every notifier in OCP\Notification\IManager::prepare()'s
		// chain sees the same instance, so this survives even though we still
		// throw below. Without the "activity" app, these subjects never reach
		// this object type and fall through to the cases below as before.
		if ($notification->getObjectType() === 'activity_notification') {
			$this->restoreObjectForClientCompatibility($notification);
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get('attendance', $languageCode);

		// For single-appointment notifications, check if the user already responded.
		// Test reminders skip this so the delivery chain can be verified end-to-end.
		if (in_array($notification->getSubject(), ['appointment_reminder', 'appointment_created'])
			&& !($notification->getSubjectParameters()['test'] ?? false)) {
			$parameters = $notification->getSubjectParameters();
			$appointmentId = $parameters['appointmentId'] ?? 0;
			if ($appointmentId > 0) {
				try {
					$response = $this->responseMapper->findByAppointmentAndUser($appointmentId, $notification->getUser());
					// Only dismiss if user gave a definitive answer (yes or no).
					// Maybe-responders may still receive reminders to decide.
					if ($response->getResponse() !== 'maybe') {
						throw new AlreadyProcessedException();
					}
				} catch (DoesNotExistException $e) {
					// No response yet — continue preparing
				}
			}
		}

		switch ($notification->getSubject()) {
			case 'appointment_reminder':
				$parameters = $notification->getSubjectParameters();
				$appointmentName = $parameters['name'] ?? 'Unknown';
				$appointmentDate = $this->eventMessages->formatAppointmentDate($parameters, $notification->getUser());
				$appointmentId = $parameters['appointmentId'] ?? 0;
				$userId = $notification->getUser();

				$notification->setParsedSubject(
					// TRANSLATORS: Push notification subject reminding someone that they have not answered yet. %1$s is the appointment name, %2$s its date and time. "Response" is the recipient's own yes/no/maybe answer. Sample German: "Antwort fehlt: „Probe" am 12.09.2026 18:00".
					$l->t('Response missing: %1$s on %2$s', [$appointmentName, $appointmentDate])
				);
				$notification->setParsedMessage(
					// TRANSLATORS Push notification body for a reminder, shown under a "Response missing" subject that already names the appointment and its date. The app speaks as "we", the people organizing — not about them in the third person. People who cannot come tend to stay silent rather than answer, which leaves the planning guessing, hence the second sentence. Sample German: "Wir warten noch auf dich. Ein Nein hilft uns genauso wie ein Ja.".
					$l->t('We are still waiting. A no helps just as much as a yes.')
				);
				$notification->setIcon($this->iconUrl());

				// Add quick response action buttons
				if ($appointmentId > 0) {
					$this->addQuickResponseActions($notification, $l, $appointmentId, $userId);
				}

				return $notification;
			case 'appointment_created':
				$parameters = $notification->getSubjectParameters();
				$appointmentName = $parameters['name'] ?? 'Unknown';
				$appointmentDate = $this->eventMessages->formatAppointmentDate($parameters, $notification->getUser());
				$appointmentId = $parameters['appointmentId'] ?? 0;
				$userId = $notification->getUser();

				$notification->setParsedSubject(
					// TRANSLATORS: Push notification subject announcing an appointment the recipient has just been invited to. %1$s is the appointment name, %2$s its date and time. Sample German: "Neuer Termin: „Probe" am 12.09.2026 18:00".
					$l->t('New appointment: %1$s on %2$s', [$appointmentName, $appointmentDate])
				);
				$notification->setParsedMessage(
					// TRANSLATORS Push notification body for a newly created appointment, shown under a "New appointment" subject that already names it and its date. The app speaks as "we", the people organizing — not about them in the third person. "Make it" means being able to attend. Organizers only switch this notification on when they need answers fast, so keep the urgency of the first sentence: it is the whole reason the message was sent. Sample German: "Wir brauchen schnell deine Rückmeldung. Bist du dabei?".
					$l->t('We need your answer quickly. Let us know whether you can make it.')
				);
				$notification->setIcon($this->iconUrl());

				// Add quick response action buttons
				if ($appointmentId > 0) {
					$this->addQuickResponseActions($notification, $l, $appointmentId, $userId);
				}

				return $notification;
			case 'appointment_cancelled':
				$rendered = $this->eventMessages->forCancelled($l, $notification->getSubjectParameters(), $notification->getUser());
				$notification->setParsedSubject($rendered['subject']);
				$notification->setParsedMessage($rendered['message']);
				$notification->setIcon($this->iconUrl());

				return $notification;
			case 'appointment_reactivated':
				$rendered = $this->eventMessages->forReactivated($l, $notification->getSubjectParameters(), $notification->getUser());
				$notification->setParsedSubject($rendered['subject']);
				$notification->setParsedMessage($rendered['message']);
				$notification->setIcon($this->iconUrl());

				return $notification;
			case 'appointment_updated':
				$parameters = $notification->getSubjectParameters();
				$appointmentName = (string)($parameters['name'] ?? 'Unknown');
				$appointmentDate = $this->eventMessages->formatAppointmentDate($parameters, $notification->getUser());
				$appointmentId = (int)($parameters['appointmentId'] ?? 0);
				$userId = $notification->getUser();

				$notification->setParsedSubject(
					// TRANSLATORS Push notification subject: details of an appointment the person already knows about have changed. %1$s is the appointment name, %2$s its new date.
					$l->t('Appointment changed: %1$s on %2$s', [$appointmentName, $appointmentDate])
				);
				$notification->setParsedMessage(
					$this->eventMessages->describeUpdate((array)($parameters['changed'] ?? []), $l)
				);
				$notification->setIcon($this->iconUrl());

				// A moved appointment is exactly when someone needs to revise their answer.
				if ($appointmentId > 0) {
					$this->addQuickResponseActions($notification, $l, $appointmentId, $userId);
				}

				return $notification;
			case 'booking_confirmed':
			case 'booking_declined':
				$rendered = $notification->getSubject() === 'booking_confirmed'
					? $this->eventMessages->forBookingConfirmed($l, $notification->getSubjectParameters(), $notification->getUser())
					: $this->eventMessages->forBookingDeclined($l, $notification->getSubjectParameters(), $notification->getUser());
				$notification->setParsedSubject($rendered['subject']);
				$notification->setParsedMessage($rendered['message']);
				$notification->setIcon($this->iconUrl());
				return $notification;
			case 'waitlist_promoted':
			case 'waitlist_not_promoted':
				$parameters = $notification->getSubjectParameters();
				$appointmentName = (string)($parameters['name'] ?? 'Unknown');
				$appointmentDate = $this->eventMessages->formatAppointmentDate($parameters, $notification->getUser());
				if ($notification->getSubject() === 'waitlist_promoted') {
					$notification->setParsedSubject(
						// TRANSLATORS Push notification subject: the appointment was full when the person answered, a spot has now come free and they have it. %1$s is the appointment name, %2$s the date.
						$l->t('A spot came free for %1$s on %2$s', [$appointmentName, $appointmentDate])
					);
					$notification->setParsedMessage(
						// TRANSLATORS Push notification body, shown under a subject that already says a spot came free. Nobody clicked anything to make this happen — the person was waiting and moved up — so say plainly that they are in now. Sample German: "Du warst auf der Warteliste und bist jetzt dabei. Bitte halte dir den Termin frei."
						$l->t('You were on the waitlist and are in now. Please plan to be there.')
					);
				} else {
					$notification->setParsedSubject(
						// TRANSLATORS Push notification subject: the appointment closed while the person was still waiting for a spot. %1$s is the appointment name, %2$s the date.
						$l->t('No spot came free for %1$s on %2$s', [$appointmentName, $appointmentDate])
					);
					$notification->setParsedMessage(
						// TRANSLATORS Push notification body, shown under a subject that already says no spot came free. The point is to release the person from holding the date — without this they keep the evening free for nothing. The app speaks as "we", the people organizing. Sample German: "Der Termin ist voll geblieben, du musst dir den Tag nicht mehr freihalten. Danke für deine Antwort."
						$l->t('The appointment stayed full, so you no longer need to keep the date free. Thanks for answering.')
					);
				}
				$notification->setIcon($this->iconUrl());
				return $notification;
			case 'response_submitted':
			case 'response_changed':
			case 'response_rescinded':
				return $this->prepareResponseChangeNotification($notification, $l);
			case 'appointments_bulk_created':
				$parameters = $notification->getSubjectParameters();
				$count = $parameters['count'] ?? 0;
				$firstName = $parameters['firstName'] ?? '';

				$notification->setParsedSubject(
					// TRANSLATORS Push notification subject: a recurring appointment was set up, or a whole series just became visible to this person. %1$s is how many dates there are, %2$s the name they all share — every appointment in the batch carries that same name, so it is the title of the whole thing rather than one example. Keep %1$s in both forms: languages with more than two plural forms need the number in the "one" form too (Russian uses it for 21, 31, …).
					// Sample German: "1 neuer Termin „Probe" hinzugefuegt" / "12 neue Termine „Probe" hinzugefuegt".
					$l->n('%1$s new "%2$s" appointment added', '%1$s new "%2$s" appointments added', $count, [$count, $firstName])
				);
				$notification->setParsedMessage(
					// TRANSLATORS Push notification body for several appointments added at once, shown under a subject that already gives the number and one example name. The app speaks as "we", the people organizing — not about them in the third person. The recipient answers each appointment separately, which is why this says "which ones" rather than asking for a single answer. Sample German: "Sag uns bitte, bei welchen Terminen du dabei bist.".
					$l->t('Let us know which ones you can make.')
				);
				$notification->setIcon($this->iconUrl());

				return $notification;
			case 'appointments_series_updated':
				$rendered = $this->eventMessages->forSeriesUpdated($l, $notification->getSubjectParameters());
				$notification->setParsedSubject($rendered['subject']);
				$notification->setParsedMessage($rendered['message']);
				$notification->setIcon($this->iconUrl());

				return $notification;
			default:
				throw new UnknownNotificationException();
		}
	}

	private function prepareResponseChangeNotification(INotification $notification, \OCP\IL10N $l): INotification {
		$rendered = $this->eventMessages->forResponseChange($l, $notification->getSubject(), $notification->getSubjectParameters());
		$notification->setParsedSubject($rendered['subject']);
		$notification->setIcon($this->iconUrl());
		return $notification;
	}

	/**
	 * Puts back the objectType/objectId shape the direct-notification path
	 * would have produced (see NotificationService::sendViaActivityOrDirect),
	 * for the mobile app's benefit — see the call site in prepare().
	 */
	private function restoreObjectForClientCompatibility(INotification $notification): void {
		if ($notification->getSubject() === 'appointments_series_updated') {
			// Never tied to one appointment — same "no single target" shape
			// the direct path uses for this and appointments_bulk_created.
			$notification->setObject('appointment_bulk', uniqid());
			return;
		}

		$appointmentId = (int)($notification->getSubjectParameters()['appointmentId'] ?? 0);
		if ($appointmentId > 0) {
			$notification->setObject('appointment', (string)$appointmentId);
		}
	}

	/**
	 * The same for every notification of the app, while imagePath() walks the
	 * theme folders (or asks the memcache) on every call.
	 */
	private function iconUrl(): string {
		return $this->iconUrl ??= $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath('attendance', 'app-dark.svg'),
		);
	}

	/**
	 * Whether this appointment still offers "Maybe". A notification can outlive
	 * the appointment it points at, and an unknown one keeps all three buttons
	 * rather than silently dropping one.
	 *
	 * Remembered per appointment: a wave prepares one notification per
	 * recipient, and all of them ask about the same appointment.
	 */
	private function isMaybeOffered(int $appointmentId): bool {
		return $this->maybeOffered[$appointmentId] ??= $this->lookupMaybeOffered($appointmentId);
	}

	private function lookupMaybeOffered(int $appointmentId): bool {
		try {
			return $this->responsePolicyService->isMaybeAllowed(
				$this->appointmentMapper->find($appointmentId),
			);
		} catch (\Throwable $e) {
			return true;
		}
	}

	/**
	 * Add quick response action buttons to a notification.
	 *
	 * @param INotification $notification The notification to add actions to
	 * @param \OCP\IL10N $l The localization instance
	 * @param int $appointmentId The appointment ID
	 * @param string $userId The user ID
	 */
	private function addQuickResponseActions(
		INotification $notification,
		\OCP\IL10N $l,
		int $appointmentId,
		string $userId,
	): void {
		// Actions are added in reverse order because the frontend displays them reversed
		// No action (added first, displays last/right)
		$noAction = $notification->createAction();
		$noAction->setLabel('no')
			->setParsedLabel($l->t('No'))
			->setLink(
				$this->tokenService->generateQuickResponseUrl($userId, $appointmentId, 'no'),
				IAction::TYPE_WEB
			)
			->setPrimary(false);
		$notification->addParsedAction($noAction);

		// Maybe action (added second, displays middle). Left out where the
		// appointment does not offer it — the link would only ever 400.
		if ($this->isMaybeOffered($appointmentId)) {
			$maybeAction = $notification->createAction();
			$maybeAction->setLabel('maybe')
				->setParsedLabel($l->t('Maybe'))
				->setLink(
					$this->tokenService->generateQuickResponseUrl($userId, $appointmentId, 'maybe'),
					IAction::TYPE_WEB
				)
				->setPrimary(false);
			$notification->addParsedAction($maybeAction);
		}

		// Yes action (added last, displays first/left)
		$yesAction = $notification->createAction();
		$yesAction->setLabel('yes')
			->setParsedLabel($l->t('Yes'))
			->setLink(
				$this->tokenService->generateQuickResponseUrl($userId, $appointmentId, 'yes'),
				IAction::TYPE_WEB
			)
			->setPrimary(false);
		$notification->addParsedAction($yesAction);
	}
}
