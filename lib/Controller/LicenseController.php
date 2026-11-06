<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Service\LicenseService;
use OCA\Attendance\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * @psalm-import-type AttendanceLicenseStatus from \OCA\Attendance\ResponseDefinitions
 */
final class LicenseController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private PermissionService $permissionService,
		private LicenseService $licenseService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get the mobile-app license of this instance
	 *
	 * Asks the push relay, which answers only after fetching a one-time proof
	 * back from this server. Cached for a few minutes unless refreshed.
	 *
	 * @param bool $refresh Skip the cache and ask the relay again
	 * @return DataResponse<Http::STATUS_OK, AttendanceLicenseStatus, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
	 */
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_ADMINISTRATION)]
	public function status(bool $refresh = false): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->permissionService->isAdmin($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
		}
		return new DataResponse($this->licenseService->getStatus($refresh));
	}

	/**
	 * Answer the push relay's ownership check
	 *
	 * No brute-force throttle: the nonce is unguessable, and a throttle would
	 * land on the relay's address and break the check for this server.
	 *
	 * @param string $nonce The nonce this server handed to the relay
	 * @return DataResponse<Http::STATUS_OK, array{nonce: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array<empty>, array{}>
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function proof(string $nonce = ''): DataResponse {
		if (!$this->licenseService->isValidProof($nonce)) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse(['nonce' => $nonce]);
	}
}
