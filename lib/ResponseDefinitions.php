<?php

declare(strict_types=1);

namespace OCA\Attendance;

/**
 * @psalm-type AttendanceAppointmentData = array{
 *   id: int,
 *   name: string,
 *   description: string,
 *   startDatetime: string,
 *   endDatetime: string,
 *   createdBy: string,
 *   createdAt: string,
 *   updatedAt: string,
 *   isActive: int,
 *   visibleUsers: list<string>,
 *   visibleGroups: list<string>,
 *   visibleTeams: list<string>,
 *   organizers: list<string>,
 *   calendarUri: ?string,
 *   calendarEventUid: ?string,
 *   seriesId: ?string,
 *   seriesPosition: ?int,
 *   sendNotification: bool,
 *   closedAt: ?string,
 *   cancelledAt: ?string,
 *   responseDeadline: ?string,
 * }
 * @psalm-type AttendanceMyPermissions = array{
 *   isOrganizer: bool,
 *   canEdit: bool,
 *   canSeeResponses: bool,
 *   canSeeResponseCounts: bool,
 *   canSeeComments: bool,
 *   canSeeAuditLog: bool,
 * }
 * @psalm-type AttendanceResponseData = array{
 *   id: int,
 *   appointmentId: int,
 *   userId: string,
 *   response: string,
 *   comment: string,
 *   respondedAt: ?string,
 *   checkinState: string,
 *   checkinComment: string,
 *   checkinBy: string,
 *   checkinAt: ?string,
 *   isCheckedIn: bool,
 *   responseSource: ?string,
 *   checkinSource: ?string,
 *   bookingStatus: ?string,
 * }
 * @psalm-type AttendanceResponseWithUser = array{
 *   id: int,
 *   appointmentId: int,
 *   userId: string,
 *   response: string,
 *   comment: string,
 *   respondedAt: ?string,
 *   checkinState: string,
 *   checkinComment: string,
 *   checkinBy: string,
 *   checkinAt: ?string,
 *   isCheckedIn: bool,
 *   responseSource: ?string,
 *   checkinSource: ?string,
 *   bookingStatus: ?string,
 *   userName: string,
 *   userGroups: list<string>,
 *   isGuest: bool,
 * }
 * @psalm-type AttendanceGuestsAppStatus = array{
 *   enabled: bool,
 *   whitelistEnabled: bool,
 *   attendanceInWhitelist: bool,
 * }
 * @psalm-type AttendanceGuestCreationResult = array{
 *   userId: string,
 *   displayName: string,
 *   email: string,
 *   isGuest: bool,
 *   alreadyExisted: bool,
 * }
 * @psalm-type AttendanceAppointmentWithResponse = array{
 *   id: int,
 *   name: string,
 *   description: string,
 *   startDatetime: string,
 *   endDatetime: string,
 *   createdBy: string,
 *   createdAt: string,
 *   updatedAt: string,
 *   isActive: int,
 *   visibleUsers: list<string>,
 *   visibleGroups: list<string>,
 *   visibleTeams: list<string>,
 *   organizers: list<array{id: string, label: string, type: string}>,
 *   calendarUri: ?string,
 *   calendarEventUid: ?string,
 *   seriesId: ?string,
 *   seriesPosition: ?int,
 *   sendNotification: bool,
 *   closedAt: ?string,
 *   cancelledAt: ?string,
 *   responseDeadline: ?string,
 *   userResponse: AttendanceResponseData|null,
 *   responseSummary?: array<string, mixed>,
 *   attachments: list<array<string, mixed>>,
 *   myPermissions: AttendanceMyPermissions,
 * }
 * @psalm-type AttendanceNavigationAppointment = array{
 *   id: int,
 *   name: string,
 *   startDatetime: string,
 *   seriesId: ?string,
 *   seriesPosition: ?int,
 *   userResponse: ?array{response: string},
 *   closedAt: ?string,
 *   cancelledAt: ?string,
 *   inAudience: bool,
 * }
 * @psalm-type AttendanceCheckinData = array{
 *   appointment: AttendanceAppointmentData,
 *   users: list<array<string, mixed>>,
 *   userGroups: list<string>,
 * }
 * @psalm-type AttendanceBulkAppointmentItem = array{
 *   name: string,
 *   description: string,
 *   startDatetime: string,
 *   endDatetime: string,
 *   visibleUsers?: list<string>,
 *   visibleGroups?: list<string>,
 *   visibleTeams?: list<string>,
 *   calendarUri?: string,
 *   calendarEventUid?: string,
 *   responseDeadline?: string,
 * }
 * @psalm-type AttendanceGroupOption = array{id: string, displayName: string}
 * @psalm-type AttendanceTeamOption = array{id: string, displayName: string}
 * @psalm-type AttendancePermissionSettings = array<string, list<string>>
 * @psalm-type AttendanceUserPermissions = array{
 *   canManageAppointments: bool,
 *   canCreateAppointments: bool,
 *   canCheckin: bool,
 *   canSeeResponseOverview: bool,
 *   canSeeResponseCounts: bool,
 *   canSeeComments: bool,
 *   canSelfCheckin: bool,
 *   canRespondForOthers: bool,
 * }
 * @psalm-type AttendanceCapabilities = array{
 *   calendarAvailable: bool,
 *   calendarSyncEnabled: bool,
 *   teamsAvailable: bool,
 *   calendarSyncAvailable: bool,
 *   notificationsAppEnabled: bool,
 *   closing: bool,
 *   cancelling: bool,
 *   bookingEnabled: bool,
 *   scheduledFilter: bool,
 *   remindMaybe: bool,
 *   responseToggle: bool,
 *   guestInvitation: bool,
 *   auditLog: bool,
 *   selfCheckin: bool,
 *   organizers: bool,
 *   respondOnBehalf: bool,
 *   responseCounts: bool,
 * }
 * @psalm-type AttendanceAuditUserRef = array{
 *   userId: string,
 *   displayName: string,
 * }
 * @psalm-type AttendanceAuditEvent = array{
 *   id: int,
 *   appointmentId: int,
 *   verb: string,
 *   actorId: ?string,
 *   subjectId: ?string,
 *   meta: array<string, mixed>,
 *   source: ?string,
 *   createdAt: ?string,
 *   actor: ?AttendanceAuditUserRef,
 *   subject: ?AttendanceAuditUserRef,
 * }
 * @psalm-type AttendanceAuditPage = array{
 *   items: list<AttendanceAuditEvent>,
 *   total: int,
 *   hasMore: bool,
 * }
 * @psalm-type AttendanceUserConfig = array{
 *   displayOrder: string,
 *   mobileAppBannerEnabled: bool,
 *   hasPushDevice: bool,
 * }
 * @psalm-type AttendanceAdminReminderConfig = array{
 *   enabled: bool,
 *   reminderDays: int,
 *   reminderFrequency: int,
 *   reminderTarget: string,
 * }
 * @psalm-type AttendanceAdminCalendarSyncConfig = array{
 *   enabled: bool,
 * }
 * @psalm-type AttendanceAdminOrgCalendarConfig = array{
 *   enabled: bool,
 *   calendarUri: ?string,
 *   userId: ?string,
 * }
 * @psalm-type AttendanceWritableCalendar = array{
 *   uri: string,
 *   displayName: string,
 *   color: string,
 * }
 * @psalm-type AttendanceAdminAuditConfig = array{
 *   enabled: bool,
 *   visibility: string,
 * }
 * @psalm-type AttendanceAdminConfig = array{
 *   whitelistedGroups: list<string>,
 *   whitelistedTeams: list<AttendanceTeamOption>,
 *   permissions: AttendancePermissionSettings,
 *   reminders: AttendanceAdminReminderConfig,
 *   calendarSync: AttendanceAdminCalendarSyncConfig,
 *   orgCalendar: AttendanceAdminOrgCalendarConfig,
 *   audit: AttendanceAdminAuditConfig,
 *   displayOrder: string,
 *   pushEnabled: bool,
 *   mobileAppBannerEnabled: bool,
 *   bookingEnabled: bool,
 *   selfCheckinWindowMinutes: int,
 *   guestsApp: AttendanceGuestsAppStatus,
 * }
 * @psalm-type AttendanceAdminStatus = array{
 *   nextAppointment: ?array{name: string, startDatetime: string},
 *   nextReminderRun: ?string,
 *   pushDeviceCount: int,
 * }
 * @psalm-type AttendanceSelfCheckinAppointment = array{
 *   id: int,
 *   name: string,
 *   description: string,
 *   startDatetime: string,
 *   endDatetime: string,
 *   createdBy: string,
 *   createdAt: string,
 *   updatedAt: string,
 *   isActive: int,
 *   visibleUsers: list<string>,
 *   visibleGroups: list<string>,
 *   visibleTeams: list<string>,
 *   calendarUri: ?string,
 *   calendarEventUid: ?string,
 *   seriesId: ?string,
 *   seriesPosition: ?int,
 *   sendNotification: bool,
 *   closedAt: ?string,
 *   cancelledAt: ?string,
 *   responseDeadline: ?string,
 *   alreadyCheckedIn: bool,
 *   checkinState: ?string,
 *   checkinAt: ?string,
 * }
 * @psalm-type AttendanceSelfCheckinNextUpcoming = array{
 *   id: int,
 *   name: string,
 *   startDatetime: string,
 *   checkinWindowStartsAt: string,
 * }
 * @psalm-type AttendanceSelfCheckinOverview = array{
 *   appointments: list<AttendanceSelfCheckinAppointment>,
 *   nextUpcoming: ?AttendanceSelfCheckinNextUpcoming,
 * }
 * @psalm-type AttendanceSelfCheckinResult = array{
 *   appointment: AttendanceAppointmentData,
 *   checkinState: string,
 *   checkinAt: ?string,
 *   alreadyCheckedIn: bool,
 * }
 * @psalm-type AttendancePushConfig = array{
 *   enabled: bool,
 *   proxyServer: string,
 * }
 * @psalm-type AttendanceDeleteResult = array{
 *   deletedCount: int,
 * }
 * @psalm-type AttendanceExportResult = array{
 *   path: string,
 *   filename: string,
 * }
 * @psalm-type AttendanceReminderResult = array{
 *   sent: int,
 * }
 * @psalm-type AttendanceTestReminderResult = array{
 *   sent: int,
 *   appointmentName: string,
 * }
 */
class ResponseDefinitions {
}
