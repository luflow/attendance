<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Service\IcalService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

class IcalController extends Controller {
	private IcalService $icalService;
	private IUserSession $userSession;

	public function __construct(
		string $appName,
		IRequest $request,
		IcalService $icalService,
		IUserSession $userSession
	) {
		parent::__construct($appName, $request);
		$this->icalService = $icalService;
		$this->userSession = $userSession;
	}

	/**
	 * Get or create token for current user
	 *
	 * @NoAdminRequired
	 */
	public function getToken(): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		try {
			$token = $this->icalService->getOrCreateToken($user->getUID());
			$feedUrl = $this->icalService->getFeedUrl($user->getUID());

			return new DataResponse([
				'feedUrl' => $feedUrl,
				'createdAt' => $token->getCreatedAt(),
				'lastUsedAt' => $token->getLastUsedAt(),
			]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Regenerate token for current user
	 *
	 * @NoAdminRequired
	 */
	public function regenerateToken(): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		try {
			$token = $this->icalService->regenerateToken($user->getUID());
			$feedUrl = $this->icalService->getFeedUrl($user->getUID());

			return new DataResponse([
				'feedUrl' => $feedUrl,
				'createdAt' => $token->getCreatedAt(),
				'lastUsedAt' => $token->getLastUsedAt(),
			]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Serve iCal feed (public endpoint, authenticated by token)
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function feed(string $token): Response {
		$userId = $this->icalService->validateToken($token);

		if ($userId === null) {
			return new DataResponse(['error' => 'Invalid token'], 401);
		}

		try {
			$icalContent = $this->icalService->generateIcalFeed($userId);

			$response = new DataDownloadResponse(
				$icalContent,
				'attendance.ics',
				'text/calendar; charset=utf-8'
			);

			// Set appropriate cache headers
			$response->addHeader('Cache-Control', 'private, no-cache, must-revalidate');
			$response->addHeader('X-Content-Type-Options', 'nosniff');

			return $response;
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Failed to generate feed'], 500);
		}
	}
}
