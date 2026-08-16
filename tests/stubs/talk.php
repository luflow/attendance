<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Psalm stubs for the parts of Nextcloud Talk that TalkRoomService touches.
 *
 * Talk is an optional dependency and exposes no public PHP API for
 * participants — `OCP\Talk\ITalkBackend` can only create a conversation with
 * moderators. Stubbing the internals keeps the call sites typed instead of
 * `mixed`, so a signature drift in a future Talk release surfaces as a psalm
 * error here rather than as suppressed noise.
 *
 * Verified against Talk stable32, stable33 and stable34. `addUsers()` and
 * `removeUser()` are identical across all three; `createConversation()` gained
 * `$attributes` mid-signature in stable34, which is why callers must use named
 * arguments.
 */

namespace OCA\Talk;

use OCA\Talk\Model\Attendee;

class Room {

	public function getToken(): string {
	}

	public function getObjectType(): string {
	}

	public function getObjectId(): string {
	}
}

class Participant {
	public function getAttendee(): Attendee {
	}
}

class Manager {
	public function getRoomByToken(string $token, ?string $preloadUserId = null, ?string $serverUrl = null): Room {
	}
}

namespace OCA\Talk\Model;

class Attendee {
	public function getActorType(): string {
	}

	public function getActorId(): string {
	}

	public function getParticipantType(): int {
	}

}

namespace OCA\Talk\Exceptions;

class RoomNotFoundException extends \Exception {
}

namespace OCA\Talk\Service;

use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCP\IUser;

class RoomService {
	public function createConversation(
		int $type,
		string $name,
		?IUser $owner = null,
		string $objectType = '',
		string $objectId = '',
		string $password = '',
		int $readOnly = 0,
		int $listable = 0,
		int $messageExpiration = 0,
		int $lobbyState = 0,
		?int $lobbyTimer = null,
		int $sipEnabled = 0,
		int $permissions = 0,
		int $recordingConsent = 0,
		int $mentionPermissions = 0,
		string $description = '',
		?string $emoji = null,
		?string $avatarColor = null,
	): Room {
	}

	public function setName(Room $room, string $newName, ?string $oldName = null, bool $validateType = false): void {
	}

	public function setDescription(Room $room, string $description): void {
	}

	public function setObject(Room $room, string $objectType, string $objectId): void {
	}
}

class ParticipantService {
	/**
	 * @param list<array{actorType: string, actorId: string, displayName: string}> $participants
	 * @return list<\OCA\Talk\Model\Attendee>
	 */
	public function addUsers(Room $room, array $participants, ?IUser $addedBy = null, bool $bansAlreadyChecked = false): array {
	}

	public function removeUser(Room $room, IUser $user, string $reason): void {
	}

	/**
	 * @return list<Participant>
	 */
	public function getParticipantsForRoom(Room $room): array {
	}

	public function updateParticipantType(Room $room, Participant $participant, int $participantType): void {
	}
}
