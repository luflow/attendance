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
 *   calendarUri: ?string,
 *   calendarEventUid: ?string,
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
 *   userName: string,
 *   userGroups: list<string>,
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
 *   calendarUri: ?string,
 *   calendarEventUid: ?string,
 *   userResponse: AttendanceResponseData|null,
 *   responseSummary: array<string, int>,
 *   attachments: list<array<string, mixed>>,
 * }
 * @psalm-type AttendanceNavigationAppointment = array{
 *   id: int,
 *   name: string,
 *   startDatetime: string,
 *   userResponse: ?array{response: string},
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
 * }
 * @psalm-type AttendanceGroupOption = array{id: string, displayName: string}
 * @psalm-type AttendanceTeamOption = array{id: string, displayName: string}
 * @psalm-type AttendancePermissionSettings = array<string, list<string>>
 * @psalm-type AttendanceReminderSettings = array{
 *   enabled: bool,
 *   reminderDays: int,
 *   reminderFrequency: int,
 *   notificationsAppEnabled: bool,
 *   nextAppointment: ?array{name: string, startDatetime: string},
 *   nextReminderRun: ?string,
 * }
 * @psalm-type AttendanceCalendarSyncSettings = array{enabled: bool, available: bool}
 */
class ResponseDefinitions {
}
