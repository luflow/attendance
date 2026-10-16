<?php

declare(strict_types=1);

namespace OCA\Attendance\Activity;

use OCA\Attendance\Db\Appointment;
use OCA\Attendance\Db\AuditEvent;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Renders the text for the notification types that can be delivered either
 * directly (OCP\Notification, when the "activity" app is disabled) or via
 * OCP\Activity\IManager::bulkPublish() (when it is enabled). Both Notifier
 * and Provider call these so the wording — and its TRANSLATORS comments —
 * lives in exactly one place.
 */
final class EventMessages {
	public function __construct(
		private IConfig $config,
		private IUserManager $userManager,
	) {
	}

	/**
	 * @param array<array-key, mixed> $params
	 * @return array{subject: string, message: string}
	 */
	public function forCancelled(IL10N $l, array $params, string $userId): array {
		$appointmentName = (string)($params['name'] ?? 'Unknown');
		$appointmentDate = $this->formatAppointmentDate($params, $userId);

		return [
			// TRANSLATORS: Notification subject: the appointment was called off and will not happen (German "abgesagt", not "abgebrochen"). %1$s is the appointment name, %2$s the date it would have taken place. Sample German: "Termin abgesagt: „Probe" am 12.09.2026 18:00".
			'subject' => $l->t('Appointment cancelled: %1$s on %2$s', [$appointmentName, $appointmentDate]),
			// TRANSLATORS Notification body for a called-off appointment (German "abgesagt", not "abgebrochen"), shown under an "Appointment cancelled" subject that already names it and its date. The second half is the useful part: nothing is left to do and the slot in the calendar is free again. Sample German: "Der Termin fällt aus, du hast die Zeit wieder frei.".
			'message' => $l->t('It will not take place, so the time is yours again.'),
		];
	}

	/**
	 * @param array<array-key, mixed> $params
	 * @return array{subject: string, message: string}
	 */
	public function forReactivated(IL10N $l, array $params, string $userId): array {
		$appointmentName = (string)($params['name'] ?? 'Unknown');
		$appointmentDate = $this->formatAppointmentDate($params, $userId);

		return [
			// TRANSLATORS Notification subject: a cancelled appointment is back on. %1$s is the appointment name, %2$s the date.
			'subject' => $l->t('Appointment takes place after all: %1$s on %2$s', [$appointmentName, $appointmentDate]),
			// TRANSLATORS Notification body, shown under the "takes place after all" subject, which already names the appointment and its date. The app speaks as "we", the people organizing. The second sentence is the point: someone who was told it was off has likely booked that slot for something else, so their old yes/no/maybe answer may no longer hold. Sample German: "Wir haben die Absage zurückgenommen. Falls du inzwischen etwas anderes vorhast, ändere bitte deine Antwort.".
			'message' => $l->t('We have withdrawn the cancellation. If you have made other plans since, please update your response.'),
		];
	}

	/**
	 * @param array<array-key, mixed> $params
	 * @return array{subject: string, message: string}
	 */
	public function forBookingConfirmed(IL10N $l, array $params, string $userId): array {
		return $this->forBooking($l, $params, $userId, true);
	}

	/**
	 * @param array<array-key, mixed> $params
	 * @return array{subject: string, message: string}
	 */
	public function forBookingDeclined(IL10N $l, array $params, string $userId): array {
		return $this->forBooking($l, $params, $userId, false);
	}

	/**
	 * @param array<array-key, mixed> $params
	 * @return array{subject: string, message: string}
	 */
	private function forBooking(IL10N $l, array $params, string $userId, bool $confirmed): array {
		$appointmentName = (string)($params['name'] ?? 'Unknown');
		$appointmentDate = $this->formatAppointmentDate($params, $userId);

		if ($confirmed) {
			return [
				// TRANSLATORS Notification subject: the person got a place in the appointment ("scheduled in", German "eingeplant" — not "geplant": the appointment itself is not being planned). %1$s is the appointment name, %2$s the date.
				'subject' => $l->t('You are scheduled for %1$s on %2$s', [$appointmentName, $appointmentDate]),
				// TRANSLATORS Notification body, personal and friendly, shown under a subject that already says the person is scheduled (German "eingeplant", not "geplant") and names the appointment. More people volunteer than there are places, so the news is that this person got one and is expected to turn up. Sample German: "Du bist dabei — bitte halte dir den Termin frei. Danke für deine Antwort.".
				'message' => $l->t('You have a place, so please plan to be there. Thanks for answering.'),
			];
		}

		return [
			// TRANSLATORS Notification subject: the person did not get a place in the appointment this time (German "nicht eingeplant", not "nicht geplant"). %1$s is the appointment name, %2$s the date.
			'subject' => $l->t('You are not scheduled for %1$s on %2$s', [$appointmentName, $appointmentDate]),
			// TRANSLATORS Notification body, personal and friendly, shown under a subject that already says the person is not scheduled (German "nicht eingeplant", not "nicht geplant"). The app speaks as "we", the people organizing. The delicate one: being turned down reads as a judgement unless the reason is named, so keep the explanation that there were simply more volunteers than places. Sample German: "Diesmal hatten wir mehr Zusagen als Plätze. Danke für deine Antwort.".
			'message' => $l->t('We had more volunteers than places this time. Thanks for answering.'),
		];
	}

