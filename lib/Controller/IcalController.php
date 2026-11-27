<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Db\IcalToken;
use OCA\Attendance\Service\AppointmentService;
use OCA\Attendance\Service\IcalService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * @psalm-import-type AttendanceIcalFeed from \OCA\Attendance\ResponseDefinitions
 */
class IcalController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IcalService $icalService,
		private IUserSession $userSession,
		private AppointmentService $appointmentService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get or create the token of one of the current user's iCal feeds
	 *
	 * @param string $kind Which feed: `personal` (own appointments and answer) or `organizer` (managed appointments with everybody's answers)
	 * @return DataResponse<Http::STATUS_OK, AttendanceIcalFeed, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
	 *
	 * 200: Feed URL returned
	 * 400: Unknown feed kind
	 * 401: Not logged in
	 * 403: The organizer feed is not available to this user
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function getToken(string $kind = IcalToken::KIND_PERSONAL): DataResponse {
		return $this->tokenResponse($kind, false);
	}

	/**
	 * Regenerate the token of one of the current user's iCal feeds
	 *
	 * @param string $kind Which feed: `personal` or `organizer`
	 * @return DataResponse<Http::STATUS_OK, AttendanceIcalFeed, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
	 *
	 * 200: New feed URL returned
	 * 400: Unknown feed kind
	 * 401: Not logged in
	 * 403: The organizer feed is not available to this user
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI]
	public function regenerateToken(string $kind = IcalToken::KIND_PERSONAL): DataResponse {
		return $this->tokenResponse($kind, true);
	}

	/**
	 * @return DataResponse<Http::STATUS_OK, AttendanceIcalFeed, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
	 */
	private function tokenResponse(string $kind, bool $regenerate): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		if (!in_array($kind, IcalToken::KINDS, true)) {
			return new DataResponse(['error' => 'Unknown feed kind'], Http::STATUS_BAD_REQUEST);
		}
		// Answered here rather than with the permissions: it costs a scan of the
		// appointments, and only whoever opens the subscription needs to know.
		$organizerAvailable = $this->appointmentService->actsAsOrganizer($user->getUID());
		if ($kind === IcalToken::KIND_ORGANIZER && !$organizerAvailable) {
			return new DataResponse(['error' => 'Insufficient permissions'], Http::STATUS_FORBIDDEN);
		}

		try {
			$token = $regenerate
				? $this->icalService->regenerateToken($user->getUID(), $kind)
				: $this->icalService->getOrCreateToken($user->getUID(), $kind);
			$tokenData = $token->jsonSerialize();

			return new DataResponse([
				'feedUrl' => $this->icalService->getFeedUrl($token),
				'createdAt' => $tokenData['createdAt'],
				'lastUsedAt' => $tokenData['lastUsedAt'],
				'organizerAvailable' => $organizerAvailable,
			]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Serve iCal feed (public endpoint, authenticated by token)
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function feed(string $token): Response {
		$icalToken = $this->icalService->validateToken($token);

		if ($icalToken === null) {
			return new DataResponse(['error' => 'Invalid token'], 401);
		}

		try {
			$icalContent = $this->icalService->generateFeedFor($icalToken);

			// ETag for conditional requests (saves bandwidth on repeated polls)
			$etag = '"' . md5($icalContent) . '"';
			$ifNoneMatch = $this->request->getHeader('If-None-Match');
			if ($ifNoneMatch === $etag) {
				$response = new Response();
				$response->setStatus(Http::STATUS_NOT_MODIFIED);
				$response->addHeader('ETag', $etag);
				return $response;
			}

			$response = new DataDownloadResponse(
				$icalContent,
				'attendance.ics',
				'text/calendar; charset=utf-8'
			);

			$response->addHeader('Cache-Control', 'private, no-cache, must-revalidate');
			$response->addHeader('ETag', $etag);
			$response->addHeader('Content-Disposition', 'inline; filename="attendance.ics"');
			$response->addHeader('X-Content-Type-Options', 'nosniff');

			return $response;
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Failed to generate feed'], 500);
		}
	}
}
