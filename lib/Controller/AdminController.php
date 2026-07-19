<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\BackgroundJob\ReminderJob;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\GuestService;
use OCA\Attendance\Service\NotificationService;
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
	private NotificationService $notificationService;
	private AppointmentMapper $appointmentMapper;
	private IJobList $jobList;
	private GuestService $guestService;

	public function __construct(
		string $appName,
		IRequest $request,
		IUserSession $userSession,
		PermissionService $permissionService,
		IConfig $config,
		IAppManager $appManager,
		ConfigService $configService,
		VisibilityService $visibilityService,
		NotificationService $notificationService,
		AppointmentMapper $appointmentMapper,
		IJobList $jobList,
		GuestService $guestService,
	) {
		parent::__construct($appName, $request);
		$this->userSession = $userSession;
		$this->permissionService = $permissionService;
		$this->config = $config;
		$this->appManager = $appManager;
		$this->configService = $configService;
		$this->visibilityService = $visibilityService;
		$this->notificationService = $notificationService;
		$this->appointmentMapper = $appointmentMapper;
		$this->jobList = $jobList;
		$this->guestService = $guestService;
	}

	/**
	 * Get admin settings data
	 *
	 * Returns admin-editable configuration, computed status, and available groups.
	 * System-wide capabilities (teamsAvailable, calendarSyncAvailable, notificationsAppEnabled)
	 * are available via GET /api/capabilities.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{config: AttendanceAdminConfig, status: AttendanceAdminStatus, groups: list<AttendanceGroupOption>}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
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

			// Closed inquiries are skipped so the preview matches the cron's
			// findRemindable filter.
			$next = $this->findFirstOpenUpcoming();
			$nextAppointment = $next ? [
				'name' => $next->getName(),
				'startDatetime' => $next->getStartDatetime(),
			] : null;

			// Compute status: next reminder run
			$nextReminderRun = null;
			$reminderJobs = $this->jobList->getJobs(ReminderJob::class, 1, 0);
			if (!empty($reminderJobs)) {
				$lastRun = $reminderJobs[0]->getLastRun();
				if ($lastRun > 0) {
					$nextReminderRun = gmdate('Y-m-d H:i:s', $lastRun + 86400);
				}
			}

			$pushDeviceCount = $this->notificationService->countPushDevices($user->getUID());

			return new DataResponse([
				'config' => [
					'whitelistedGroups' => $whitelistedGroups,
					'whitelistedTeams' => $whitelistedTeams,
					'permissions' => $permissionSettings,
					'reminders' => [
						'enabled' => $this->config->getAppValue('attendance', 'reminders_enabled', 'no') === 'yes',
						'reminderDays' => (int)$this->config->getAppValue('attendance', 'reminder_days', '7'),
						'reminderFrequency' => (int)$this->config->getAppValue('attendance', 'reminder_frequency', '0'),
						'reminderTarget' => $this->configService->getReminderTarget(),
					],
					'calendarSync' => [
						'enabled' => $this->configService->isCalendarSyncEnabled(),
					],
					'audit' => [
						'enabled' => $this->configService->isAuditLogEnabled(),
						'visibility' => $this->configService->getAuditLogVisibility(),
					],
					'displayOrder' => $this->configService->getDisplayOrder(),
					'pushEnabled' => $this->configService->isPushEnabled(),
					'mobileAppBannerEnabled' => $this->configService->isMobileAppBannerEnabled(),
					'bookingEnabled' => $this->configService->isBookingEnabled(),
					'selfCheckinWindowMinutes' => $this->configService->getSelfCheckinWindowMinutes(),
					'guestsApp' => [
						'enabled' => $this->guestService->isGuestsAppEnabled(),
						'whitelistEnabled' => $this->guestService->isGuestsWhitelistEnabled(),
						'attendanceInWhitelist' => $this->guestService->isAttendanceInGuestsWhitelist(),
					],
				],
				'status' => [
					'nextAppointment' => $nextAppointment,
					'nextReminderRun' => $nextReminderRun,
					'pushDeviceCount' => $pushDeviceCount,
				],
				'groups' => $groupOptions,
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
	 * @param array{enabled?: bool, reminderDays?: int, reminderFrequency?: int, reminderTarget?: string} $reminders Reminder settings
	 * @param array{enabled?: bool} $calendarSync Calendar sync settings
	 * @param array{enabled?: bool, visibility?: string} $audit Audit log settings (master switch + read visibility)
	 * @param ?string $displayOrder Display order for appointments: chronological, name, or group
	 * @param ?bool $pushEnabled Whether push notifications are enabled
	 * @param ?bool $mobileAppBannerEnabled Whether the mobile app promotion banner is enabled
	 * @param ?bool $bookingEnabled Whether the booking / planning feature is enabled
	 * @param ?int $selfCheckinWindowMinutes Minutes before appointment start that self-check-in opens
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
	 */
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_ADMINISTRATION)]
	public function saveSettings(
		array $whitelistedGroups = [],
		array $whitelistedTeams = [],
		array $permissions = [],
		array $reminders = [],
		array $calendarSync = [],
		array $audit = [],
		?string $displayOrder = null,
		?bool $pushEnabled = null,
		?bool $mobileAppBannerEnabled = null,
		?bool $bookingEnabled = null,
		?int $selfCheckinWindowMinutes = null,
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
			if (isset($reminders['reminderTarget'])) {
				$this->configService->setReminderTarget($reminders['reminderTarget']);
			}

			// Save calendar sync settings
			if (isset($calendarSync['enabled'])) {
				$this->configService->setCalendarSyncEnabled((bool)$calendarSync['enabled']);
			}

			// Save audit log settings
			if (isset($audit['enabled'])) {
				$this->configService->setAuditLogEnabled((bool)$audit['enabled']);
			}
			if (isset($audit['visibility']) && is_string($audit['visibility'])) {
				$this->configService->setAuditLogVisibility($audit['visibility']);
			}

			// Save display order
			if ($displayOrder !== null) {
				$this->configService->setDisplayOrder($displayOrder);
			}

			if ($pushEnabled !== null) {
				$this->configService->setPushEnabled($pushEnabled);
			}

			if ($mobileAppBannerEnabled !== null) {
				$this->configService->setMobileAppBannerEnabled($mobileAppBannerEnabled);
			}

			if ($bookingEnabled !== null) {
				$this->configService->setBookingEnabled($bookingEnabled);
			}

			if ($selfCheckinWindowMinutes !== null) {
				$this->configService->setSelfCheckinWindowMinutes($selfCheckinWindowMinutes);
			}

			return new DataResponse([]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Send a test reminder notification to the current admin user
	 *
	 * Uses the next upcoming appointment to send a preview reminder notification.
	 *
	 * @return DataResponse<Http::STATUS_OK, AttendanceTestReminderResult, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{error: string}, array{}>
	 */
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_ADMINISTRATION)]
	public function sendTestReminder(): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		if (!$this->permissionService->isAdmin($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions'], 403);
		}

		$appointment = $this->findFirstOpenUpcoming();
		if ($appointment === null) {
			return new DataResponse(['error' => 'No upcoming appointment found'], 404);
		}

		try {
			$this->notificationService->sendReminderToUser($appointment, $user->getUID(), true);
			return new DataResponse([
				'sent' => 1,
				'appointmentName' => $appointment->getName(),
			]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}

	private function findFirstOpenUpcoming(): ?\OCA\Attendance\Db\Appointment {
		foreach ($this->appointmentMapper->findUpcoming() as $candidate) {
			if (!$candidate->isClosed()) {
				return $candidate;
			}
		}
		return null;
	}
}
