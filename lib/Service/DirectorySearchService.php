<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\ResponseDefinitions;
use OCP\App\IAppManager;
use OCP\Collaboration\Collaborators\ISearch as ICollaboratorSearch;
use OCP\IUserManager;
use OCP\Share\IShare;

/**
 * The users/groups/teams directory behind the audience and organizer
 * pickers, built on Nextcloud's sharee search so its enumeration rules apply.
 *
 * @psalm-import-type AttendanceDirectoryEntry from ResponseDefinitions
 */
class DirectorySearchService {
	public function __construct(
		private ICollaboratorSearch $collaboratorSearch,
		private IAppManager $appManager,
		private IUserManager $userManager,
		private GuestService $guestService,
	) {
	}

	/**
	 * @return list<AttendanceDirectoryEntry>
	 */
	public function search(string $query, string $currentUserId): array {
		$shareTypes = [
			IShare::TYPE_USER,
			IShare::TYPE_GROUP,
		];

		if ($this->appManager->isEnabledForUser('circles')) {
			$shareTypes[] = IShare::TYPE_CIRCLE;
		}

		[$searchResult] = $this->collaboratorSearch->search(
			$query,
			$shareTypes,
			false, // lookup
			// Bound the page so an empty/very-short query on a large directory
			// can't fan out to every user/group/team. 200 leaves plenty of
			// headroom for a typical org while keeping the response bounded.
			200,
			0
		);

		// ISearch::search() is untyped; its first element is the result page as an array.
		$page = is_array($searchResult) ? $searchResult : [];
		return $this->withCurrentUser($this->formatCollaboratorResults($page), $query, $currentUserId);
	}

	/**
	 * Nobody shares with themselves, so the sharee search drops the searching
	 * user — but an organizer may well attend their own appointment.
	 *
	 * @param list<AttendanceDirectoryEntry> $results
	 * @return list<AttendanceDirectoryEntry>
	 */
	private function withCurrentUser(array $results, string $query, string $currentUserId): array {
		$user = $this->userManager->get($currentUserId);
		if ($user === null) {
			return $results;
		}
		$needle = mb_strtolower(trim($query));
		$hits = array_filter(
			array_map(mb_strtolower(...), [$user->getUID(), $user->getDisplayName(), $user->getEMailAddress() ?? '']),
			static fn (string $name): bool => $name !== '' && str_contains($name, $needle),
		);
		if ($hits === []) {
			return $results;
		}
		foreach ($results as $result) {
			if ($result['type'] === 'user' && $result['id'] === $user->getUID()) {
				return $results;
			}
		}

		$self = $this->directoryEntry('user', $user->getUID(), $user->getDisplayName());
		// An exact hit leads, like the sharee search's own exact matches do.
		return in_array($needle, $hits, true) ? [$self, ...$results] : [...$results, $self];
	}

	/**
	 * @return AttendanceDirectoryEntry
	 */
	private function directoryEntry(string $type, string $id, string $label): array {
		return [
			'id' => $id,
			'label' => $label,
			'type' => $type,
			'icon' => $this->getIconForType($type),
			'isGuest' => $type === 'user' && $id !== '' && $this->guestService->isGuestUser($id),
		];
	}

	/**
	 * @return list<AttendanceDirectoryEntry>
	 */
	private function formatCollaboratorResults(array $searchResult): array {
		$results = [];
		foreach (['exact', 'users', 'groups', 'circles'] as $category) {
			$items = [];

			if ($category === 'exact') {
				// Exact matches are nested by type
				$exactMatches = $searchResult['exact'] ?? [];
				foreach (['users', 'groups', 'circles'] as $subCategory) {
					if (isset($exactMatches[$subCategory])) {
						$items = array_merge($items, $exactMatches[$subCategory]);
					}
				}
			} else {
				$items = $searchResult[$category] ?? [];
			}

			foreach ($items as $item) {
				$shareType = $item['value']['shareType'] ?? null;
				$type = $this->mapShareTypeToString($shareType);

				if ($type === null) {
					continue;
				}

				$id = (string)($item['value']['shareWith'] ?? $item['shareWith'] ?? '');
				$results[] = $this->directoryEntry($type, $id, (string)($item['label'] ?? ''));
			}
		}

		$seen = [];
		$uniqueResults = [];
		foreach ($results as $result) {
			$key = $result['type'] . ':' . $result['id'];
			if (!isset($seen[$key])) {
				$seen[$key] = true;
				$uniqueResults[] = $result;
			}
		}

		return $uniqueResults;
	}

	private function mapShareTypeToString(?int $shareType): ?string {
		return match ($shareType) {
			IShare::TYPE_USER => 'user',
			IShare::TYPE_GROUP => 'group',
			IShare::TYPE_CIRCLE => 'team',
			default => null,
		};
	}

	private function getIconForType(string $type): string {
		return match ($type) {
			'user' => 'icon-user',
			'group' => 'icon-group',
			'team' => 'icon-team',
			default => 'icon-user',
		};
	}
}
