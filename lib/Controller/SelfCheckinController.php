<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\AppInfo\Application;
use OCA\Attendance\Controller\Traits\RequiresAuthTrait;
use OCA\Attendance\Service\PermissionService;
use OCA\Attendance\Service\SelfCheckinService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Controller for self-check-in via NFC / deep link.
 * All endpoints require Nextcloud authentication (logged-in user).
 */
class SelfCheckinController extends Controller {
	use RequiresAuthTrait;

	/** App Store ID of the companion app, same app as src/utils/mobileApp.js. */
	private const APPLE_APP_ID = '6759988681';

	private SelfCheckinService $selfCheckinService;
	private PermissionService $permissionService;
	private IUserSession $userSession;
	private IURLGenerator $urlGenerator;
	private LoggerInterface $logger;

	public function __construct(
		string $appName,
		IRequest $request,
		SelfCheckinService $selfCheckinService,
		PermissionService $permissionService,
		IUserSession $userSession,
		IURLGenerator $urlGenerator,
		LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
		$this->selfCheckinService = $selfCheckinService;
		$this->permissionService = $permissionService;
		$this->userSession = $userSession;
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
	}

	/**
	 * Get the self-check-in overview
	 *
	 * Returns active appointments the current user can self-check into right now,
	 * plus the next upcoming appointment whose check-in window has not opened yet.
	 *
	 * @return DataResponse<Http::STATUS_OK, AttendanceSelfCheckinOverview, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_DEFAULT)]
	public function getActiveAppointments(): DataResponse {
		$user = $this->requireUser();
		if ($user instanceof DataResponse) {
			return $user;
		}

		$permissionError = $this->requirePermission('self_checkin', 'Self-check-in is not enabled for your account.');
		if ($permissionError) {
			return $permissionError;
		}

		try {
			$overview = $this->selfCheckinService->getOverview($user->getUID());
			return new DataResponse($overview);
		} catch (\Exception $e) {
			$this->logger->error('Self-checkin: failed to get active appointments: ' . $e->getMessage());
			return new DataResponse(
				['error' => 'Failed to retrieve appointments.'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	/**
	 * Self-check-in to a specific appointment
	 *
	 * @param int $appointmentId ID of the appointment to check into
	 * @param string $method How the check-in was triggered: qr or nfc
	 * @return DataResponse<Http::STATUS_OK, AttendanceSelfCheckinResult, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_DEFAULT)]
	public function checkin(int $appointmentId, string $method = 'qr'): DataResponse {
		$user = $this->requireUser();
		if ($user instanceof DataResponse) {
			return $user;
		}

		$permissionError = $this->requirePermission('self_checkin', 'Self-check-in is not enabled for your account.');
		if ($permissionError) {
			return $permissionError;
		}

		try {
			$result = $this->selfCheckinService->selfCheckin($appointmentId, $user->getUID(), $method);
			return new DataResponse($result);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(
				['error' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (\Exception $e) {
			$this->logger->error('Self-checkin: failed to check in: ' . $e->getMessage());
			return new DataResponse(
				['error' => 'Failed to check in.'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	/**
	 * GET /self-checkin
	 * Landing page for scanned QR codes / NFC tags. Self-check-in itself is
	 * app-only — this page tells visitors to get the mobile app and offers a
	 * deep link that opens it directly. Public so a scan without an active
	 * browser session still lands on the funnel instead of a login wall.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function showPage(): TemplateResponse {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-selfcheckin');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-selfcheckin');

		// iOS Smart App Banner: Safari shows a native "Open" banner that
		// launches the installed app without the custom-scheme confirmation
		// dialog (and offers the App Store when it is missing). The
		// app-argument mirrors the deep link the page fires itself.
		$server = rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');
		Util::addHeader('meta', [
			'name' => 'apple-itunes-app',
			'content' => 'app-id=' . self::APPLE_APP_ID
				. ', app-argument=nc-attendance://self-checkin?server=' . rawurlencode($server),
		]);

		return new TemplateResponse(
			Application::APP_ID,
			'selfcheckin',
			[],
			'guest'
		);
	}
}
