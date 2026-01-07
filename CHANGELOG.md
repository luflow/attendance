# Changelog

## [Unreleased]

### Added

- Nightly changes here

## 1.21.0 – 2026-01-07

### Added

- Search filter for calendar list in import dialog (shown when more than 5 calendars)
- Search filter for events list in import dialog (shown when more than 5 events)
- Calendar names are now displayed in the user's language (e.g., "Personal" shows as "Persönlich" for German users)

### Fixed

- Deleted calendars (in trash bin) no longer appear in calendar import list

## 1.20.0 – 2026-01-06

### Added

- Calendar integration for importing appointments from Nextcloud Calendar
  - Import calendar events via a new calendar picker when creating appointments
  - Appointments are linked to their source calendar event (URI + UID stored)
  - Deep link to the source event in Calendar app from appointment cards
  - Optional automatic sync: when enabled, changes to calendar events update linked appointments (title, description, date/time)
  - Admin setting to enable/disable calendar sync (requires Nextcloud 32+)

### Fixed

- Confetti celebration now works on Nextcloud 32+ (disabled Web Worker to comply with stricter CSP)

## 1.19.0 – 2026-01-06

### Added

- Teams (Circles) support for visibility settings
  - Appointments can now be restricted to specific teams in addition to users and groups
  - Teams appear with a star icon to distinguish them from groups
  - Requires the Circles/Teams app to be enabled
- Teams support for Response Summary
  - Configure teams in admin settings to organize responses by team membership
  - Team sections display alongside group sections with star icons
  - Users can appear in both group and team sections if they belong to both
- Visibility mismatch warning now includes teams
  - Warning appears when selected teams are not in Response Summary Teams configuration

## 1.18.3 – 2026-01-01

### Fixed

- Removed unused preview styling in markdown editor
- Fixed cursor visibility in markdown editor
- Fixed selected text styling in markdown editor

## 1.18.2 – 2026-01-01

### Fixed

- Markdown editor styling on mobile devices now displays correctly
- Disabled FontAwesome download (uses Nextcloud Material Design icons instead)

## 1.18.1 – 2026-01-01

### Fixed

- Reminder notifications now respect appointment visibility settings
  - Users who are not in the visible users/groups list will no longer receive reminders
  - Previously, reminders were sent to all users in the system regardless of visibility
- Improved reminder job performance by only querying appointments within the reminder window

## 1.18.0 – 2025-12-31

### Added

- Markdown editor for easier rich text formatting when creating or editing appointment descriptions
  - Supports bold, italic, strikethrough, lists, links, quotes

### Changed

- Improved comment UX with collapsible input and focus management
  - Comment input is now collapsed by default, saving vertical space
  - Clicking the comment button expands the input and auto-focuses it

## 1.17.0 – 2025-12-30

### Added

- File attachment support for appointments
  - Attach files from Nextcloud Files to appointments
  - Attachments are displayed in appointment details for all users who can view the appointment
- Unanswered appointments banner on the unanswered view
  - Shows count of appointments awaiting response with proper singular/plural translations
  - Confetti celebration when all appointments are answered
  - Quick navigation button to upcoming appointments
- Collapsible past appointments navigation
  - Past appointments menu is now collapsed by default
  - Expands only when user clicks on "Past Appointments"

## 1.16.1 – 2025-12-28

### Changed

- Updated project website URL to anwesenheit.app
- Added Stripe donation link to app metadata

## 1.16.0 – 2025-12-27

### Added

- Quick response links in appointment notifications allowing one-click RSVP directly from the notification panel
  - Users can respond Yes/No/Maybe without logging in
  - Secure token-based authentication with HMAC-SHA256 signing
  - Confirmation page shows appointment details before submitting response
  - Links automatically expire after appointment end time
- Copy appointment functionality to quickly duplicate existing appointments with all settings

### Security

- Added brute force protection to quick response endpoints to prevent token guessing attacks

## 1.15.2 – 2025-12-25

### Added

- Formal German translation (de_DE) with "Sie" form for professional environments

## 1.15.1 – 2025-12-25

### Fixed

- iCal feed now properly syncs updates to Apple Calendar and other calendar apps
  - Added LAST-MODIFIED and SEQUENCE properties to VEVENT for update detection
  - DTSTAMP now reflects actual modification time instead of current time
  - Response changes are now tracked and trigger calendar updates
- Added URL property to iCal events for clickable link in calendar event details

## 1.15.0 – 2025-12-25

### Added

- Quick subscribe buttons in iCal feed modal for one-click subscription to Google Calendar and Apple Calendar

### Maintenance

- E2E tests now compatible with Nextcloud 32
- Refactored login helper for better test maintainability

## 1.14.0 – 2025-12-25

### Added

- iCal feed for subscribing to appointments in external calendar apps (Google Calendar, Apple Calendar, Thunderbird)
  - Personal feed URL with secure token authentication
  - Response status shown in event title (Me: Yes/No/Maybe/?)
  - Link to view or change response in event description
  - Regenerate URL option to invalidate compromised tokens
  - Respects user's Nextcloud language preference for translations

## 1.13.2 – 2025-12-24

### Fixed

- Empty groups no longer appear in response summaries when visibility restrictions filter out all users in a group

## 1.13.1 – 2025-12-24

### Fixed

- Check-in lists now only show targeted users (users who should attend based on visibility settings) and no admin users additionally anymore

### Maintenance

- Performance optimizations
- Better code organization

## 1.13.0 – 2025-12-23

### Added

- Notification support for new appointments: Users now optionally receive a Nextcloud notification when a new appointment is created

### Fixed

