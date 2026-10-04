<?php

declare(strict_types=1);

namespace OCA\Attendance\Tests\Unit\Controller;

use OCA\Attendance\Controller\CategoryController;
use OCA\Attendance\Db\Category;
use OCA\Attendance\Service\CategoryService;
use OCA\Attendance\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CategoryControllerTest extends TestCase {
	/** @var CategoryService|MockObject */
	private $categoryService;

	/** @var PermissionService|MockObject */
	private $permissionService;

	/** @var IUserSession|MockObject */
	private $userSession;

	private CategoryController $controller;

	protected function setUp(): void {
		$this->categoryService = $this->createMock(CategoryService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new CategoryController(
			'attendance',
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->permissionService,
			$this->categoryService,
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->categoryService->method('getAll')->willReturn([new Category()]);
	}

	/**
	 * @return array<string, array{bool, bool, bool}>
	 */
	public static function templateAccess(): array {
		return [
			'plain member' => [false, false, false],
			'may create appointments' => [true, false, true],
			'admin' => [false, true, true],
		];
	}

	/**
	 * @dataProvider templateAccess
	 */
	public function testIndexHandsTheTemplateOnlyToThoseWhoFillInTheForm(bool $canCreate, bool $isAdmin, bool $expected): void {
		$this->permissionService->method('canCreateAppointments')->with('alice')->willReturn($canCreate);
		$this->permissionService->method('isAdmin')->with('alice')->willReturn($isAdmin);
		$this->categoryService->expects($this->once())->method('serialize')
			->with($this->isInstanceOf(Category::class), $expected)
			->willReturn(['id' => 1, 'name' => 'Rehearsal', 'icon' => 'tag', 'template' => null]);

		$this->assertSame(Http::STATUS_OK, $this->controller->index()->getStatus());
	}

	public function testUpdatePassesTheTemplateThrough(): void {
		$this->permissionService->method('isAdmin')->with('alice')->willReturn(true);
		$template = ['description' => 'Clothing:'];
		$category = new Category();
		$this->categoryService->expects($this->once())->method('update')
			->with(3, 'Rehearsal', 'tag', $template)
			->willReturn($category);
		$this->categoryService->expects($this->once())->method('serialize')->with($category, true)
			->willReturn(['id' => 3, 'name' => 'Rehearsal', 'icon' => 'tag', 'template' => null]);

		$this->assertSame(Http::STATUS_OK, $this->controller->update(3, 'Rehearsal', 'tag', $template)->getStatus());
	}
}
