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
	public int $attendedDespiteNo = 0;

	/**
	 * @param ?string $answer yes/no/maybe, or null when unanswered
	 * @param ?string $checkin yes/no, or null when not recorded
	 * @param bool $countsForAttendance Whether the appointment is over and had check-ins at all
	 */
	public function record(?string $answer, ?string $checkin, bool $countsForAttendance): void {
		$this->targetCount++;

		match ($answer) {
			'yes' => $this->yes++,
			'no' => $this->no++,
			'maybe' => $this->maybe++,
			default => $this->noResponse++,
		};

		if (!$countsForAttendance) {
			return;
		}

		$this->attendanceBase++;

		if ($checkin === 'yes') {
			$this->present++;
			if ($answer === 'no') {
				$this->attendedDespiteNo++;
			}
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
		$this->attendedDespiteNo += $other->attendedDespiteNo;
	}

	/**
	 * @return array{targetCount: int, yes: int, no: int, maybe: int, noResponse: int, present: int, absent: int, notRecorded: int, attendanceBase: int, noShow: int, attendedDespiteNo: int, responseRate: ?float, acceptRate: ?float, attendanceRate: ?float}
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
			'attendedDespiteNo' => $this->attendedDespiteNo,
			'responseRate' => self::rate($this->yes + $this->no + $this->maybe, $this->targetCount),
			'acceptRate' => self::rate($this->yes, $this->targetCount),
			'attendanceRate' => self::rate($this->present, $this->attendanceBase),
		];
	}

	public static function rate(int $part, int $total): ?float {
		return $total > 0 ? round($part / $total, 4) : null;
	}
}
