<?php

declare(strict_types=1);

namespace OCA\Attendance\Activity;

use OCP\IConfig;
use OCP\IL10N;

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
	) {
	}

	/**
	 * @param array<array-key, mixed> $params
	 * @return array{subject: string, message: string}
	 */
	public function forCancelled(IL10N $l, array $params, string $userId): array {
		$appointmentName = (string)($params['name'] ?? 'Unknown');
		$appointmentDate = $this->formatDateForUser(
			(string)($params['startDatetime'] ?? $params['date'] ?? ''),
			$userId
		);

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
		$appointmentDate = $this->formatDateForUser(
			(string)($params['startDatetime'] ?? ''),
			$userId
		);

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
		$appointmentDate = $this->formatDateForUser(
			(string)($params['startDatetime'] ?? $params['date'] ?? ''),
			$userId
		);

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
	 * Format a UTC datetime string for display in the user's timezone.
	 */
	public function formatDateForUser(string $utcDatetime, string $userId): string {
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
}
