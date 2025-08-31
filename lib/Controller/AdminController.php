<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IGroupManager;
use OCP\IUserSession;

class AdminController extends Controller {
	private AdminSettings $adminSettings;
	private IGroupManager $groupManager;
	private IUserSession $userSession;

	public function __construct(string $appName, IRequest $request, AdminSettings $adminSettings, IGroupManager $groupManager, IUserSession $userSession) {
		parent::__construct($appName, $request);
		$this->adminSettings = $adminSettings;
		$this->groupManager = $groupManager;
		$this->userSession = $userSession;
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
			// Get all available groups
			$allGroups = $this->groupManager->search('');
			$groupOptions = [];
			foreach ($allGroups as $group) {
				$groupOptions[] = [
					'id' => $group->getGID(),
					'displayName' => $group->getDisplayName()
				];
			}

			// Get currently configured whitelisted groups
			$whitelistedGroups = $this->adminSettings->getWhitelistedGroups();

			return new JSONResponse([
				'success' => true,
				'groups' => $groupOptions,
				'whitelistedGroups' => $whitelistedGroups
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
		
		try {
			$this->adminSettings->setWhitelistedGroups($whitelistedGroups);
			return new JSONResponse(['success' => true]);
		} catch (\Exception $e) {
			return new JSONResponse(['success' => false, 'error' => $e->getMessage()]);
		}
	}
}