	/**
	 * @param array<array-key, mixed> $params
	 * @return array{subject: string, message: string}
	 */
	public function forSeriesUpdated(IL10N $l, array $params): array {
		$count = (int)($params['count'] ?? 0);
		$seriesName = (string)($params['name'] ?? '');

		return [
			// TRANSLATORS Notification subject: several appointments of a recurring series moved at once. %1$s is how many, %2$s the name they share. Sample German: "1 Termin der Reihe „Probe" geändert" / "12 Termine der Reihe „Probe" geändert".
			'subject' => $l->n('%1$s appointment changed in "%2$s"', '%1$s appointments changed in "%2$s"', $count, [$count, $seriesName]),
			'message' => $this->describeUpdate((array)($params['changed'] ?? []), $l),
		];
	}

	/**
	 * One complete sentence per change instead of a joined field list, which
	 * translators cannot reorder or inflect.
	 *
	 * @param array<array-key, mixed> $changedFields As stored in the subject parameters
	 */
	public function describeUpdate(array $changedFields, IL10N $l): string {
		$timeChanged = in_array('time', $changedFields, true);
		$locationChanged = in_array('location', $changedFields, true);

		if ($timeChanged && $locationChanged) {
			// TRANSLATORS Notification body for an edited appointment, shown under an "Appointment changed" or "N appointments changed" subject. Both when it takes place and where it takes place were edited. "Date" covers the day and the time of day, not a response deadline. "Your response" is the recipient's own yes/no/maybe answer: they are asked to check whether the answer they already gave still holds for the new date and place.
			return $l->t('Date and location have changed. Please check whether your response still fits.');
		}
		if ($timeChanged) {
			// TRANSLATORS Notification body for an edited appointment, shown under an "Appointment changed" or "N appointments changed" subject. The appointment was moved to a different day or time of day — this is not about a response deadline. "Your response" is the recipient's own yes/no/maybe answer, which they are asked to re-check against the new date.
			return $l->t('The date has changed. Please check whether your response still fits.');
		}
		if ($locationChanged) {
			// TRANSLATORS Notification body for an edited appointment, shown under an "Appointment changed" or "N appointments changed" subject. Only the place where the appointment happens was edited; the date and time are unchanged, which is why this sentence does not ask the recipient to re-check their answer.
			return $l->t('The location has changed.');
		}
		// TRANSLATORS Notification body for an edited appointment, used when the notification does not name which detail was edited. Keep it as an unspecific nudge to open the appointment and look at it.
		return $l->t('Please check the appointment.');
	}

	/**
	 * The date of the appointment a notification's subject parameters name.
	 *
	 * @param array<array-key, mixed> $params
	 */
	public function formatAppointmentDate(array $params, string $userId): string {
		return $this->formatDateForUser(
			(string)($params['startDatetime'] ?? $params['date'] ?? ''),
			$userId,
			(bool)($params['isAllDay'] ?? false),
		);
	}

	/**
	 * Format a UTC datetime string for display in the user's timezone; an
	 * all-day appointment is named by its first day alone.
	 */
	public function formatDateForUser(string $utcDatetime, string $userId, bool $allDay = false): string {
		if ($utcDatetime === '') {
			return 'Unknown';
		}

		try {
			$userTimezone = $this->config->getUserValue($userId, 'core', 'timezone', '');
			if ($userTimezone === '') {
				$userTimezone = date_default_timezone_get();
			}
			$date = ($allDay ? Appointment::allDayAnchor($utcDatetime) : new \DateTimeImmutable($utcDatetime, new \DateTimeZone('UTC')))
				->setTimezone(new \DateTimeZone($userTimezone));
			return $date->format($allDay ? 'd.m.Y' : 'd.m.Y H:i');
		} catch (\Exception $e) {
			return $utcDatetime;
		}
	}

