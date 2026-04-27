<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Service;

use OCA\Attendance\Service\GuestService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class GuestServiceTest extends TestCase {
	/** @var IAppManager|MockObject */
	private $appManager;
	/** @var IAppConfig|MockObject */
	private $appConfig;
	/** @var IUserManager|MockObject */
	private $userManager;
	/** @var ContainerInterface|MockObject */
	private $container;
	/** @var LoggerInterface|MockObject */
	private $logger;

	private GuestService $service;

	protected function setUp(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new GuestService(
			$this->appManager,
			$this->appConfig,
			$this->userManager,
			$this->container,
			$this->logger,
		);
	}

	public function testIsGuestUserReturnsFalseWhenAppDisabled(): void {
		$this->appManager->method('isEnabledForUser')->with('guests')->willReturn(false);

		$this->assertFalse($this->service->isGuestUser('alice'));
	}

	public function testIsGuestUserReturnsFalseForEmptyId(): void {
		$this->appManager->expects($this->never())->method('isEnabledForUser');
		$this->assertFalse($this->service->isGuestUser(''));
	}

	public function testIsGuestUserCachesPositiveResult(): void {
		// Cache is keyed per uid → second call must not re-resolve the manager
		$this->appManager->method('isEnabledForUser')->with('guests')->willReturn(true);

		// resolveGuestManager() goes through container.get; since the Guests
		// app is not loaded in unit tests, class_exists returns false and the
		// manager is null → result is false but cached.
		$this->assertFalse($this->service->isGuestUser('alice'));

		// Second call returns cached value without consulting appManager again.
		$this->appManager->expects($this->never())->method('isEnabledForUser');
		$this->assertFalse($this->service->isGuestUser('alice'));
	}

	public function testIsGuestsWhitelistEnabledFalseWhenAppDisabled(): void {
		$this->appManager->method('isEnabledForUser')->with('guests')->willReturn(false);

		$this->assertFalse($this->service->isGuestsWhitelistEnabled());
	}

	public function testIsAttendanceInGuestsWhitelistFalseWhenAppDisabled(): void {
		$this->appManager->method('isEnabledForUser')->with('guests')->willReturn(false);

		$this->assertFalse($this->service->isAttendanceInGuestsWhitelist());
	}

	public function testIsAttendanceInGuestsWhitelistTrueWhenWhitelistDisabled(): void {
		$this->appManager->method('isEnabledForUser')->with('guests')->willReturn(true);
		$this->appConfig->method('getValueBool')
			->with('guests', 'usewhitelist', true)
			->willReturn(false);

		// Whitelist disabled → guests can use any app, so attendance counts as included.
		$this->assertTrue($this->service->isAttendanceInGuestsWhitelist());
	}

	public function testIsAttendanceInGuestsWhitelistDetectsExplicitListing(): void {
		$this->appManager->method('isEnabledForUser')->with('guests')->willReturn(true);
		$this->appConfig->method('getValueBool')
			->with('guests', 'usewhitelist', true)
			->willReturn(true);
		$this->appConfig->method('getValueString')
			->with('guests', 'whitelist', '')
			->willReturn('files,attendance,activity');

		$this->assertTrue($this->service->isAttendanceInGuestsWhitelist());
	}

	public function testIsAttendanceInGuestsWhitelistMissingFromList(): void {
		$this->appManager->method('isEnabledForUser')->with('guests')->willReturn(true);
		$this->appConfig->method('getValueBool')
			->with('guests', 'usewhitelist', true)
			->willReturn(true);
		$this->appConfig->method('getValueString')
			->with('guests', 'whitelist', '')
			->willReturn('files,activity,photos');

		$this->assertFalse($this->service->isAttendanceInGuestsWhitelist());
	}

	public function testCreateGuestRejectsInvalidEmail(): void {
		$creator = $this->createMock(IUser::class);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->createGuest($creator, 'not-an-email');
	}

	public function testCreateGuestThrowsWhenAppDisabled(): void {
		$creator = $this->createMock(IUser::class);
		$this->appManager->method('isEnabledForUser')->with('guests')->willReturn(false);

		$this->expectException(\RuntimeException::class);
		$this->service->createGuest($creator, 'guest@example.com');
	}

	public function testCreateGuestReturnsExistingUserIdempotently(): void {
		$creator = $this->createMock(IUser::class);
		$existing = $this->createMock(IUser::class);
		$existing->method('getUID')->willReturn('guest@example.com');
		$existing->method('getDisplayName')->willReturn('Existing Guest');
		$existing->method('getSystemEMailAddress')->willReturn('guest@example.com');

		$this->appManager->method('isEnabledForUser')->with('guests')->willReturn(true);
		$this->userManager->method('get')->with('guest@example.com')->willReturn($existing);

		$result = $this->service->createGuest($creator, 'guest@example.com', 'Ignored');

		$this->assertSame('guest@example.com', $result['userId']);
		$this->assertSame('Existing Guest', $result['displayName']);
		$this->assertTrue($result['alreadyExisted']);
	}
}
