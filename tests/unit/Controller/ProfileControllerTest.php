<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Controller;

use OCA\Attendance\Controller\ProfileController;
use OCA\Attendance\Service\DirectorySearchService;
use OCA\Attendance\Service\ProfileService;
use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Http;
use OCP\IAvatar;
use OCP\IAvatarManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Profile\IProfileManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProfileControllerTest extends TestCase {
	/** @var IAvatar|MockObject */
	private $avatar;
	/** @var IUserSession|MockObject */
	private $userSession;
	private ProfileController $controller;

	protected function setUp(): void {
		$this->avatar = $this->createMock(IAvatar::class);
		$avatarManager = $this->createMock(IAvatarManager::class);
		$avatarManager->method('getAvatar')->willReturn($this->avatar);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new ProfileController(
			'attendance',
			$this->createMock(IRequest::class),
			$this->userSession,
			new ProfileService(
				$this->createMock(IAccountManager::class),
				$this->createMock(IProfileManager::class),
				$avatarManager,
				$this->createMock(IUserManager::class),
				$this->createMock(DirectorySearchService::class),
			),
		);
	}

	private function signIn(bool $canChange = true): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');
		$user->method('canChangeDisplayName')->willReturn($canChange);
		$user->method('canChangeAvatar')->willReturn($canChange);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testAnonymousRequestsAreRejected(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->show()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->update('x')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->updateAvatar('eA==')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->index()->getStatus());
	}

	public function testUpdateMapsLockedFieldToForbidden(): void {
		$this->signIn(false);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->update('Bob')->getStatus());
	}

	public function testUpdateMapsInvalidValueToBadRequest(): void {
		$this->signIn();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->update('  ')->getStatus());
	}

	public function testUpdateAvatarDecodesBase64(): void {
		$this->signIn();
		$image = imagecreatetruecolor(2, 2);
		ob_start();
		imagepng($image);
		$png = (string)ob_get_clean();
		$this->avatar->expects($this->once())->method('set')->with($png);

		$response = $this->controller->updateAvatar(base64_encode($png));

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateAvatarRejectsInvalidBase64(): void {
		$this->signIn();
		$this->avatar->expects($this->never())->method('set');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->updateAvatar('not base64!')->getStatus());
	}

	public function testUpdateAvatarMapsLockedAvatarToForbidden(): void {
		$this->signIn(false);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->updateAvatar(base64_encode('x'))->getStatus());
	}

	public function testUpdateAvatarRejectsNonImage(): void {
		$this->signIn();
		$this->avatar->expects($this->never())->method('set');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->updateAvatar(base64_encode('x'))->getStatus());
	}

	public function testUpdateAvatarRejectsOversizedUploadBeforeDecoding(): void {
		$this->signIn();
		$this->avatar->expects($this->never())->method('set');

		$response = $this->controller->updateAvatar(str_repeat('A', 28 * 1024 * 1024));

		$this->assertSame(['error' => 'Image is too large'], $response->getData());
	}
}
