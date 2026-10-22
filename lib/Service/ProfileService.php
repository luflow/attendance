<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

use OCA\Attendance\ResponseDefinitions;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\IAvatarManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Profile\IProfileManager;

/**
 * Name, picture and biography as Nextcloud's own account settings keep them,
 * so the web profile and the app always show the same thing.
 *
 * @psalm-import-type AttendanceOwnProfile from ResponseDefinitions
 * @psalm-import-type AttendanceUserProfile from ResponseDefinitions
 */
final class ProfileService {
	/** Core rejects longer display names. */
	private const MAX_DISPLAY_NAME_LENGTH = 64;

	public function __construct(
		private IAccountManager $accountManager,
		private IProfileManager $profileManager,
		private IAvatarManager $avatarManager,
		private IUserManager $userManager,
		private DirectorySearchService $directorySearchService,
	) {
	}

	/**
	 * @return AttendanceOwnProfile
	 */
	public function getOwnProfile(IUser $user): array {
		return [
			'userId' => $user->getUID(),
			'displayName' => $user->getDisplayName(),
			'biography' => $this->getBiography($user),
			'canChangeDisplayName' => $user->canChangeDisplayName(),
			'canChangeAvatar' => $user->canChangeAvatar(),
		];
	}

	/**
	 * A null field is left as it is.
	 *
	 * @return AttendanceOwnProfile
	 * @throws \InvalidArgumentException when a value is rejected
	 * @throws ProfileChangeNotAllowedException when the display name is read-only
	 */
	public function updateOwnProfile(IUser $user, ?string $displayName, ?string $biography): array {
		if ($displayName !== null) {
			$displayName = trim($displayName);
			if ($displayName === '') {
				throw new \InvalidArgumentException('Display name must not be empty');
			}
			if (mb_strlen($displayName) > self::MAX_DISPLAY_NAME_LENGTH) {
				throw new \InvalidArgumentException('Display name is too long');
			}
			if ($displayName !== $user->getDisplayName()) {
				if (!$user->canChangeDisplayName() || !$user->setDisplayName($displayName)) {
					throw new ProfileChangeNotAllowedException('Display name cannot be changed');
				}
			}
		}

		if ($biography !== null) {
			$account = $this->accountManager->getAccount($user);
			$account->getProperty(IAccountManager::PROPERTY_BIOGRAPHY)->setValue(trim($biography));
			$this->accountManager->updateAccount($account);
		}

		return $this->getOwnProfile($user);
	}

	/**
	 * @throws ProfileChangeNotAllowedException when the backend keeps avatars read-only
	 * @throws \InvalidArgumentException when the data is not an image
	 * @throws \Exception when core rejects the image
	 */
	public function setAvatar(IUser $user, string $imageData): void {
		if (!$user->canChangeAvatar()) {
			throw new ProfileChangeNotAllowedException('Avatar cannot be changed');
		}
		$square = $this->squared($imageData);
		$this->avatarManager->getAvatar($user->getUID())->set($square);
	}

	/**
	 * Core only takes square avatars, and a cropper can land a pixel off.
	 * Anything else is trimmed to its centre.
	 */
	private function squared(string $imageData): string {
		$size = @getimagesizefromstring($imageData);
		if ($size === false) {
			throw new \InvalidArgumentException('Data is not an image');
		}
		[$width, $height] = $size;
		if ($width === $height) {
			return $imageData;
		}

		$image = @imagecreatefromstring($imageData);
		if ($image === false) {
			throw new \InvalidArgumentException('Data is not an image');
		}
		$side = min($width, $height);
		$square = imagecrop($image, [
			'x' => intdiv($width - $side, 2),
			'y' => intdiv($height - $side, 2),
			'width' => $side,
			'height' => $side,
		]);
		if ($square === false) {
			throw new \InvalidArgumentException('Image could not be cropped');
		}
		ob_start();
		imagepng($square);
		return (string)ob_get_clean();
	}

	/**
	 * Built on the directory search, so Nextcloud's enumeration rules decide
	 * who shows up, and each person's profile visibility decides their bio.
	 *
	 * @return list<AttendanceUserProfile>
	 */
	public function listProfiles(IUser $visitor, string $search): array {
		$profiles = [];
		foreach ($this->directorySearchService->search($search, $visitor->getUID(), true) as $entry) {
			if ($entry['isGuest']) {
				continue;
			}
			$user = $this->userManager->get($entry['id']);
			if ($user === null) {
				continue;
			}
			// The visibility check is costly per user, and most have no bio to hide.
			$biography = $this->getBiography($user);
			$visible = $biography !== ''
				&& $this->profileManager->isProfileFieldVisible(IAccountManager::PROPERTY_BIOGRAPHY, $user, $visitor);
			$profiles[] = [
				'userId' => $user->getUID(),
				'displayName' => $user->getDisplayName(),
				'biography' => $visible ? $biography : null,
			];
		}
		return $profiles;
	}

	private function getBiography(IUser $user): string {
		try {
			return trim($this->accountManager->getAccount($user)->getProperty(IAccountManager::PROPERTY_BIOGRAPHY)->getValue());
		} catch (PropertyDoesNotExistException) {
			return '';
		}
	}
}
