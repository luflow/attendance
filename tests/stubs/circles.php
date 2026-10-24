<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Psalm stubs for the parts of the Circles (Teams) app that
 * MembershipRemovedListener touches. Circles is an optional dependency;
 * the member model matches what VisibilityService reads off a circle.
 */

namespace OCA\Circles\Model;

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
