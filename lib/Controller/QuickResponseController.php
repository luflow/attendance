<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\AppInfo\Application;
use OCA\Attendance\Service\QuickResponseTokenService;
use OCA\Attendance\Service\ResponseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IInitialStateService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling quick response links from notifications and emails.
 * All endpoints are public (no login required) and authenticated via signed tokens.
 */
class QuickResponseController extends Controller {
	private QuickResponseTokenService $tokenService;
	private ResponseService $responseService;
	private IInitialStateService $initialStateService;
	private IL10N $l;
	private IUserManager $userManager;
	private LoggerInterface $logger;

	public function __construct(
		string $appName,
		IRequest $request,
		QuickResponseTokenService $tokenService,
		ResponseService $responseService,
		IInitialStateService $initialStateService,
		IL10N $l,
		IUserManager $userManager,
		LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
		$this->tokenService = $tokenService;
		$this->responseService = $responseService;
		$this->initialStateService = $initialStateService;
		$this->l = $l;
		$this->userManager = $userManager;
		$this->logger = $logger;
	}

	/**
	 * Show confirmation page for quick response.
	 * User clicks this link from email/notification, sees appointment details and a confirm button.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'attendance_quickresponse')]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function showConfirmation(
		int $appointmentId,
		string $response,
		string $token,
		string $userId,
	): TemplateResponse {
		// Provide NC version for CSS compatibility
		$ncVersion = Util::getVersion();
		$this->initialStateService->provideInitialState(
			Application::APP_ID,
			'nc-version',
			$ncVersion[0]
		);

		$validationResult = $this->validateQuickResponse($appointmentId, $response, $token, $userId);

		if ($validationResult['error']) {
			$this->initialStateService->provideInitialState(
				Application::APP_ID,
				'quick-response-data',
				$validationResult
			);

			$errorResponse = new TemplateResponse(
				Application::APP_ID,
				'quickresponse',
				[],
				'guest'
			);
			$errorResponse->throttle();
			return $errorResponse;
		}

		// Get appointment details
		$appointment = $this->tokenService->getAppointment($appointmentId);

		$data = [
			'error' => false,
			'confirmed' => false,
			'appointmentId' => $appointmentId,
			'appointmentName' => $appointment->getName(),
			'appointmentDatetime' => $appointment->getStartDatetime(),
			'response' => $response,
			'responseLabel' => $this->getResponseLabel($response),
			'token' => $token,
			'userId' => $userId,
			'userName' => $this->getUserDisplayName($userId),
		];

		$this->initialStateService->provideInitialState(
			Application::APP_ID,
			'quick-response-data',
			$data
		);

		return new TemplateResponse(
			Application::APP_ID,
			'quickresponse',
			[],
			'guest'
		);
	}

	/**
	 * Confirm and record the quick response (API endpoint).
	 * This is called via AJAX when the user clicks the confirm button.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'attendance_quickresponse')]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function confirmResponse(
		int $appointmentId,
		string $response,
		string $token,
		string $userId,
	): DataResponse {
		$validationResult = $this->validateQuickResponse($appointmentId, $response, $token, $userId);

		if ($validationResult['error']) {
			$this->tokenService->logQuickResponse(
				$appointmentId,
				$userId,
				$response,
				false,
				$validationResult['errorMessage'],
				$this->request->getRemoteAddress()
			);

			$errorResponse = new DataResponse([
				'success' => false,
				'message' => $validationResult['errorMessage'],
			], 400);
			$errorResponse->throttle();
			return $errorResponse;
		}

		// Record the response
		try {
			$this->responseService->submitResponse(
				$appointmentId,
				$userId,
				$response,
				'',
				ResponseService::SOURCE_QUICK_LINK
			);

			$this->tokenService->logQuickResponse(
				$appointmentId,
				$userId,
				$response,
				true,
				'',
				$this->request->getRemoteAddress()
			);

			return new DataResponse([
				'success' => true,
				'message' => $this->l->t('Response recorded successfully'),
			]);
		} catch (\Exception $e) {
			$this->logger->error('Failed to record quick response: ' . $e->getMessage());

			$this->tokenService->logQuickResponse(
				$appointmentId,
				$userId,
				$response,
				false,
				'Internal error',
				$this->request->getRemoteAddress()
			);

			return new DataResponse([
				'success' => false,
				'message' => $this->l->t('An error occurred while recording your response. Please try again.'),
			], 500);
		}
	}

	/**
	 * Validate the quick response request.
	 *
	 * @return array{error: bool, errorMessage?: string}
	 */
	private function validateQuickResponse(
		int $appointmentId,
		string $response,
		string $token,
		string $userId,
	): array {
		// Validate response type
		if (!in_array($response, ['yes', 'no', 'maybe'])) {
			return [
				'error' => true,
				'errorMessage' => $this->l->t('Invalid response type.'),
			];
		}

		// Verify token
		if (!$this->tokenService->verifyToken($token, $userId, $appointmentId, $response)) {
			return [
				'error' => true,
				'errorMessage' => $this->l->t('This link is invalid. Please use the link from your notification.'),
			];
		}

		// Check if expired
		if ($this->tokenService->isExpired($appointmentId)) {
			return [
				'error' => true,
				'errorMessage' => $this->l->t('This link has expired. The appointment has already ended.'),
			];
		}

		// Check if user exists
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return [
				'error' => true,
				'errorMessage' => $this->l->t('This link is no longer valid.'),
			];
		}

		// Check if appointment exists
		$appointment = $this->tokenService->getAppointment($appointmentId);
		if ($appointment === null) {
			return [
				'error' => true,
				'errorMessage' => $this->l->t('This appointment no longer exists.'),
			];
		}

		return ['error' => false];
	}

	/**
	 * Get the translated label for a response type.
	 */
	private function getResponseLabel(string $response): string {
		switch ($response) {
			case 'yes':
				return $this->l->t('Yes');
			case 'no':
				return $this->l->t('No');
			case 'maybe':
				return $this->l->t('Maybe');
			default:
				return $response;
		}
	}

	/**
	 * Get the display name for a user.
	 */
	private function getUserDisplayName(string $userId): string {
		$user = $this->userManager->get($userId);
		return $user ? $user->getDisplayName() : $userId;
	}
}
