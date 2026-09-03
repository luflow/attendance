<?php

declare(strict_types=1);

namespace OCA\Attendance\Notification;

use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Service\QuickResponseTokenService;
use OCA\Attendance\Service\ResponsePolicyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
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
	private IConfig $config;
	private AttendanceResponseMapper $responseMapper;
	private AppointmentMapper $appointmentMapper;
	private ResponsePolicyService $responsePolicyService;
	private IUserManager $userManager;

	public function __construct(
		IFactory $l10nFactory,
		IURLGenerator $urlGenerator,
		QuickResponseTokenService $tokenService,
		IConfig $config,
		AttendanceResponseMapper $responseMapper,
		IUserManager $userManager,
		AppointmentMapper $appointmentMapper,
		ResponsePolicyService $responsePolicyService,
	) {
		$this->l10nFactory = $l10nFactory;
		$this->urlGenerator = $urlGenerator;
		$this->tokenService = $tokenService;
		$this->config = $config;
		$this->responseMapper = $responseMapper;
		$this->appointmentMapper = $appointmentMapper;
		$this->responsePolicyService = $responsePolicyService;
		$this->userManager = $userManager;
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
				$appointmentDate = $this->formatDateForUser(
					$parameters['startDatetime'] ?? $parameters['date'] ?? '',
					$notification->getUser()
				);
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
				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath('attendance', 'app-dark.svg')
				));

				// Add quick response action buttons
				if ($appointmentId > 0) {
					$this->addQuickResponseActions($notification, $l, $appointmentId, $userId);
				}

				return $notification;
			case 'appointment_created':
				$parameters = $notification->getSubjectParameters();
				$appointmentName = $parameters['name'] ?? 'Unknown';
				$appointmentDate = $this->formatDateForUser(
					$parameters['startDatetime'] ?? $parameters['date'] ?? '',
					$notification->getUser()
				);
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
				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath('attendance', 'app-dark.svg')
				));

				// Add quick response action buttons
				if ($appointmentId > 0) {
					$this->addQuickResponseActions($notification, $l, $appointmentId, $userId);
				}

				return $notification;
			case 'appointment_cancelled':
				$parameters = $notification->getSubjectParameters();
				$appointmentName = $parameters['name'] ?? 'Unknown';
				$appointmentDate = $this->formatDateForUser(
					$parameters['startDatetime'] ?? $parameters['date'] ?? '',
					$notification->getUser()
				);

				$notification->setParsedSubject(
					// TRANSLATORS: Push notification subject: the appointment was called off and will not happen (German "abgesagt", not "abgebrochen"). %1$s is the appointment name, %2$s the date it would have taken place. Sample German: "Termin abgesagt: „Probe" am 12.09.2026 18:00".
					$l->t('Appointment cancelled: %1$s on %2$s', [$appointmentName, $appointmentDate])
				);
				$notification->setParsedMessage(
					// TRANSLATORS Push notification body for a called-off appointment (German "abgesagt", not "abgebrochen"), shown under an "Appointment cancelled" subject that already names it and its date. The second half is the useful part: nothing is left to do and the slot in the calendar is free again. Sample German: "Der Termin fällt aus, du hast die Zeit wieder frei.".
					$l->t('It will not take place, so the time is yours again.')
				);
				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath('attendance', 'app-dark.svg')
				));

				return $notification;
			case 'appointment_reactivated':
				$parameters = $notification->getSubjectParameters();
				$appointmentName = (string)($parameters['name'] ?? 'Unknown');
				$appointmentDate = $this->formatDateForUser(
					(string)($parameters['startDatetime'] ?? ''),
					$notification->getUser()
				);

				$notification->setParsedSubject(
					// TRANSLATORS Push notification subject: a cancelled appointment is back on. %1$s is the appointment name, %2$s the date.
					$l->t('Appointment takes place after all: %1$s on %2$s', [$appointmentName, $appointmentDate])
				);
				$notification->setParsedMessage(
					// TRANSLATORS Push notification body, shown under the "takes place after all" subject, which already names the appointment and its date. The app speaks as "we", the people organizing. The second sentence is the point: someone who was told it was off has likely booked that slot for something else, so their old yes/no/maybe answer may no longer hold. Sample German: "Wir haben die Absage zurückgenommen. Falls du inzwischen etwas anderes vorhast, ändere bitte deine Antwort.".
					$l->t('We have withdrawn the cancellation. If you have made other plans since, please update your response.')
				);
				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath('attendance', 'app-dark.svg')
				));

				return $notification;
			case 'appointment_updated':
				$parameters = $notification->getSubjectParameters();
				$appointmentName = (string)($parameters['name'] ?? 'Unknown');
				$appointmentDate = $this->formatDateForUser(
					(string)($parameters['startDatetime'] ?? ''),
					$notification->getUser()
				);
				$appointmentId = (int)($parameters['appointmentId'] ?? 0);
				$userId = $notification->getUser();

				$notification->setParsedSubject(
					// TRANSLATORS Push notification subject: details of an appointment the person already knows about have changed. %1$s is the appointment name, %2$s its new date.
					$l->t('Appointment changed: %1$s on %2$s', [$appointmentName, $appointmentDate])
				);
				$notification->setParsedMessage(
					$this->describeUpdate((array)($parameters['changed'] ?? []), $l)
				);
				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath('attendance', 'app-dark.svg')
				));

				// A moved appointment is exactly when someone needs to revise their answer.
				if ($appointmentId > 0) {
					$this->addQuickResponseActions($notification, $l, $appointmentId, $userId);
				}

				return $notification;
			case 'booking_confirmed':
			case 'booking_declined':
				$parameters = $notification->getSubjectParameters();
				$appointmentName = $parameters['name'] ?? 'Unknown';
				$appointmentDate = $this->formatDateForUser(
					$parameters['startDatetime'] ?? $parameters['date'] ?? '',
					$notification->getUser()
				);
				if ($notification->getSubject() === 'booking_confirmed') {
					$notification->setParsedSubject(
						// TRANSLATORS Push notification subject: the person got a place in the appointment ("scheduled in", German "eingeplant" — not "geplant": the appointment itself is not being planned). %1$s is the appointment name, %2$s the date.
						$l->t('You are scheduled for %1$s on %2$s', [$appointmentName, $appointmentDate])
					);
					$notification->setParsedMessage(
						// TRANSLATORS Push notification body, personal and friendly, shown under a subject that already says the person is scheduled (German "eingeplant", not "geplant") and names the appointment. More people volunteer than there are places, so the news is that this person got one and is expected to turn up. Sample German: "Du bist dabei — bitte halte dir den Termin frei. Danke für deine Antwort.".
						$l->t('You have a place, so please plan to be there. Thanks for answering.')
					);
				} else {
					$notification->setParsedSubject(
						// TRANSLATORS Push notification subject: the person did not get a place in the appointment this time (German "nicht eingeplant", not "nicht geplant"). %1$s is the appointment name, %2$s the date.
						$l->t('You are not scheduled for %1$s on %2$s', [$appointmentName, $appointmentDate])
					);
					$notification->setParsedMessage(
						// TRANSLATORS Push notification body, personal and friendly, shown under a subject that already says the person is not scheduled (German "nicht eingeplant", not "nicht geplant"). The app speaks as "we", the people organizing. The delicate one: being turned down reads as a judgement unless the reason is named, so keep the explanation that there were simply more volunteers than places. Sample German: "Diesmal hatten wir mehr Zusagen als Plätze. Danke für deine Antwort.".
						$l->t('We had more volunteers than places this time. Thanks for answering.')
					);
				}
				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath('attendance', 'app-dark.svg')
				));
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
				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath('attendance', 'app-dark.svg')
				));

				return $notification;
			case 'appointments_series_updated':
				$parameters = $notification->getSubjectParameters();
				$count = (int)($parameters['count'] ?? 0);
				$seriesName = (string)($parameters['name'] ?? '');

				$notification->setParsedSubject(
					// TRANSLATORS Push notification subject: several appointments of a recurring series moved at once. %1$s is how many, %2$s the name they share. Sample German: "1 Termin der Reihe „Probe" geändert" / "12 Termine der Reihe „Probe" geändert".
					$l->n('%1$s appointment changed in "%2$s"', '%1$s appointments changed in "%2$s"', $count, [$count, $seriesName])
				);
				$notification->setParsedMessage(
					$this->describeUpdate((array)($parameters['changed'] ?? []), $l)
				);
				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath('attendance', 'app-dark.svg')
				));

				return $notification;
			default:
				throw new UnknownNotificationException();
		}
	}

	private function prepareResponseChangeNotification(INotification $notification, \OCP\IL10N $l): INotification {
		$params = $notification->getSubjectParameters();
		$actor = (string)($params['actor'] ?? '');
		$subject = (string)($params['subject'] ?? '');
		$appointmentName = (string)($params['appointmentName'] ?? '');
		$from = (string)($params['from'] ?? '');
		$to = (string)($params['to'] ?? '');

		// Same collapse rule as the audit renderers: a subject only shows up
		// in the wording when someone answered on behalf of another person.
		$onBehalfOf = ($subject !== '' && $subject !== $actor) ? $subject : '';

		// TRANSLATORS: Stands in for a person's name in the response notifications below when the acting account cannot be identified. It is the grammatical subject of sentences like "Someone answered Yes on ...". Sample German: "Jemand".
		$actorLabel = $actor !== '' ? $this->resolveDisplayName($actor) : $l->t('Someone');
		$onBehalfOfLabel = $onBehalfOf !== '' ? $this->resolveDisplayName($onBehalfOf) : '';
		$fromLabel = $this->translateResponseValue($from, $l);
		$toLabel = $this->translateResponseValue($to, $l);

		switch ($notification->getSubject()) {
			case 'response_changed':
				$subject = $onBehalfOf !== ''
					// TRANSLATORS: Notification to an organizer. Someone edited another person's answer for them. %1$s is the display name of whoever made the edit, %2$s the display name of the person whose answer it is, %3$s and %4$s are the old and the new answer (each already translated as Yes, No or Maybe), %5$s the appointment name.
					// Sample German: "Anna Weber hat die Antwort von Bernd Klein auf „Probe" von Ja zu Nein geaendert".
					? $l->t('%1$s changed the response of %2$s from %3$s to %4$s on "%5$s"', [
						$actorLabel,
						$onBehalfOfLabel,
						$fromLabel,
						$toLabel,
						$appointmentName,
					])
					// TRANSLATORS: Notification to an organizer. Someone edited their own answer. %1$s is their display name, %2$s and %3$s are the old and the new answer (each already translated as Yes, No or Maybe), %4$s the appointment name. "Their" is a single person of unknown gender, not a group.
					// Sample German: "Anna Weber hat ihre Antwort auf „Probe" von Ja zu Nein geaendert".
					: $l->t('%1$s changed their response from %2$s to %3$s on "%4$s"', [
						$actorLabel,
						$fromLabel,
						$toLabel,
						$appointmentName,
					]);
				break;
			case 'response_rescinded':
				$subject = $onBehalfOf !== ''
					// TRANSLATORS: Notification to an organizer. Someone deleted another person's answer, leaving them unanswered again — this is not a No. %1$s is the display name of whoever deleted it, %2$s the display name of the person whose answer it was, %3$s the appointment name.
					// Sample German: "Anna Weber hat die Antwort von Bernd Klein auf „Probe" geloescht".
					? $l->t('%1$s removed the response of %2$s on "%3$s"', [
						$actorLabel,
						$onBehalfOfLabel,
						$appointmentName,
					])
					// TRANSLATORS: Notification to an organizer. Someone withdrew their own answer and now counts as unanswered again — this is not a No. %1$s is their display name, %2$s the appointment name. "Their" is a single person of unknown gender, not a group.
					// Sample German: "Anna Weber hat ihre Antwort auf „Probe" zurueckgezogen".
					: $l->t('%1$s took back their response on "%2$s"', [
						$actorLabel,
						$appointmentName,
					]);
				break;
			case 'response_submitted':
			default:
				$subject = $onBehalfOf !== ''
					// TRANSLATORS: Notification to an organizer. Someone answered on another person's behalf, e.g. a manager entering an answer a member gave by phone. %1$s is the display name of whoever entered it, %2$s the answer itself (already translated as Yes, No or Maybe), %3$s the display name of the person it is for, %4$s the appointment name.
					// Sample German: "Anna Weber hat fuer Bernd Klein mit Ja auf „Probe" geantwortet".
					? $l->t('%1$s answered %2$s for %3$s on "%4$s"', [
						$actorLabel,
						$toLabel,
						$onBehalfOfLabel,
						$appointmentName,
					])
					// TRANSLATORS: Notification to an organizer that someone answered for themselves. %1$s is their display name, %2$s the answer itself (already translated as Yes, No or Maybe), %3$s the appointment name.
					// Sample German: "Anna Weber hat mit Ja auf „Probe" geantwortet".
					: $l->t('%1$s answered %2$s on "%3$s"', [
						$actorLabel,
						$toLabel,
						$appointmentName,
					]);
				break;
		}

		$notification->setParsedSubject($subject);
		$notification->setIcon($this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath('attendance', 'app-dark.svg')
		));
		return $notification;
	}

	/**
	 * One complete sentence per change instead of a joined field list, which
	 * translators cannot reorder or inflect.
	 *
	 * @param array<array-key, mixed> $changedFields As stored in the subject parameters
	 */
	private function describeUpdate(array $changedFields, \OCP\IL10N $l): string {
		$timeChanged = in_array('time', $changedFields, true);
		$locationChanged = in_array('location', $changedFields, true);

		if ($timeChanged && $locationChanged) {
			// TRANSLATORS Push notification body for an edited appointment, shown under an "Appointment changed" or "N appointments changed" subject. Both when it takes place and where it takes place were edited. "Date" covers the day and the time of day, not a response deadline. "Your response" is the recipient's own yes/no/maybe answer: they are asked to check whether the answer they already gave still holds for the new date and place.
			return $l->t('Date and location have changed. Please check whether your response still fits.');
		}
		if ($timeChanged) {
			// TRANSLATORS Push notification body for an edited appointment, shown under an "Appointment changed" or "N appointments changed" subject. The appointment was moved to a different day or time of day — this is not about a response deadline. "Your response" is the recipient's own yes/no/maybe answer, which they are asked to re-check against the new date.
			return $l->t('The date has changed. Please check whether your response still fits.');
		}
		if ($locationChanged) {
			// TRANSLATORS Push notification body for an edited appointment, shown under an "Appointment changed" or "N appointments changed" subject. Only the place where the appointment happens was edited; the date and time are unchanged, which is why this sentence does not ask the recipient to re-check their answer.
			return $l->t('The location has changed.');
		}
		// TRANSLATORS Push notification body for an edited appointment, used when the notification does not name which detail was edited. Keep it as an unspecific nudge to open the appointment and look at it.
		return $l->t('Please check the appointment.');
	}

	/**
	 * Notifications carry raw user IDs; resolving them here rather than at send
	 * time keeps renamed accounts correct. Unknown accounts keep the ID.
	 */
	private function resolveDisplayName(string $userId): string {
		$user = $this->userManager->get($userId);
		return $user !== null ? $user->getDisplayName() : $userId;
	}

	/**
	 * The three answers, rendered for insertion into the response notification
	 * subjects above.
	 */
	private function translateResponseValue(string $value, \OCP\IL10N $l): string {
		switch ($value) {
			case 'yes':
				// TRANSLATORS: An attendee's answer, meaning "I will be there". Serves two purposes with one translation: it is the label of the Yes button on the notification, and it is substituted into sentences like "... answered Yes on ...". So it must work both as a standalone button and mid-sentence. Sample German: "Ja".
				return $l->t('Yes');
			case 'no':
				// TRANSLATORS: An attendee's answer, meaning "I will not be there". Serves two purposes with one translation: it is the label of the No button on the notification, and it is substituted into sentences like "... answered No on ...". So it must work both as a standalone button and mid-sentence. Sample German: "Nein".
				return $l->t('No');
			case 'maybe':
				// TRANSLATORS: An attendee's answer, meaning "I do not know yet". Serves two purposes with one translation: it is the label of the Maybe button on the notification, and it is substituted into sentences like "... answered Maybe on ...". So it must work both as a standalone button and mid-sentence. Sample German: "Vielleicht".
				return $l->t('Maybe');
			default:
				return $value;
		}
	}

	/**
	 * Format a UTC datetime string for display in the user's timezone.
	 */
	private function formatDateForUser(string $utcDatetime, string $userId): string {
		if ($utcDatetime === '') {
			return 'Unknown';
		}

		try {
			$userTimezone = $this->config->getUserValue($userId, 'core', 'timezone', '');
			if ($userTimezone === '') {
				$userTimezone = date_default_timezone_get();
			}
			$date = new \DateTime($utcDatetime, new \DateTimeZone('UTC'));
			$date->setTimezone(new \DateTimeZone($userTimezone));
			return $date->format('d.m.Y H:i');
		} catch (\Exception $e) {
			return $utcDatetime;
		}
	}

	/**
	 * Whether this appointment still offers "Maybe". A notification can outlive
	 * the appointment it points at, and an unknown one keeps all three buttons
	 * rather than silently dropping one.
	 */
	private function isMaybeOffered(int $appointmentId): bool {
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
