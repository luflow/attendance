<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Controller;

use OCA\Attendance\Controller\LicenseController;
use OCA\Attendance\Service\LicenseService;
use OCA\Attendance\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LicenseControllerTest extends TestCase {
	/** @var LicenseService|MockObject */
	private $licenseService;
	/** @var PermissionService|MockObject */
	private $permissionService;
	/** @var IUserSession|MockObject */
	private $userSession;
	private LicenseController $controller;

	protected function setUp(): void {
		$this->licenseService = $this->createMock(LicenseService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new LicenseController(
			'attendance',
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->permissionService,
			$this->licenseService,
		);
	}

	private function signIn(string $uid, bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('isAdmin')->with($uid)->willReturn($admin);
	}

	public function testStatusNeedsAnAdmin(): void {
		$this->signIn('member', false);
		$this->licenseService->expects($this->never())->method('getStatus');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->status()->getStatus());
	}

	public function testStatusNeedsALogin(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->licenseService->expects($this->never())->method('getStatus');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->status()->getStatus());
	}

	public function testStatusPassesRefreshOn(): void {
		$this->signIn('admin', true);
		$status = ['state' => 'no_devices', 'license' => null, 'checkedAt' => '2026-10-31T10:00:00Z'];
		$this->licenseService->expects($this->once())->method('getStatus')->with(true)->willReturn($status);

		$response = $this->controller->status(true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($status, $response->getData());
	}

	public function testProofEchoesTheValidNonce(): void {
		$this->licenseService->method('isValidProof')->with('abc')->willReturn(true);

		$response = $this->controller->proof('abc');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['nonce' => 'abc'], $response->getData());
	}

	public function testProofHidesAnInvalidNonce(): void {
		$this->licenseService->method('isValidProof')->willReturn(false);

		$response = $this->controller->proof('abc');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $response->getData());
	}
}
