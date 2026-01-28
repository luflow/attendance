# Attendance

A Nextcloud app for managing event attendance with advance RSVP functionality. Administrators can create appointments and track responses, while users can easily indicate their attendance status and optional comment on their attendance.

## Features

### For Administrators
- **Create & Manage Appointments:** Set up events with name, description, and date/time
- **Track Responses:** View detailed attendance summaries organized by user groups
- **See Non-Responders:** Identify who hasn't responded yet to follow up
- **Flexible Views:** Toggle between upcoming and past appointments
- **Check-in Management:** Track actual attendance during events with dedicated check-in interface
- **Bulk Operations:** Mark multiple users as present/absent with bulk check-in actions
- **Admin Comments:** Add check-in specific comments for attendance tracking
- **Group Whitelisting:** Configure which user groups are included in attendance statistics and check-in lists

### For All Users  
- **Easy RSVP:** Respond with Yes/No/Maybe to any appointment
- **Add Comments:** Include optional notes with your response (especially for Maybe/No responses)
- **Update Anytime:** Change your response until the event date
- **Dashboard Widget:** Quick access to upcoming appointments directly from your dashboard
- **Check-in View:** Dedicated interface for administrators to track actual attendance during events

### Group-Based Organization
- **Group Summaries:** Responses are automatically organized by nextcloud user groups
- **Missing Responses:** See which user group members haven't responded yet
- **Admin Overview:** Complete visibility into attendance across all groups
- **Filtered Check-in:** Search and filter users by name or group during check-in process

## Installation

1. Place this app in **nextcloud/apps/**
2. Enable the app in Nextcloud admin settings
3. The database tables will be created automatically via migration

## Development

### Creating a Release

This repository includes a GitHub Actions workflow for creating new releases. The workflow automatically increments the minor version and adds a "Translations updated" entry to the changelog.

To create a release:

1. Go to the **Actions** tab in the GitHub repository
2. Select the **Create Release** workflow
3. Click **Run workflow**
4. Click **Run workflow** (no inputs required)

The workflow will:
- Read the current version from `package.json`
- Automatically increment the minor version (e.g., 1.23.0 → 1.24.0)
- Check that the new tag doesn't already exist
- Update `package.json` and `appinfo/info.xml` with the new version
- Update `CHANGELOG.md` with the new version entry and "Translations updated" message
- Commit the changes
- Create and push a git tag
- Create a GitHub release

Once the release is created, the existing `release.yml` workflow will automatically:
- Run e2e tests
- Build the app for the appstore
- Upload the tarball to the GitHub release
- Publish to the Nextcloud appstore

**Note**: The repository must not have branch protection rules on the main branch that would prevent the workflow from pushing directly. If branch protection is required, the workflow will need to be modified to create a pull request instead.

