<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Settings\AdminSettings;
use OCA\Attendance\Service\PermissionService;
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

	public function __construct(
		string $appName,
		IRequest $request,
		IGroupManager $groupManager,
		IUserSession $userSession,
		AdminSettings $adminSettings,
		PermissionService $permissionService,
		IConfig $config,
		IAppManager $appManager
	) {
		parent::__construct($appName, $request);
		$this->groupManager = $groupManager;
		$this->userSession = $userSession;
		$this->adminSettings = $adminSettings;
		$this->permissionService = $permissionService;
		$this->config = $config;
		$this->appManager = $appManager;
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

			// Get permission settings
			$permissionSettings = $this->permissionService->getAllPermissionSettings();

			// Get reminder settings
			$reminderSettings = [
				'enabled' => $this->config->getAppValue('attendance', 'reminders_enabled', 'no') === 'yes',
				'reminderDays' => (int)$this->config->getAppValue('attendance', 'reminder_days', '7'),
				'notificationsAppEnabled' => $this->appManager->isEnabledForUser('notifications'),
			];

			return new JSONResponse([
				'success' => true,
				'groups' => $groupOptions,
				'whitelistedGroups' => $whitelistedGroups,
				'permissions' => $permissionSettings,
				'reminders' => $reminderSettings
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
		$permissions = $this->request->getParam('permissions', []);
		$reminders = $this->request->getParam('reminders', []);
		
		try {
			$this->adminSettings->setWhitelistedGroups($whitelistedGroups);
			
			// Save permissions
			if (isset($permissions) && is_array($permissions)) {
				foreach ($permissions as $permissionKey => $groupIds) {
					$this->permissionService->setPermission($permissionKey, $groupIds);
				}
			}
		
			// Save reminder settings
			if (isset($reminders['enabled'])) {
				$this->config->setAppValue('attendance', 'reminders_enabled', $reminders['enabled'] ? 'yes' : 'no');
			}
			if (isset($reminders['reminderDays'])) {
				$reminderDays = max(1, min(30, (int)$reminders['reminderDays'])); // Clamp between 1-30
				$this->config->setAppValue('attendance', 'reminder_days', (string)$reminderDays);
			}
		
			return new JSONResponse(['success' => true]);
		} catch (\Exception $e) {
			return new JSONResponse(['success' => false, 'error' => $e->getMessage()]);
		}
	}
}
