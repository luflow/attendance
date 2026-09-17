<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\Db\Vacation;
use OCA\Attendance\Db\VacationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;

/**
 * Per-user vacation/absence periods. A vacation is a closed range of whole
 * days with an optional free-text reason, owned by the user who entered it —
 * every write here is scoped to that owner, there is no admin override.
 */
class VacationService {
	/** Reasons are shown to the whole team on the overview and the
	 * create-appointment hint, so keep the field bounded like any other
	 * shared text field. */
	private const NOTE_MAX_LENGTH = 500;

	private VacationMapper $vacationMapper;
	private IUserManager $userManager;

	public function __construct(VacationMapper $vacationMapper, IUserManager $userManager) {
		$this->vacationMapper = $vacationMapper;
		$this->userManager = $userManager;
	}

	/**
	 * @return list<Vacation>
	 */
	public function getForUser(string $userId): array {
		return $this->vacationMapper->findByUser($userId);
	}

	/**
	 * @throws \InvalidArgumentException When the dates are malformed or end is before start
	 */
	public function create(string $userId, string $startDate, string $endDate, ?string $note): Vacation {
		[$startDate, $endDate] = $this->validateRange($startDate, $endDate);

		$vacation = new Vacation();
		$vacation->setUserId($userId);
		$vacation->setStartDate($startDate);
		$vacation->setEndDate($endDate);
		$vacation->setNote($this->normalizeNote($note));
		$now = gmdate('Y-m-d H:i:s');
		$vacation->setCreatedAt($now);
		$vacation->setUpdatedAt($now);

		return $this->vacationMapper->insert($vacation);
	}

	/**
	 * @throws DoesNotExistException When the vacation does not exist or belongs to someone else
	 * @throws \InvalidArgumentException When the dates are malformed or end is before start
	 */
	public function update(int $id, string $userId, string $startDate, string $endDate, ?string $note): Vacation {
		$vacation = $this->findOwnedBy($id, $userId);
		[$startDate, $endDate] = $this->validateRange($startDate, $endDate);

		$vacation->setStartDate($startDate);
		$vacation->setEndDate($endDate);
		$vacation->setNote($this->normalizeNote($note));
		$vacation->setUpdatedAt(gmdate('Y-m-d H:i:s'));

		return $this->vacationMapper->update($vacation);
	}

	/**
	 * @throws DoesNotExistException When the vacation does not exist or belongs to someone else
	 */
	public function delete(int $id, string $userId): void {
		$vacation = $this->findOwnedBy($id, $userId);
		$this->vacationMapper->delete($vacation);
	}

	/**
	 * Which of $userIds have a vacation covering any part of the given
	 * datetime window — the check behind the auto-"no" response on
	 * appointment creation.
	 *
	 * @param list<string> $userIds
	 * @return list<string>
	 */
	public function findUsersOnVacation(string $startDatetime, string $endDatetime, array $userIds): array {
		if ($userIds === []) {
			return [];
		}

		[$startDate, $endDate] = $this->toDateRange($startDatetime, $endDatetime);
		$matches = [];
		foreach ($this->vacationMapper->findOverlapping($startDate, $endDate, $userIds) as $vacation) {
			$matches[$vacation->getUserId()] = true;
		}

		return array_keys($matches);
	}

	/**
	 * Which candidates can't attend a proposed appointment window, with
	 * enough detail for the "X people can't attend" hint (count + names).
	 *
	 * @param list<string> $userIds Candidate audience to check
	 * @return list<array{userId: string, displayName: string, startDate: string, endDate: string, note: ?string}>
	 */
	public function findConflicts(string $startDatetime, string $endDatetime, array $userIds): array {
		if ($userIds === []) {
			return [];
		}

		[$startDate, $endDate] = $this->toDateRange($startDatetime, $endDatetime);
		return $this->withDisplayNames($this->vacationMapper->findOverlapping($startDate, $endDate, $userIds));
	}

	/**
	 * The team-wide vacation calendar: every candidate's vacation periods
	 * overlapping [$fromDate, $toDate] (both Y-m-d).
	 *
	 * @param list<string> $userIds Candidate audience to scope the overview to
	 * @return list<array{userId: string, displayName: string, startDate: string, endDate: string, note: ?string}>
	 */
	public function getOverview(string $fromDate, string $toDate, array $userIds): array {
		if ($userIds === []) {
			return [];
		}

		return $this->withDisplayNames($this->vacationMapper->findOverlapping($fromDate, $toDate, $userIds));
	}

	/**
	 * @throws DoesNotExistException When the vacation does not exist or belongs to someone else
	 */
	private function findOwnedBy(int $id, string $userId): Vacation {
		$vacation = $this->vacationMapper->find($id);
		if ($vacation->getUserId() !== $userId) {
			// Same "not found" as a missing row: the API never confirms that
			// another user's vacation id exists.
			throw new DoesNotExistException('Vacation not found.');
		}
		return $vacation;
	}

	/**
	 * @return array{0: string, 1: string} [startDate, endDate], both Y-m-d
	 * @throws \InvalidArgumentException When either date is malformed or end is before start
	 */
	private function validateRange(string $startDate, string $endDate): array {
		$start = \DateTime::createFromFormat('!Y-m-d', $startDate);
		$end = \DateTime::createFromFormat('!Y-m-d', $endDate);
		if ($start === false || $end === false) {
			throw new \InvalidArgumentException('Dates must be in Y-m-d format.');
		}
		if ($end < $start) {
			throw new \InvalidArgumentException('End date must not be before start date.');
		}

		return [$start->format('Y-m-d'), $end->format('Y-m-d')];
	}

	private function normalizeNote(?string $note): ?string {
		if ($note === null) {
			return null;
		}
		$note = trim($note);
		if ($note === '') {
			return null;
		}
		return mb_substr($note, 0, self::NOTE_MAX_LENGTH);
	}

	/**
	 * @return array{0: string, 1: string} [startDate, endDate], both Y-m-d
	 */
	private function toDateRange(string $startDatetime, string $endDatetime): array {
		// Appointment datetimes are either 'Y-m-d H:i:s' (stored) or ISO
		// 8601 'Y-m-d\TH:i:s\Z' (from the client) — both start with the date.
		return [substr($startDatetime, 0, 10), substr($endDatetime, 0, 10)];
	}

	/**
	 * @param list<Vacation> $vacations
	 * @return list<array{userId: string, displayName: string, startDate: string, endDate: string, note: ?string}>
	 */
	private function withDisplayNames(array $vacations): array {
		return array_map(function (Vacation $vacation): array {
			$user = $this->userManager->get($vacation->getUserId());
			return [
				'userId' => $vacation->getUserId(),
				'displayName' => $user !== null ? $user->getDisplayName() : $vacation->getUserId(),
				'startDate' => $vacation->getStartDate(),
				'endDate' => $vacation->getEndDate(),
				'note' => $vacation->getNote(),
			];
		}, $vacations);
	}
}
