<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\PermissionService;
use OCA\Attendance\Service\VisibilityService;
use OCA\Attendance\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class AdminController extends Controller {
	private AdminSettings $adminSettings;
	private PermissionService $permissionService;
	private IGroupManager $groupManager;
	private IUserSession $userSession;
	private IConfig $config;
	private IAppManager $appManager;
	private ConfigService $configService;
	private VisibilityService $visibilityService;

	public function __construct(
		string $appName,
		IRequest $request,
		IGroupManager $groupManager,
		IUserSession $userSession,
		AdminSettings $adminSettings,
		PermissionService $permissionService,
		IConfig $config,
		IAppManager $appManager,
		ConfigService $configService,
		VisibilityService $visibilityService,
	) {
		parent::__construct($appName, $request);
		$this->groupManager = $groupManager;
		$this->userSession = $userSession;
		$this->adminSettings = $adminSettings;
		$this->permissionService = $permissionService;
		$this->config = $config;
		$this->appManager = $appManager;
		$this->configService = $configService;
		$this->visibilityService = $visibilityService;
	}

	/**
	 * Get admin settings data (groups and current whitelist)
	 */
	public function getSettings(): JSONResponse {
		// Get current user
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['success' => false, 'error' => 'User not logged in'], 401);
		}

		// Check if user is admin
		if (!$this->groupManager->isAdmin($user->getUID())) {
			return new JSONResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
		}

		try {
			// Get all available groups (including admin)
			$groupOptions = $this->permissionService->getAvailableGroups();

			// Get currently configured whitelisted groups
			$whitelistedGroups = $this->adminSettings->getWhitelistedGroups();

			// Get currently configured whitelisted teams with display names
			$whitelistedTeamIds = $this->configService->getWhitelistedTeams();
			$whitelistedTeams = [];
			foreach ($whitelistedTeamIds as $teamId) {
				$teamInfo = $this->visibilityService->getTeamInfo($teamId);
				if ($teamInfo) {
					$whitelistedTeams[] = $teamInfo;
				}
			}

			// Get permission settings
			$permissionSettings = $this->permissionService->getAllPermissionSettings();

			// Get reminder settings
			$reminderSettings = [
				'enabled' => $this->config->getAppValue('attendance', 'reminders_enabled', 'no') === 'yes',
				'reminderDays' => (int)$this->config->getAppValue('attendance', 'reminder_days', '7'),
				'reminderFrequency' => (int)$this->config->getAppValue('attendance', 'reminder_frequency', '0'),
				'notificationsAppEnabled' => $this->appManager->isEnabledForUser('notifications'),
			];

			// Get calendar sync settings
			// Calendar sync is only available in NC 32+ when the calendar event classes exist
			// Use version check as class_exists() can be unreliable with autoloading
			$ncVersion = \OCP\Util::getVersion();
			$calendarSyncAvailable = $ncVersion[0] >= 32;
			$calendarSyncSettings = [
				'enabled' => $this->configService->isCalendarSyncEnabled(),
				'available' => $calendarSyncAvailable,
			];

			return new JSONResponse([
				'success' => true,
				'groups' => $groupOptions,
				'whitelistedGroups' => $whitelistedGroups,
				'whitelistedTeams' => $whitelistedTeams,
				'teamsAvailable' => $this->visibilityService->isTeamsAvailable(),
				'permissions' => $permissionSettings,
				'reminders' => $reminderSettings,
				'calendarSync' => $calendarSyncSettings,
				'displayOrder' => $this->configService->getDisplayOrder(),
			]);
		} catch (\Exception $e) {
			return new JSONResponse(['success' => false, 'error' => $e->getMessage()]);
		}
	}

	/**
	 * Save admin settings
	 */
	public function saveSettings(): JSONResponse {
		// Get current user
		$user = $this->userSession->getUser();
		if (!$user) {
			return new JSONResponse(['success' => false, 'error' => 'User not logged in'], 401);
		}

		// Check if user is admin
		if (!$this->groupManager->isAdmin($user->getUID())) {
			return new JSONResponse(['success' => false, 'error' => 'Insufficient permissions'], 403);
		}

		$whitelistedGroups = $this->request->getParam('whitelistedGroups', []);
		$whitelistedTeams = $this->request->getParam('whitelistedTeams', []);
		$permissions = $this->request->getParam('permissions', []);
		$reminders = $this->request->getParam('reminders', []);
		$calendarSync = $this->request->getParam('calendarSync', []);
		$displayOrder = $this->request->getParam('displayOrder', null);

		try {
			$this->adminSettings->setWhitelistedGroups($whitelistedGroups);
			$this->configService->setWhitelistedTeams($whitelistedTeams);

			// Save permissions
			if (isset($permissions) && is_array($permissions)) {
				$this->permissionService->setAllPermissionSettings($permissions);
			}

			// Save reminder settings
			if (isset($reminders['enabled'])) {
				$this->config->setAppValue('attendance', 'reminders_enabled', $reminders['enabled'] ? 'yes' : 'no');
			}
			if (isset($reminders['reminderDays'])) {
				$reminderDays = max(1, min(30, (int)$reminders['reminderDays'])); // Clamp between 1-30
				$this->config->setAppValue('attendance', 'reminder_days', (string)$reminderDays);
			}
			if (isset($reminders['reminderFrequency'])) {
				// Frequency: 0 = once, 1-30 = days between reminders
				$reminderFrequency = max(0, min(30, (int)$reminders['reminderFrequency']));
				$this->config->setAppValue('attendance', 'reminder_frequency', (string)$reminderFrequency);
			}

			// Save calendar sync settings
			if (isset($calendarSync['enabled'])) {
				$this->configService->setCalendarSyncEnabled((bool)$calendarSync['enabled']);
			}

			// Save display order
			if ($displayOrder !== null) {
				$this->configService->setDisplayOrder($displayOrder);
			}

			return new JSONResponse(['success' => true]);
		} catch (\Exception $e) {
			return new JSONResponse(['success' => false, 'error' => $e->getMessage()]);
		}
	}
}
