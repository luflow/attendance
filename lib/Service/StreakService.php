<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Db\AttendanceResponseMapper;
use OCA\Attendance\Db\Streak;
use OCA\Attendance\Db\StreakMapper;
use OCP\IUserManager;

class StreakService {
	private StreakMapper $streakMapper;
	private AppointmentMapper $appointmentMapper;
	private AttendanceResponseMapper $responseMapper;
	private VisibilityService $visibilityService;
	private IUserManager $userManager;

	public function __construct(
		StreakMapper $streakMapper,
		AppointmentMapper $appointmentMapper,
		AttendanceResponseMapper $responseMapper,
		VisibilityService $visibilityService,
		IUserManager $userManager,
	) {
		$this->streakMapper = $streakMapper;
		$this->appointmentMapper = $appointmentMapper;
		$this->responseMapper = $responseMapper;
		$this->visibilityService = $visibilityService;
		$this->userManager = $userManager;
	}

	/**
	 * Get the cached streak for a user.
	 *
	 * @param string $userId
	 * @return Streak
	 */
	public function getUserStreak(string $userId): Streak {
		return $this->streakMapper->findOrCreateByUser($userId);
	}

	/**
	 * Recalculate the streak for a user from scratch.
	 *
	 * Rules:
	 * - RSVP yes + checkin yes → +1
	 * - RSVP yes + no checkin performed (appointment has no checkins at all) → +1
	 * - RSVP no (excused) → skip
	 * - RSVP yes + checkin no → breaks
	 * - No response + past appointment → breaks
	 * - RSVP maybe + not attended → breaks
	 * - Appointment not visible to user → skip
	 *
	 * @param string $userId
	 * @return Streak
	 */
	public function recalculateStreak(string $userId): Streak {
		$pastAppointments = $this->appointmentMapper->findPast();

		// Sort chronologically oldest first
		usort($pastAppointments, function ($a, $b) {
			return strcmp($a->getStartDatetime(), $b->getStartDatetime());
		});

		// Build response map: appointmentId => AttendanceResponse
		$responses = $this->responseMapper->findByUser($userId);
		$responseMap = [];
		foreach ($responses as $response) {
			$responseMap[$response->getAppointmentId()] = $response;
		}

		// Build checkin-performed map: which appointments have any checkins at all
		$checkinPerformedMap = $this->buildCheckinPerformedMap($pastAppointments);

		$currentStreak = 0;
		$longestStreak = 0;
		$streakStartDate = null;
		$longestStreakDate = null;
		$tempStreakStart = null;

		foreach ($pastAppointments as $appointment) {
			// Skip if user is not a target attendee
			if (!$this->visibilityService->isUserTargetAttendee($appointment, $userId)) {
				continue;
			}

			$appointmentId = $appointment->getId();
			$response = $responseMap[$appointmentId] ?? null;
			$checkinPerformed = $checkinPerformedMap[$appointmentId] ?? false;

			$result = $this->evaluateAppointment($response, $checkinPerformed);

			if ($result === 'attend') {
				$currentStreak++;
				if ($tempStreakStart === null) {
					$tempStreakStart = $appointment->getStartDatetime();
				}
				if ($currentStreak > $longestStreak) {
					$longestStreak = $currentStreak;
					$longestStreakDate = $appointment->getStartDatetime();
				}
			} elseif ($result === 'break') {
				$currentStreak = 0;
				$tempStreakStart = null;
			}
			// 'skip' → no change
		}

		$streakStartDate = $tempStreakStart;

		// Persist
		$streak = $this->streakMapper->findOrCreateByUser($userId);
		$streak->setCurrentStreak($currentStreak);
		$streak->setLongestStreak($longestStreak);
		$streak->setStreakStartDate($streakStartDate);
		$streak->setLongestStreakDate($longestStreakDate);
		$streak->setLastCalculatedAt((new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'));

		return $this->streakMapper->update($streak);
	}

	/**
	 * Get top streaks with user display names.
	 *
	 * @param int $limit
	 * @return array
	 */
	public function getTopStreaks(int $limit = 10): array {
		$streaks = $this->streakMapper->findTopStreaks($limit);
		$result = [];

		foreach ($streaks as $streak) {
			$user = $this->userManager->get($streak->getUserId());
			$displayName = $user ? $user->getDisplayName() : $streak->getUserId();

			$result[] = [
				'userId' => $streak->getUserId(),
				'displayName' => $displayName,
				'currentStreak' => $streak->getCurrentStreak(),
				'longestStreak' => $streak->getLongestStreak(),
				'level' => $streak->getStreakLevel(),
			];
		}

		return $result;
	}

	/**
	 * Evaluate a single appointment for streak calculation.
	 *
	 * @param \OCA\Attendance\Db\AttendanceResponse|null $response
	 * @param bool $checkinPerformed Whether any checkins were performed for this appointment
	 * @return string 'attend'|'break'|'skip'
	 */
	private function evaluateAppointment($response, bool $checkinPerformed): string {
		if ($response === null) {
			return 'break';
		}

		$rsvp = $response->getResponse();
		$checkinState = $response->getCheckinState();
		$isCheckedInYes = ($checkinState === 'yes');

		if ($rsvp === 'no') {
			return 'skip';
		}

		if ($rsvp === 'yes') {
			if (!$checkinPerformed) {
				return 'attend';
			}
			return $isCheckedInYes ? 'attend' : 'break';
		}

		if ($rsvp === 'maybe') {
			return $isCheckedInYes ? 'attend' : 'break';
		}

		return 'break';
	}

	/**
	 * Build a map of appointment IDs to whether checkins were performed.
	 * Uses a single batch query instead of per-appointment queries.
	 *
	 * @param array $appointments
	 * @return array<int, bool>
	 */
	private function buildCheckinPerformedMap(array $appointments): array {
		$appointmentIds = array_map(fn($a) => $a->getId(), $appointments);
		$idsWithCheckins = $this->responseMapper->findAppointmentsWithCheckins($appointmentIds);
		$checkinSet = array_flip($idsWithCheckins);

		$map = [];
		foreach ($appointmentIds as $id) {
			$map[$id] = isset($checkinSet[$id]);
		}

		return $map;
	}
}
