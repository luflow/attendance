<?php

declare(strict_types=1);

namespace OCA\Attendance\Notification;

use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Service\QuickResponseTokenService;
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
	private IUserManager $userManager;

	public function __construct(
		IFactory $l10nFactory,
		IURLGenerator $urlGenerator,
		QuickResponseTokenService $tokenService,
		IConfig $config,
		AttendanceResponseMapper $responseMapper,
		IUserManager $userManager,
	) {
		$this->l10nFactory = $l10nFactory;
		$this->urlGenerator = $urlGenerator;
		$this->tokenService = $tokenService;
		$this->config = $config;
		$this->responseMapper = $responseMapper;
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
					$l->t('Response missing: %1$s on %2$s', [$appointmentName, $appointmentDate])
				);
				$notification->setParsedMessage(
					$l->t('Please respond to the upcoming appointment!')
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
					$l->t('New appointment: %1$s on %2$s', [$appointmentName, $appointmentDate])
				);
				$notification->setParsedMessage(
					$l->t('A new appointment has been created. Please respond soon.')
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
					$l->t('Appointment cancelled: %1$s on %2$s', [$appointmentName, $appointmentDate])
				);
				$notification->setParsedMessage(
					$l->t('This appointment will not take place.')
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
					// TRANSLATORS Push notification body, shown under the "takes place after all" subject, which already names the appointment and its date. The organizer had cancelled this appointment and has now reinstated it. The second sentence is the point of the message: after hearing it was cancelled, the recipient may well have booked something else for that slot, so they are asked to correct their yes/no/maybe answer if it no longer holds. Keep the reassuring, slightly relieved tone.
					$l->t('The cancellation has been withdrawn and this appointment is going ahead as planned. If you have made other plans in the meantime, please update your response.')
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
						// TRANSLATORS Push notification body, personal and friendly — confirms the person got a place in the appointment (German "eingeplant", not "geplant") and thanks them for responding.
						$l->t('You are scheduled for this appointment. Thank you for your response!')
					);
				} else {
					$notification->setParsedSubject(
						// TRANSLATORS Push notification subject: the person did not get a place in the appointment this time (German "nicht eingeplant", not "nicht geplant"). %1$s is the appointment name, %2$s the date.
						$l->t('You are not scheduled for %1$s on %2$s', [$appointmentName, $appointmentDate])
					);
					$notification->setParsedMessage(
						// TRANSLATORS Push notification body, personal and friendly — gently tells the person they are not part of this appointment this time and thanks them for responding.
						$l->t('Unfortunately, you are not part of this appointment this time. Thank you for your response!')
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
					$l->n('%1$s new appointment added (e.g. "%2$s")', '%1$s new appointments added (e.g. "%2$s")', $count, [$count, $firstName])
				);
				$notification->setParsedMessage(
					$l->t('Please check the new appointments and respond.')
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
					// TRANSLATORS Push notification subject: several appointments of a recurring series moved at once. %1$s is how many, %2$s the name they share.
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

		$actorLabel = $actor !== '' ? $this->resolveDisplayName($actor) : $l->t('Someone');
		$onBehalfOfLabel = $onBehalfOf !== '' ? $this->resolveDisplayName($onBehalfOf) : '';
		$fromLabel = $this->translateResponseValue($from, $l);
		$toLabel = $this->translateResponseValue($to, $l);

		switch ($notification->getSubject()) {
			case 'response_changed':
				$subject = $onBehalfOf !== ''
					// TRANSLATORS: %1$s changes the answer on behalf of person %2$s; %3$s and %4$s are the old and the new answer (yes/no/maybe) and %5$s the appointment title.
					? $l->t('%1$s changed the response of %2$s from %3$s to %4$s on "%5$s"', [
						$actorLabel,
						$onBehalfOfLabel,
						$fromLabel,
						$toLabel,
						$appointmentName,
					])
					: $l->t('%1$s changed their response from %2$s to %3$s on "%4$s"', [
						$actorLabel,
						$fromLabel,
						$toLabel,
						$appointmentName,
					]);
				break;
			case 'response_rescinded':
				$subject = $onBehalfOf !== ''
					// TRANSLATORS: %1$s deletes the answer that person %2$s had given; %3$s is the appointment title.
					? $l->t('%1$s removed the response of %2$s on "%3$s"', [
						$actorLabel,
						$onBehalfOfLabel,
						$appointmentName,
					])
					: $l->t('%1$s took back their response on "%2$s"', [
						$actorLabel,
						$appointmentName,
					]);
				break;
			case 'response_submitted':
			default:
				$subject = $onBehalfOf !== ''
					// TRANSLATORS: %1$s answers on behalf of person %3$s; %2$s is the answer (yes/no/maybe) and %4$s the appointment title.
					? $l->t('%1$s answered %2$s for %3$s on "%4$s"', [
						$actorLabel,
						$toLabel,
						$onBehalfOfLabel,
						$appointmentName,
					])
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

	private function translateResponseValue(string $value, \OCP\IL10N $l): string {
		switch ($value) {
			case 'yes':
				return $l->t('Yes');
			case 'no':
				return $l->t('No');
			case 'maybe':
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

		// Maybe action (added second, displays middle)
		$maybeAction = $notification->createAction();
		$maybeAction->setLabel('maybe')
			->setParsedLabel($l->t('Maybe'))
			->setLink(
				$this->tokenService->generateQuickResponseUrl($userId, $appointmentId, 'maybe'),
				IAction::TYPE_WEB
			)
			->setPrimary(false);
		$notification->addParsedAction($maybeAction);

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
