<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\StreakService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class StreakController extends Controller {
	private StreakService $streakService;
	private ConfigService $configService;
	private IUserSession $userSession;

	public function __construct(
		string $appName,
		IRequest $request,
		StreakService $streakService,
		ConfigService $configService,
		IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
		$this->streakService = $streakService;
		$this->configService = $configService;
		$this->userSession = $userSession;
	}

	/**
	 * @NoAdminRequired
	 */
	public function getUserStreak(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'User not logged in'], 401);
		}

		if (!$this->configService->isStreaksEnabled()) {
			return new JSONResponse(['currentStreak' => 0, 'longestStreak' => 0, 'streakLevel' => 'none']);
		}

		$streak = $this->streakService->getUserStreak($user->getUID());
		return new JSONResponse($streak->jsonSerialize());
	}

	/**
	 * @NoAdminRequired
	 */
	public function recalculateStreak(): JSONResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['error' => 'User not logged in'], 401);
		}

		if (!$this->configService->isStreaksEnabled()) {
			return new JSONResponse(['currentStreak' => 0, 'longestStreak' => 0, 'streakLevel' => 'none']);
		}

		$streak = $this->streakService->recalculateStreak($user->getUID());
		return new JSONResponse($streak->jsonSerialize());
	}
}