- Export functionality now works reliably by fixing variable shadowing in ExportService
- Select components in AdminSettings now work better also when searching for a group name with already selected groups
- Avatar size increased in CheckinUserItem for better visibility of attendee images

### Maintenance

- Standardized translation file format with auto-generation support

## 1.12.1 – 2024-12-22

### Fixed

- Appointment responses and non-responders now properly filtered based on visibility restrictions
- Improved admin settings labels with visibility restriction warnings to clarify feature distinctions

### Maintenance

- Refactored comment handling in dashboard widget for better code organization
- Simplified and centralized API calls to reduce repetition
- Added e2e test for comment persistence verification

## 1.12.0 – 2024-12-20

### Added

- Appointment visibility controls with user and group filtering
- Filter response summary and user lists based on appointment visibility settings
- Granular control over who can see specific appointments

## 1.11.0 – 2024-12-18

### Added

- Group ordering in response summary now follows the configured order of the admin settings
- Mobile-optimized appointment creation and edit forms

## 1.10.0 – 2024-12-08

### Added

- Alphabetical sorting for responses and non-responding users in response summary for better overview

### Maintenance

- Removed unused CSS styles from AppointmentDetail and ResponseSummary components

## 1.9.0 – 2024-12-07

### Added

- End-to-end testing infrastructure with Playwright for improved code quality and reliability
- Automated e2e tests in release workflow to ensure quality before publishing

### Fixed

- Dashboard widget: Removed unnecessary "show more" link
- Improved dark mode styling for warning button text color with better CSS selectors

### Maintenance

- Refactored permissions loading into shared composable for better code organization
- Extracted shared styles for comment auto-save indicators
- Improved German translation consistency by using informal "du" form throughout the app

## 1.8.1 – 2024-12-04

### Added

- Configurable reminder frequency setting (0-30 days) to control notification frequency and prevent spam
- Reminder logging system to track when users were last notified about appointments
- Auto-navigation to unanswered appointments view on app load when unanswered appointments exist

### Fixed

- Fixed permission mapping issue in PermissionService handling uppercase constants

## 1.8.0 – 2024-12-04

### Added

- Dedicated "Unanswered" appointments view with navigation section to quickly identify appointments without responses
- Display appointment start date and time in sidebar navigation items for better overview
- Appointment reminder system via Nextcloud notifications to notify users about upcoming appointments

### Fixed

- Improved icon clarity and dark mode styling for appointment responses

### Maintenance

- Removed unused translation strings and fixed inconsistent capitalization in Danish and German locales
- Removed unused OpenAPI extractor tooling and documentation
- Removed unused GitHub workflow files for linting and npm audit automation

## 1.7.0 – 2025-11-29

### Added

- Granular permission controls for viewing response overview and comments
  - New "See Response Overview" permission setting in admin settings to control who can view detailed response statistics
  - New "See Comments" permission setting to control who can view and add comments on appointments
  - Response overview and comment sections are now hidden based on user permissions
- Navigate automatically to newly created appointment detail view after creation for better UX

### Fixed

- Comments not being saved or displayed correctly with new autosave functionality introduced in last version
- Added error handling with visual feedback (red X icon) for failed comment saves
- Better handling of error when using the response (yes, no, maybe) buttons

## 1.6.0 – 2025-11-29

### Added

- Auto-save functionality for comments with visual feedback (spinner while saving, green checkmark on success)
- Collapsible comment field in dashboard widget with toggle button for cleaner interface
- Added Response status icons in sidebar navigation (checkmark for Yes, circle for Maybe or No Answer yet, X for No)
- Dashboard widget now shows up to 10 appointments instead of 5

### Fixed

- Small issues leading to log spamming fixed

## 1.5.0 – 2025-11-28

### Added

- Export functionality: Export all appointments to ODS (spreadsheet) format
  - Generates table with user names, groups, and RSVP/Check-in status per appointment
  - Automatically navigates to Attendance folder in Files app after export
  - Translated response values (Yes/No/Maybe) based on user language
  - Three-row header structure: appointment names, dates, and RSVP/CheckIn labels
- Danish (da) translation with complete localization coverage for all UI elements

## 1.4.0 – 2025-11-28

### Added

- Adds main menu for app for faster access to single appointments
- Appointments are now directly linkable, new action "Share Link" in each appointment available
- Markdown rendering support for appointment descriptions in check-in view
- Global Nextcloud version detection for CSS compatibility layers

### Changed

- Appointment creation form now accessible from main navigation menu
- Updated all dependencies to be compatible with NextCloud 32
- Updated all buttons to use modern `variant` API instead of deprecated `type` prop

### Fixed

- Textarea placeholders now remain visible until text is entered in comment sections

## 1.3.0 – 2025-09-02

### Added

- Added "Others" section to group responses in all appointment list for users not in whitelisted groups
- Better behavior of lists no not jump back to beginning when clicking buttons
- Added check-in status indicator with improved dark theme contrast for "maybe" buttons
- Added group-based permissions for managing appointments and check-ins


## 1.2.0 – 2025-08-31

### Added

- Added check-in feature to track attendance at the event including checkin comments
- Added settings screen to configure allowed user groups

## 1.1.1 – 2025-08-28

### Fixed

- Fixed version number in info.xml

## 1.1.0 – 2025-08-28

### Added

- Added screenshots
- Added appointment end time auto setting to start time + 2.5 hours

### Fixed

- Fixed widget translations
- Fixed timezone issues when editing appointments

## 1.0.2 – 2025-08-26

### Fixed

- Fixed color issues in older Nextcloud versions

## 1.0.0 – 2025-08-26

### Added

- Initial release of Attendance app
- Dashboard widget to track attendance with yes/no/maybe responses
- Easy attendance tracking interface
