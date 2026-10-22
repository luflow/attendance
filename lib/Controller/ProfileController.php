<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Service\ProfileChangeNotAllowedException;
use OCA\Attendance\Service\ProfileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * @psalm-import-type AttendanceOwnProfile from \OCA\Attendance\ResponseDefinitions
 * @psalm-import-type AttendanceProfileDirectory from \OCA\Attendance\ResponseDefinitions
 */
final class ProfileController extends Controller {
	/** Matches core's own avatar upload limit. */
	private const MAX_AVATAR_BYTES = 20 * 1024 * 1024;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private ProfileService $profileService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get the current user's own profile.
	 *
	 * @return DataResponse<Http::STATUS_OK, AttendanceOwnProfile, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function show(): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return new DataResponse($this->profileService->getOwnProfile($user));
	}

	/**
	 * Update the current user's display name and/or biography.
	 *
	 * @param ?string $displayName New display name, left unchanged when null
	 * @param ?string $biography New biography, left unchanged when null
	 * @return DataResponse<Http::STATUS_OK, AttendanceOwnProfile, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function update(?string $displayName = null, ?string $biography = null): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new DataResponse($this->profileService->updateOwnProfile($user, $displayName, $biography));
		} catch (ProfileChangeNotAllowedException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Replace the current user's avatar.
	 *
	 * @param string $image Base64-encoded PNG or JPEG; a non-square one is cropped to its centre
	 * @return DataResponse<Http::STATUS_OK, array{success: bool}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function updateAvatar(string $image): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Checked on the encoded length, so an oversized upload is never decoded.
		if (strlen($image) > intdiv(self::MAX_AVATAR_BYTES * 4, 3) + 4) {
			return new DataResponse(['error' => 'Image is too large'], Http::STATUS_BAD_REQUEST);
		}
		$data = base64_decode($image, true);
		if ($data === false || $data === '') {
			return new DataResponse(['error' => 'Image is not valid base64'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->profileService->setAvatar($user, $data);
		} catch (ProfileChangeNotAllowedException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse(['success' => true]);
	}

	/**
	 * List the users the current user can see, with the biography each of
	 * them shows to the current user. `complete` is false when the directory
	 * holds more people than one page; only then is searching worth a request.
	 *
	 * @param string $search Search query
	 * @return DataResponse<Http::STATUS_OK, AttendanceProfileDirectory, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function index(string $search = ''): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return new DataResponse($this->profileService->listProfiles($user, $search));
	}
}
