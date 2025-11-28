# Changelog

## [Unreleased]

### Added

- Nightly changes here

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
