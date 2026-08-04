<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Service\CategoryService;
use OCA\Attendance\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class CategoryController extends Controller {
	private IUserSession $userSession;
	private PermissionService $permissionService;
	private CategoryService $categoryService;

	public function __construct(
		string $appName,
		IRequest $request,
		IUserSession $userSession,
		PermissionService $permissionService,
		CategoryService $categoryService,
	) {
		parent::__construct($appName, $request);
		$this->userSession = $userSession;
		$this->permissionService = $permissionService;
		$this->categoryService = $categoryService;
	}

	/**
	 * List all categories, alphabetically
	 *
	 * Available to any logged-in user — appointments' category picker and
	 * the appointment list filter both need the full list, not just admins.
	 *
	 * @return DataResponse<Http::STATUS_OK, list<AttendanceCategoryData>, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function index(): DataResponse {
		if ($this->userSession->getUser() === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return new DataResponse($this->categoryService->getAll());
	}

	/**
	 * Create a category
	 *
	 * @param string $name Category name, must be unique
	 * @param string $icon Icon key, must be one of CategoryService::ICONS
	 * @return DataResponse<Http::STATUS_CREATED, AttendanceCategoryData, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
	 */
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_ADMINISTRATION)]
	public function create(string $name, string $icon): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->permissionService->isAdmin($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new DataResponse($this->categoryService->create($name, $icon), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Rename a category or change its icon
	 *
	 * @param int $id Category ID
	 * @param string $name New category name, must be unique
	 * @param string $icon Icon key, must be one of CategoryService::ICONS
	 * @return DataResponse<Http::STATUS_OK, AttendanceCategoryData, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_ADMINISTRATION)]
	public function update(int $id, string $name, string $icon): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->permissionService->isAdmin($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new DataResponse($this->categoryService->update($id, $name, $icon));
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'Category not found'], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Delete a category
	 *
	 * Any appointment that referenced this category has it cleared, not
	 * deleted — the appointment itself is never touched.
	 *
	 * @param int $id Category ID
	 * @return DataResponse<Http::STATUS_OK, array{success: bool}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_ADMINISTRATION)]
	public function destroy(int $id): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->permissionService->isAdmin($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
		}

		try {
			$this->categoryService->delete($id);
			return new DataResponse(['success' => true]);
		} catch (DoesNotExistException $e) {
			return new DataResponse(['error' => 'Category not found'], Http::STATUS_NOT_FOUND);
		}
	}
}
