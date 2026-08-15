<?php

declare(strict_types=1);

namespace OCA\Attendance\Controller;

use OCA\Attendance\BackgroundJob\ReminderJob;
use OCA\Attendance\Db\AppointmentMapper;
use OCA\Attendance\Service\CalendarService;
use OCA\Attendance\Service\ConfigService;
use OCA\Attendance\Service\GuestService;
use OCA\Attendance\Service\NotificationService;
use OCA\Attendance\Service\OrgCalendarSyncService;
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
	private CalendarService $calendarService;
	private OrgCalendarSyncService $orgCalendarSyncService;

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
		CalendarService $calendarService,
		OrgCalendarSyncService $orgCalendarSyncService,
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
		$this->calendarService = $calendarService;
		$this->orgCalendarSyncService = $orgCalendarSyncService;
	}

	/**
	 * Get admin settings data
	 *
	 * Returns admin-editable configuration, computed status, and available groups.
	 * System-wide capabilities (teamsAvailable, calendarSyncAvailable, notificationsAppEnabled)
	 * are available via GET /api/capabilities.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{config: AttendanceAdminConfig, status: AttendanceAdminStatus, groups: list<AttendanceGroupOption>, writableCalendars: list<AttendanceWritableCalendar>}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
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
					'orgCalendar' => [
						'enabled' => $this->configService->isOrgCalendarEnabled(),
						'calendarUri' => $this->configService->getOrgCalendarUri() ?: null,
						'userId' => $this->configService->getOrgCalendarUserId() ?: null,
						'summary' => $this->configService->isOrgCalendarSummaryEnabled(),
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
				'writableCalendars' => $this->calendarService->getCalendarsForUser($user->getUID(), true),
			]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Save admin settings
	 *
	 * All parameters are optional so the settings UI can auto-save partial
	 * payloads; omitted settings stay untouched.
	 *
	 * @param ?list<string> $whitelistedGroups Group IDs allowed to use the app
	 * @param ?list<string> $whitelistedTeams Team IDs allowed to use the app
	 * @param ?array<string, array{mode: string, groups: list<string>}> $permissions Permission name to access mode (all|groups|nobody) and group IDs
	 * @param ?array{enabled?: bool, reminderDays?: int, reminderFrequency?: int, reminderTarget?: string} $reminders Reminder settings
	 * @param ?array{enabled?: bool} $calendarSync Calendar sync settings
	 * @param ?array{enabled?: bool, calendarUri?: string, summary?: bool} $orgCalendar Organization calendar settings (target calendar for automatic event creation)
	 * @param ?array{enabled?: bool, visibility?: string} $audit Audit log settings (master switch + read visibility)
	 * @param ?string $displayOrder Display order for appointments: chronological, name, or group
	 * @param ?bool $pushEnabled Whether push notifications are enabled
	 * @param ?bool $mobileAppBannerEnabled Whether the mobile app promotion banner is enabled
	 * @param ?bool $bookingEnabled Whether the booking / planning feature is enabled
	 * @param ?int $selfCheckinWindowMinutes Minutes before appointment start that self-check-in opens
	 * @param ?bool $onboardingCompleted Whether the setup wizard has been walked to its end
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
	 */
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_ADMINISTRATION)]
	public function saveSettings(
		?array $whitelistedGroups = null,
		?array $whitelistedTeams = null,
		?array $permissions = null,
		?array $reminders = null,
		?array $calendarSync = null,
		?array $orgCalendar = null,
		?array $audit = null,
		?string $displayOrder = null,
		?bool $pushEnabled = null,
		?bool $mobileAppBannerEnabled = null,
		?bool $bookingEnabled = null,
		?int $selfCheckinWindowMinutes = null,
		?bool $onboardingCompleted = null,
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
			if ($whitelistedGroups !== null) {
				$this->configService->setWhitelistedGroups($whitelistedGroups);
			}
			if ($whitelistedTeams !== null) {
				$this->configService->setWhitelistedTeams($whitelistedTeams);
			}

			if ($permissions !== null) {
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

			// Save organization calendar settings (backfills on enable/re-point)
			if ($orgCalendar !== null) {
				$this->orgCalendarSyncService->applySettings($orgCalendar, $user->getUID());
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

			if ($onboardingCompleted !== null) {
				$this->configService->setOnboardingCompleted($onboardingCompleted);
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

	/**
	 * Push all upcoming appointments into the organization calendar
	 *
	 * Explicit backfill trigger for the admin settings — the same sync that
	 * runs automatically when the feature is enabled or re-pointed.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{synced: int}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{error: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
	 */
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_ADMINISTRATION)]
	public function syncOrgCalendar(): DataResponse {
		$user = $this->userSession->getUser();
		if (!$user) {
			return new DataResponse(['error' => 'User not authenticated'], 401);
		}

		if (!$this->permissionService->isAdmin($user->getUID())) {
			return new DataResponse(['error' => 'Insufficient permissions'], 403);
		}

		if (!$this->orgCalendarSyncService->isEnabled()) {
			return new DataResponse(['error' => 'Organization calendar is not configured'], 400);
		}

		return new DataResponse(['synced' => $this->orgCalendarSyncService->syncAllUpcoming()]);
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
