<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

/**
 * The counters behind one row of the statistics — a person, a group, or the
 * totals. Typed rather than an array because the same block is accumulated,
 * merged and serialized in three different places.
 */
final class StatisticsTally {
	public int $targetCount = 0;
	public int $yes = 0;
	public int $no = 0;
	public int $maybe = 0;
	public int $noResponse = 0;
	public int $present = 0;
	public int $absent = 0;
	public int $notRecorded = 0;
	public int $attendanceBase = 0;
	public int $noShow = 0;
	public int $scheduled = 0;
	public int $notScheduled = 0;
	public int $schedulingBase = 0;

	/**
	 * @param ?string $answer yes/no/maybe, or null when unanswered
	 * @param ?string $checkin yes/no, or null when not recorded
	 * @param bool $countsForAttendance Whether the appointment is over and had check-ins at all
	 * @param bool $countsForScheduling Whether the inquiry is closed and somebody got a place
	 * @param bool $isScheduled Whether this person got one
	 */
	public function record(
		?string $answer,
		?string $checkin,
		bool $countsForAttendance,
		bool $countsForScheduling,
		bool $isScheduled,
	): void {
		$this->targetCount++;

		match ($answer) {
			'yes' => $this->yes++,
			'no' => $this->no++,
			'maybe' => $this->maybe++,
			default => $this->noResponse++,
		};

		// Only a yes can be scheduled, and only a closed inquiry that gave
		// somebody a place has decided anything — see BookingService.
		if ($countsForScheduling && $answer === 'yes') {
			$this->schedulingBase++;
			$isScheduled ? $this->scheduled++ : $this->notScheduled++;
		}

		if (!$countsForAttendance) {
			return;
		}

		$this->attendanceBase++;

		if ($checkin === 'yes') {
			$this->present++;
		} elseif ($checkin === 'no') {
			$this->absent++;
			if ($answer === 'yes') {
				$this->noShow++;
			}
		} else {
			$this->notRecorded++;
		}
	}

	public function add(self $other): void {
		$this->targetCount += $other->targetCount;
		$this->yes += $other->yes;
		$this->no += $other->no;
		$this->maybe += $other->maybe;
		$this->noResponse += $other->noResponse;
		$this->present += $other->present;
		$this->absent += $other->absent;
		$this->notRecorded += $other->notRecorded;
		$this->attendanceBase += $other->attendanceBase;
		$this->noShow += $other->noShow;
		$this->scheduled += $other->scheduled;
		$this->notScheduled += $other->notScheduled;
		$this->schedulingBase += $other->schedulingBase;
	}

	/**
	 * @return array{targetCount: int, yes: int, no: int, maybe: int, noResponse: int, present: int, absent: int, notRecorded: int, attendanceBase: int, noShow: int, scheduled: int, notScheduled: int, schedulingBase: int, responseRate: ?float, acceptRate: ?float, attendanceRate: ?float, scheduledRate: ?float}
	 */
	public function toArray(): array {
		return [
			'targetCount' => $this->targetCount,
			'yes' => $this->yes,
			'no' => $this->no,
			'maybe' => $this->maybe,
			'noResponse' => $this->noResponse,
			'present' => $this->present,
			'absent' => $this->absent,
			'notRecorded' => $this->notRecorded,
			'attendanceBase' => $this->attendanceBase,
			'noShow' => $this->noShow,
			'scheduled' => $this->scheduled,
			'notScheduled' => $this->notScheduled,
			'schedulingBase' => $this->schedulingBase,
			'responseRate' => self::rate($this->yes + $this->no + $this->maybe, $this->targetCount),
			'acceptRate' => self::rate($this->yes, $this->targetCount),
			'attendanceRate' => self::rate($this->present, $this->attendanceBase),
			'scheduledRate' => self::rate($this->scheduled, $this->schedulingBase),
		];
	}

	public static function rate(int $part, int $total): ?float {
		return $total > 0 ? round($part / $total, 4) : null;
	}
}