	/**
	 * The subject parameters forResponseChange() reads, taken from a response
	 * audit event.
	 *
	 * @return array<string, mixed>
	 */
	public static function responseParameters(AuditEvent $event, string $appointmentName): array {
		$meta = $event->getMetaArray();
		return [
			'appointmentId' => $event->getAppointmentId(),
			'appointmentName' => $appointmentName,
			'actor' => $event->getActorId() ?? '',
			// Shipped verbatim like the audit API does — the Notifier collapses
			// subject == actor into the self wording, same as the audit renderers.
			'subject' => $event->getSubjectId() ?? '',
			'from' => (string)($meta['from'] ?? ''),
			'to' => (string)($meta['to'] ?? ($meta['response'] ?? '')),
		];
	}

	/**
	 * A response given, changed, withdrawn, commented on or moved up off the
	 * waitlist, as told to the organizers — by push (Notifier) and in the
	 * activity stream (Provider).
	 * The message is the comment, when the event carries one.
	 *
	 * @param array<array-key, mixed> $params
	 * @return array{subject: string, message: string}
	 */
	public function forResponseChange(IL10N $l, string $type, array $params): array {
		$actor = (string)($params['actor'] ?? '');
		$subjectId = (string)($params['subject'] ?? '');
		$appointmentName = (string)($params['appointmentName'] ?? '');
		$from = (string)($params['from'] ?? '');
		$to = (string)($params['to'] ?? '');

		// Same collapse rule as the audit renderers: a subject only shows up
		// in the wording when someone answered on behalf of another person.
		$onBehalfOf = ($subjectId !== '' && $subjectId !== $actor) ? $subjectId : '';

		// TRANSLATORS: Stands in for a person's name in the response notifications below when the acting account cannot be identified. It is the grammatical subject of sentences like "Someone answered Yes on ...". Sample German: "Jemand".
		$actorLabel = $actor !== '' ? $this->resolveDisplayName($actor) : $l->t('Someone');
		$onBehalfOfLabel = $onBehalfOf !== '' ? $this->resolveDisplayName($onBehalfOf) : '';
		$fromLabel = $this->translateResponseValue($from, $l);
		$toLabel = $this->translateResponseValue($to, $l);

		switch ($type) {
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
			case 'response_comment_updated':
				$subject = $onBehalfOf !== ''
					// TRANSLATORS: Activity entry for an organizer. Someone changed the comment another person left with their answer, e.g. a manager noting what a member said by phone; the answer itself stayed the same. %1$s is the display name of whoever made the edit, %2$s the display name of the person whose answer it is, %3$s the appointment name. The comment itself is shown below this line.
					// Sample German: "Anna Weber hat den Kommentar von Bernd Klein zu „Probe" geändert".
					? $l->t('%1$s changed the comment of %2$s on "%3$s"', [
						$actorLabel,
						$onBehalfOfLabel,
						$appointmentName,
					])
					// TRANSLATORS: Activity entry for an organizer. Someone changed the comment they left with their answer; the answer itself stayed the same. %1$s is their display name, %2$s the appointment name. The comment itself is shown below this line. "Their" is a single person of unknown gender, not a group.
					// Sample German: "Anna Weber hat ihren Kommentar zu „Probe" geändert".
					: $l->t('%1$s changed their comment on "%2$s"', [
						$actorLabel,
						$appointmentName,
					]);
				break;
			case 'waitlist_promoted':
				// TRANSLATORS: Activity entry for an organizer. A spot came free and the next person on the waitlist got it automatically — nobody clicked anything. %1$s is that person's display name, %2$s the appointment name.
				// Sample German: "Anna Weber ist bei „Probe" von der Warteliste auf einen frei gewordenen Platz nachgerückt".
				$subject = $l->t('%1$s moved off the waitlist into a free spot on "%2$s"', [
					$onBehalfOf !== '' ? $onBehalfOfLabel : $actorLabel,
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

		return ['subject' => $subject, 'message' => (string)($params['comment'] ?? '')];
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
	private function translateResponseValue(string $value, IL10N $l): string {
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
}
