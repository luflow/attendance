<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\BackgroundJob\ReminderJob;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\PermissionService;
use OCA\Attendance\Service\VisibilityService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

class AdminController extends Controller {
	private PermissionService $permissionService;
	private IUserSession $userSession;
	private IConfig $config;
	private IAppManager $appManager;
	private ConfigService $configService;
	private VisibilityService $visibilityService;
	private AppointmentMapper $appointmentMapper;
	private IJobList $jobList;

	public function __construct(
		string $appName,
		IRequest $request,
		IUserSession $userSession,
		PermissionService $permissionService,
		IConfig $config,
		IAppManager $appManager,
		ConfigService $configService,
		VisibilityService $visibilityService,
		AppointmentMapper $appointmentMapper,
		IJobList $jobList,
	) {
		parent::__construct($appName, $request);
		$this->userSession = $userSession;
		$this->permissionService = $permissionService;
		$this->config = $config;
		$this->appManager = $appManager;
		$this->configService = $configService;
		$this->visibilityService = $visibilityService;
		$this->appointmentMapper = $appointmentMapper;
		$this->jobList = $jobList;
	}

	/**
	 * Get admin settings data (groups and current whitelist)
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{success: bool, groups: list<AttendanceGroupOption>, whitelistedGroups: list<string>, whitelistedTeams: list<AttendanceTeamOption>, teamsAvailable: bool, permissions: AttendancePermissionSettings, reminders: AttendanceReminderSettings, calendarSync: AttendanceCalendarSyncSettings, displayOrder: string}, array{}>|JSONResponse<Http::STATUS_OK, array{success: bool, error: string}, array{}>|JSONResponse<Http::STATUS_UNAUTHORIZED, array{success: bool, error: string}, array{}>|JSONResponse<Http::STATUS_FORBIDDEN, array{success: bool, error: string}, array{}>
	 */
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_ADMINISTRATION)]
	public function getSettings(): DataResponse {
		// Get current user
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user is admin
		if (!$this->permissionService->isAdmin($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions'], 403);
		}

		try {
			// Get all available groups (including admin)
			$groupOptions = $this->permissionService->getAvailableGroups();

			// Get currently configured whitelisted groups
			$whitelistedGroups = $this->configService->getWhitelistedGroups();

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
			$upcomingAppointments = $this->appointmentMapper->findUpcoming();
			$nextAppointment = null;
			if (!empty($upcomingAppointments)) {
				$first = $upcomingAppointments[0];
				$nextAppointment = [
					'name' => $first->getName(),
					'startDatetime' => $first->getStartDatetime(),
				];
			}

			// Get next planned reminder run from background job
			$nextReminderRun = null;
			$reminderJobs = $this->jobList->getJobs(ReminderJob::class, 1, 0);
			if (!empty($reminderJobs)) {
				$lastRun = $reminderJobs[0]->getLastRun();
				if ($lastRun > 0) {
					$nextReminderRun = gmdate('Y-m-d H:i:s', $lastRun + 86400);
				}
			}

			$reminderSettings = [
				'enabled' => $this->config->getAppValue('attendance', 'reminders_enabled', 'no') === 'yes',
				'reminderDays' => (int)$this->config->getAppValue('attendance', 'reminder_days', '7'),
				'reminderFrequency' => (int)$this->config->getAppValue('attendance', 'reminder_frequency', '0'),
				'notificationsAppEnabled' => $this->appManager->isEnabledForUser('notifications'),
				'nextAppointment' => $nextAppointment,
				'nextReminderRun' => $nextReminderRun,
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

			return new DataResponse([
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
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Save admin settings
	 *
	 * @param list<string> $whitelistedGroups Group IDs allowed to use the app
	 * @param list<string> $whitelistedTeams Team IDs allowed to use the app
	 * @param array<string, list<string>> $permissions Permission name to group IDs mapping
	 * @param array{enabled?: bool, reminderDays?: int, reminderFrequency?: int} $reminders Reminder settings
	 * @param array{enabled?: bool} $calendarSync Calendar sync settings
	 * @param ?string $displayOrder Display order for appointments: chronological, name, or group
	 * @return JSONResponse<Http::STATUS_OK, array{success: bool}, array{}>|JSONResponse<Http::STATUS_OK, array{success: bool, error: string}, array{}>|JSONResponse<Http::STATUS_UNAUTHORIZED, array{success: bool, error: string}, array{}>|JSONResponse<Http::STATUS_FORBIDDEN, array{success: bool, error: string}, array{}>
	 */
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_ADMINISTRATION)]
	public function saveSettings(
		array $whitelistedGroups = [],
		array $whitelistedTeams = [],
		array $permissions = [],
		array $reminders = [],
		array $calendarSync = [],
		?string $displayOrder = null,
	): DataResponse {
		// Get current user
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		// Check if user is admin
		if (!$this->permissionService->isAdmin($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions'], 403);
		}

		try {
			$this->configService->setWhitelistedGroups($whitelistedGroups);
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

			return new DataResponse(['success' => true]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}
}
