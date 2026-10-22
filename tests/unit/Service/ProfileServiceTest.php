<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Service\DirectorySearchService;
use OCA\Attendance\Service\ProfileChangeNotAllowedException;
use OCA\Attendance\Service\ProfileService;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\IAvatar;
use OCP\IAvatarManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Profile\IProfileManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProfileServiceTest extends TestCase {
	/** @var IAccountManager|MockObject */
	private $accountManager;
	/** @var IProfileManager|MockObject */
	private $profileManager;
	/** @var IAvatarManager|MockObject */
	private $avatarManager;
	/** @var IUserManager|MockObject */
	private $userManager;
	/** @var DirectorySearchService|MockObject */
	private $directorySearch;
	private ProfileService $service;

	/** @var array<string, string> */
	private array $biographies = [];

	protected function setUp(): void {
		$this->accountManager = $this->createMock(IAccountManager::class);
		$this->profileManager = $this->createMock(IProfileManager::class);
		$this->avatarManager = $this->createMock(IAvatarManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->directorySearch = $this->createMock(DirectorySearchService::class);

		$this->accountManager->method('getAccount')->willReturnCallback(function (IUser $user): IAccount {
			$property = $this->createMock(IAccountProperty::class);
			$property->method('getValue')->willReturn($this->biographies[$user->getUID()] ?? '');
			$account = $this->createMock(IAccount::class);
			$account->method('getProperty')->with(IAccountManager::PROPERTY_BIOGRAPHY)->willReturn($property);
			return $account;
		});

		$this->service = new ProfileService(
			$this->accountManager,
			$this->profileManager,
			$this->avatarManager,
			$this->userManager,
			$this->directorySearch,
		);
	}

	/** @return IUser&MockObject */
	private function user(string $uid, string $displayName, bool $canChange = true): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);
		$user->method('canChangeDisplayName')->willReturn($canChange);
		$user->method('canChangeAvatar')->willReturn($canChange);
		return $user;
	}

	public function testOwnProfileCarriesBiographyAndEditability(): void {
		$this->biographies['alice'] = 'Alto since 2019';

		$profile = $this->service->getOwnProfile($this->user('alice', 'Alice', false));

		$this->assertSame([
			'userId' => 'alice',
			'displayName' => 'Alice',
			'biography' => 'Alto since 2019',
			'canChangeDisplayName' => false,
			'canChangeAvatar' => false,
		], $profile);
	}

	public function testUpdateTrimsAndStoresBiography(): void {
		$user = $this->user('alice', 'Alice');
		$property = $this->createMock(IAccountProperty::class);
		$property->expects($this->once())->method('setValue')->with('Hello');
		$account = $this->createMock(IAccount::class);
		$account->method('getProperty')->willReturn($property);
		$accountManager = $this->createMock(IAccountManager::class);
		$accountManager->method('getAccount')->willReturn($account);
		$accountManager->expects($this->once())->method('updateAccount')->with($account);
		$service = new ProfileService($accountManager, $this->profileManager, $this->avatarManager, $this->userManager, $this->directorySearch);

		$service->updateOwnProfile($user, null, "  Hello\n");
	}

	public function testUpdateRenamesUser(): void {
		$user = $this->user('alice', 'Alice');
		$user->expects($this->once())->method('setDisplayName')->with('Alice B.')->willReturn(true);

		$this->service->updateOwnProfile($user, ' Alice B. ', null);
	}

	public function testUnchangedDisplayNameIsNotWrittenEvenWhenLocked(): void {
		$user = $this->user('alice', 'Alice', false);
		$user->expects($this->never())->method('setDisplayName');

		$this->service->updateOwnProfile($user, 'Alice', null);
	}

	public function testLockedDisplayNameIsRejected(): void {
		$user = $this->user('alice', 'Alice', false);
		$user->expects($this->never())->method('setDisplayName');

		$this->expectException(ProfileChangeNotAllowedException::class);
		$this->service->updateOwnProfile($user, 'Bob', null);
	}

	public function testEmptyDisplayNameIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->updateOwnProfile($this->user('alice', 'Alice'), '   ', null);
	}

	public function testTooLongDisplayNameIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->updateOwnProfile($this->user('alice', 'Alice'), str_repeat('ä', 65), null);
	}

	private static function png(int $width, int $height): string {
		$image = imagecreatetruecolor($width, $height);
		ob_start();
		imagepng($image);
		return (string)ob_get_clean();
	}

	public function testSquareAvatarIsHandedToCoreUnchanged(): void {
		$png = self::png(4, 4);
		$avatar = $this->createMock(IAvatar::class);
		$avatar->expects($this->once())->method('set')->with($png);
		$this->avatarManager->method('getAvatar')->with('alice')->willReturn($avatar);

		$this->service->setAvatar($this->user('alice', 'Alice'), $png);
	}

	public function testNonSquareAvatarIsCroppedToItsCentre(): void {
		$avatar = $this->createMock(IAvatar::class);
		$avatar->expects($this->once())->method('set')->with($this->callback(
			static fn (string $data): bool => array_slice(getimagesizefromstring($data) ?: [], 0, 2) === [5, 5],
		));
		$this->avatarManager->method('getAvatar')->willReturn($avatar);

		$this->service->setAvatar($this->user('alice', 'Alice'), self::png(6, 5));
	}

	public function testNonImageAvatarIsRejected(): void {
		$this->avatarManager->expects($this->never())->method('getAvatar');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setAvatar($this->user('alice', 'Alice'), 'not an image');
	}

	public function testSetAvatarRespectsBackend(): void {
		$this->avatarManager->expects($this->never())->method('getAvatar');

		$this->expectException(ProfileChangeNotAllowedException::class);
		$this->service->setAvatar($this->user('alice', 'Alice', false), self::png(4, 4));
	}

	public function testListProfilesSkipsGuestsAndHidesInvisibleBios(): void {
		$visitor = $this->user('alice', 'Alice');
		$bob = $this->user('bob', 'Bob');
		$carla = $this->user('carla', 'Carla');
		$dana = $this->user('dana', 'Dana');
		$this->biographies = ['bob' => "Tenor\n", 'carla' => 'Secret'];
		$this->userManager->method('get')->willReturnMap([['bob', $bob], ['carla', $carla], ['dana', $dana]]);
		$this->directorySearch->method('search')->with('', 'alice', true)->willReturn([
			['id' => 'carla', 'label' => 'Carla', 'type' => 'user', 'icon' => 'icon-user', 'isGuest' => false],
			['id' => 'guest', 'label' => 'Guest', 'type' => 'user', 'icon' => 'icon-user', 'isGuest' => true],
			['id' => 'bob', 'label' => 'Bob', 'type' => 'user', 'icon' => 'icon-user', 'isGuest' => false],
			['id' => 'dana', 'label' => 'Dana', 'type' => 'user', 'icon' => 'icon-user', 'isGuest' => false],
		]);
		// Dana has no bio, so her visibility is never asked for.
		$this->profileManager->expects($this->exactly(2))->method('isProfileFieldVisible')
			->willReturnCallback(static fn (string $field, IUser $target): bool => $target->getUID() === 'bob');

		$profiles = $this->service->listProfiles($visitor, '');

		$this->assertSame([
			['userId' => 'carla', 'displayName' => 'Carla', 'biography' => null],
			['userId' => 'bob', 'displayName' => 'Bob', 'biography' => 'Tenor'],
			['userId' => 'dana', 'displayName' => 'Dana', 'biography' => null],
		], $profiles);
	}
}
