# Attendance

A Nextcloud app for knowing who is coming. Collect replies up front, check people in on the day, and see every response broken down by your Nextcloud groups. Free, open source, and running entirely on your own server.

[Website](https://anwesenheit.app) · [Nextcloud App Store](https://apps.nextcloud.com/apps/attendance) · [Documentation](https://anwesenheit.app/docs)

## Mobile companion app

There is a companion app for iOS and Android. It talks to your own Nextcloud and adds the two things the web app cannot do:

- **Self-check-in by QR code or NFC** — print one QR code for the whole instance or stick an NFC tag next to the door. People scan or tap as they arrive and the attendance list fills itself. Every self-check-in is recorded in the activity history with its source.
- **Push notifications** — new appointments and reminders reach the phone straight away, without anyone opening Nextcloud first.

It also puts appointments, replies and comments on the phone, and holds several Nextcloud accounts in one app.

[App Store](https://apps.apple.com/app/id6759988681) · [Google Play](https://play.google.com/store/apps/details?id=de.krautnerds.attendance) · [What the app does](https://anwesenheit.app/mobile)

The mobile app is free for the first 30 days per Nextcloud instance, then one yearly subscription covers the whole instance. **This Nextcloud app stays free and open source** — everything below works without the mobile app.

## Features

### Collecting replies
- **One-click RSVP:** Yes, No or Maybe, with an optional comment
- **Changed plans:** update or withdraw a reply any time before the appointment
- **Quick-reply links:** answer straight from the notification email via a signed token, no login needed
- **Dashboard widget:** reply from the Nextcloud dashboard without opening the app
- **Guests without an account:** invite people by email address through the Nextcloud Guests app (see below)
- **Reminders:** automatic nudges for everyone who has not replied yet, plus manual reminders

### Organising appointments
- **Recurring appointments:** daily, weekly or monthly series created in one go; edit or delete this one, this and following, or all of them
- **Calendar import:** pull events out of Nextcloud Calendar, with optional automatic sync
- **Closing and deadlines:** close an inquiry by hand, on a deadline, or automatically once it starts
- **Cancel and reactivate:** cancelling is a state of its own, separate from closing
- **Scheduling:** mark the yes-repliers you actually need and notify exactly those people on closing (instance-wide opt-in, off by default)
- **Attachments:** attach files from Nextcloud Files, also exposed as links in the calendar feed
- **Visibility:** restrict an appointment to individual users, groups or Nextcloud Teams (Circles)
- **Copy:** duplicate an appointment with all of its settings

### Overview and reporting
- **Response summary by group:** Nextcloud groups and Teams as separate sections, each with its own counts
- **Check-in:** record actual attendance, with bulk actions, search, filters and a note per person
- **Unanswered:** a dedicated section showing only what still waits for a reply
- **Activity history:** every reply, change, withdrawal and check-in with who, when and from where (web, email link, admin check-in, QR or NFC)
- **Export:** all appointment data as an ODS spreadsheet
- **Calendar subscription:** a personal iCal feed for Google Calendar, Apple Calendar, Outlook or Thunderbird, with token regeneration

### Administration
- **Six group-based permissions:** manage appointments, create own appointments, check-in access, see response and check-in summary, see comments, self-check-in
- **Response summary groups:** choose which groups and Teams appear as sections (this also scopes the check-in list)
- **Reminder settings:** days before the appointment and repeat frequency
- **Self-check-in settings:** which groups may self-check-in, how wide the check-in window is, plus the instance QR code and the NFC URL
- **Calendar sync:** keep imported appointments in step with the source event

## Requirements

- Nextcloud 32 to 34
- PHP 8.1 or newer
- The Notifications app enabled, for reminders and notifications

## Installation

1. Place this app in **nextcloud/apps/**
2. Enable the app in Nextcloud admin settings
3. The database tables will be created automatically via migration

## Guest participation

External participants without a Nextcloud account can be invited by integrating
with the official [Nextcloud Guests app](https://apps.nextcloud.com/apps/guests).
With both apps installed, organizers type an email address into the
appointment's user picker and choose **Create guest account for {email}** —
the Guests app then provisions a guest user, sends them an invitation email,
and adds them to the appointment audience in one step.

Setup:

1. Install the Guests app from the Nextcloud app store and enable it.
2. Add `attendance` to the Guests app whitelist so guests can access it. The
   Attendance admin settings will warn you if this step is missing and offer
   an `occ` command snippet:

   ```bash
   occ config:app:set guests whitelist --value=$(occ config:app:get guests whitelist),attendance
   ```

3. Optionally add the `guests` group to **Response summary groups** in the
   Attendance admin settings to render guests in their own section instead of
   under "Others".

Guest accounts are technically restricted users:

- Guests can submit RSVPs and self-check-in (when permitted), but they can
  never manage appointments or check-in others — this is enforced server-side
  regardless of how groups are configured.
- When a guest later registers a full Nextcloud account with the same email
  (e.g. via SAML/LDAP), the Guests app's automatic conversion takes over.
  Past attendance responses remain attached to the original guest UID; if you
  want to migrate them to the new account, use:

  ```bash
  occ db:execute "UPDATE oc_att_responses SET user_id='<new-uid>' WHERE user_id='<old-uid>'"
  ```

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

