<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Psalm stubs for the parts of the Circles (Teams) app that VisibilityService
 * and MembershipRemovedListener touch. Circles is an optional dependency.
 *
 * Verified against Circles stable32, stable33 and stable34.
 */

namespace OCA\Circles;

use OCA\Circles\Model\Circle;

class CirclesManager {
	public function startSuperSession(bool $forceSync = false): void {
	}

	public function stopSession(): void {
	}

	public function getCircle(string $singleId): Circle {
	}
}

namespace OCA\Circles\Model;

class Circle {
	public function getDisplayName(): string {
	}

	/**
	 * Direct members only: a group or a nested team is one entry.
	 *
	 * @return list<Member>
	 */
	public function getMembers(int $limit = 0): array {
	}

	/**
	 * Everyone who belongs through a group or a nested team, resolved.
	 *
	 * @return list<Member>
	 */
	public function getInheritedMembers(bool $detailed = false, bool $remote = false): array {
	}
}

class Member {
	public const TYPE_USER = 1;

	public function getUserId(): string {
	}

	public function getUserType(): int {
	}
}

namespace OCA\Circles\Events;

use OCA\Circles\Model\Member;
use OCP\EventDispatcher\Event;

class CircleMemberRemovedEvent extends Event {
	public function getMember(): Member {
	}
}
