# API migration guide (v1.32 to v1.33)

This document describes the breaking API changes for consumers (Flutter app, external integrations).

## New endpoints

### `GET /api/capabilities`

Returns system-wide capabilities. No authentication required beyond Nextcloud login.

**Response:**
```json
{
  "calendarAvailable": true,
  "calendarSyncEnabled": true,
  "teamsAvailable": true,
  "calendarSyncAvailable": true,
  "notificationsAppEnabled": true
}
```

### `GET /api/user/config`

Returns user-relevant app configuration.

**Response:**
```json
{
  "displayOrder": "name_first"
}
```

## Changed endpoints

### `GET /api/user/permissions`

**Removed fields:** `calendarAvailable`, `calendarSyncEnabled`, `displayOrder`

These are now available via `GET /api/capabilities` and `GET /api/user/config`.

**Before:**
```json
{
  "canManageAppointments": true,
  "canCheckin": true,
  "canSeeResponseOverview": true,
  "canSeeComments": true,
  "canSelfCheckin": true,
  "calendarAvailable": true,
  "calendarSyncEnabled": true,
  "displayOrder": "name_first"
}
```

**After:**
```json
{
  "canManageAppointments": true,
  "canCheckin": true,
  "canSeeResponseOverview": true,
  "canSeeComments": true,
  "canSelfCheckin": true
}
```

### `GET /api/admin/settings`

Response is now structured into `config`, `status`, and `groups`.

Capabilities (`teamsAvailable`, `calendarSyncAvailable`, `notificationsAppEnabled`) are now served by `GET /api/capabilities`.

**Before:**
```json
{
  "groups": [...],
  "whitelistedGroups": [...],
  "whitelistedTeams": [...],
  "teamsAvailable": true,
  "permissions": {...},
  "reminders": {
    "enabled": true,
    "reminderDays": 7,
    "reminderFrequency": 0,
    "notificationsAppEnabled": true,
    "nextAppointment": {...},
    "nextReminderRun": "..."
  },
  "calendarSync": {"enabled": true, "available": true},
  "displayOrder": "name_first"
}
```

**After:**
```json
{
  "config": {
    "whitelistedGroups": [...],
    "whitelistedTeams": [...],
    "permissions": {...},
    "reminders": {
      "enabled": true,
      "reminderDays": 7,
      "reminderFrequency": 0
    },
    "calendarSync": {"enabled": true},
    "displayOrder": "name_first"
  },
  "status": {
    "nextAppointment": {"name": "...", "startDatetime": "..."},
    "nextReminderRun": "2026-03-23 10:00:00"
  },
  "groups": [{"id": "admin", "displayName": "Admin"}, ...]
}
```

### `POST /api/admin/settings`

**Before:** returned `{"success": true}`
**After:** returns `{}`

### `DELETE /api/appointments/{id}`

**Before:** returned `{"success": true, "deletedCount": 1}`
**After:** returns `{"deletedCount": 1}`

### `DELETE /api/appointments/{id}/checkin-reset`

**Before:** returned `{"success": true}`
**After:** returns `{}`

### `POST /api/export`

**Before:** returned `{"success": true, "path": "...", "filename": "..."}`
**After:** returns `{"path": "...", "filename": "..."}`

## Status code fixes

- `POST /api/appointments` now correctly returns `201 Created` (was `200 OK` in the OpenAPI spec)
- `POST /api/appointments/bulk` now correctly returns `201 Created` (was `200 OK` in the OpenAPI spec)

Note: The actual HTTP status codes were already correct in the implementation; only the OpenAPI documentation was fixed.
