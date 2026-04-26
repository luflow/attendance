<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

/**
 * Three-state instruction for updating an appointment's response deadline:
 * leave it untouched, clear it, or set it to a new value. Replaces the
 * tri-state `?string` (null = unchanged, '' = clear, value = set) that
 * was easy to misread at call sites.
 */
final class DeadlineUpdate {
	private const MODE_UNCHANGED = 0;
	private const MODE_CLEAR = 1;
	private const MODE_SET = 2;

	private int $mode;
	private ?string $value;

	private function __construct(int $mode, ?string $value) {
		$this->mode = $mode;
		$this->value = $value;
	}

	public static function unchanged(): self {
		return new self(self::MODE_UNCHANGED, null);
	}

	public static function clear(): self {
		return new self(self::MODE_CLEAR, null);
	}

	public static function set(string $value): self {
		return new self(self::MODE_SET, $value);
	}

	/**
	 * Build from the wire-level `?string` controllers receive:
	 * null = unchanged, '' = clear, otherwise = set.
	 */
	public static function fromWire(?string $raw): self {
		if ($raw === null) {
			return self::unchanged();
		}
		if ($raw === '') {
			return self::clear();
		}
		return self::set($raw);
	}

	public function isUnchanged(): bool {
		return $this->mode === self::MODE_UNCHANGED;
	}

	public function isClear(): bool {
		return $this->mode === self::MODE_CLEAR;
	}

	public function isSet(): bool {
		return $this->mode === self::MODE_SET;
	}

	public function value(): ?string {
		return $this->value;
	}
}
