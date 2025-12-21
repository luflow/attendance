<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller\Traits;

use OCP\AppFramework\Http\DataResponse;
use OCP\IUser;

/**
 * Trait for controllers that require authenticated users.
 * Provides helper methods to reduce boilerplate authentication code.
 */
trait RequiresAuthTrait {
	/**
	 * Get the current authenticated user.
	 * Returns null if no user is authenticated.
	 *
	 * @return IUser|null The authenticated user or null
	 */
	protected function getCurrentUser(): ?IUser {
		return $this->userSession->getUser();
	}

	/**
	 * Get the current authenticated user or return an error response.
	 * Use this when a user is required and you want to return early on failure.
	 *
	 * @return IUser|DataResponse The authenticated user or an error response
	 */
	protected function requireUser(): IUser|DataResponse {
		$user = $this->getCurrentUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}
		return $user;
	}

	/**
	 * Get the current user's ID if authenticated.
	 *
	 * @return string|null The user ID or null
	 */
	protected function getCurrentUserId(): ?string {
		$user = $this->getCurrentUser();
		return $user?->getUID();
	}

	/**
	 * Check if the current user has a specific permission.
	 *
	 * @param string $permission The permission to check
	 * @return bool True if user has permission
	 */
	protected function currentUserHasPermission(string $permission): bool {
		$userId = $this->getCurrentUserId();
		if (!$userId) {
			return false;
		}

		return match ($permission) {
			'manage_appointments' => $this->permissionService->canManageAppointments($userId),
			'checkin' => $this->permissionService->canCheckin($userId),
			'see_response_overview' => $this->permissionService->canSeeResponseOverview($userId),
			'see_comments' => $this->permissionService->canSeeComments($userId),
			default => false,
		};
	}

	/**
	 * Require a specific permission or return a 403 response.
	 *
	 * @param string $permission The permission to require
	 * @param string $errorMessage Custom error message
	 * @return DataResponse|null Error response or null if permission granted
	 */
	protected function requirePermission(string $permission, string $errorMessage = ''): ?DataResponse {
		if (!$this->currentUserHasPermission($permission)) {
			$message = $errorMessage ?: "Insufficient permissions: $permission required";
			return new DataResponse(['error' => $message], 403);
		}
		return null;
	}
}
