# Attendance App - Windsurf Rules

## Code Style & Conventions

### Vue.js Frontend
- Use Vue 3 Composition API (`<script setup>`)
- All translations must be defined in `translationfiles/` folder for all languages
- **Always use English keys** for `t()` calls in Vue components, never German strings
- Use and Import mainly Nextcloud components from `@nextcloud/vue`
- Styling with CSS in `<style scoped>`
- Use icons from `vue-material-design-icons`
- If you create new views that need routing, add them in vue and update the router configuration of the PHP backend in `appinfo/routes.php`
- When changing the frontend, always build the app with `npm run build`

### Translation Guidelines (Nextcloud Standards)
Follow these Nextcloud translation guidelines (see https://docs.nextcloud.com/server/latest/developer_manual/basics/translations.html):

#### Capitalization
- **Only capitalize the first word** of a sentence/label, not every word
- Correct: `Create appointment`, `Calendar subscription`, `Response summary`
- Wrong: `Create Appointment`, `Calendar Subscription`, `Response Summary`
- Exception: Proper nouns like "Nextcloud" or "Attendance" (app name)

#### Success/Feedback Messages
- **Never use "successfully"** in feedback messages - it's redundant
- Correct: `Settings saved`, `Response updated`, `Appointment created`
- Wrong: `Settings saved successfully`, `Response updated successfully`

#### Ellipsis (…) Spacing
- **Add a non-breaking space** (`\u00A0`) before the ellipsis when trimming sentences
- Correct: `Loading …`, `Search users …`, `Add your comment …`
- Wrong: `Loading…`, `Search users...`, `Add your comment...`
- Use the Unicode ellipsis character `…` (U+2026), not three dots

#### Format String Placeholders (PHP)
- **Use numbered placeholders** (`%1$s`, `%2$s`) instead of positional (`%s`)
- This allows translators to reorder placeholders for different languages
- Correct: `$l->t('Response missing: %1$s on %2$s', [$name, $date])`
- Wrong: `$l->t('Response missing: %s on %s', [$name, $date])`

#### Complete Sentences
- **Never use incomplete sentences** that rely on adjacent HTML elements
- Include placeholders in the translation string itself
- Correct: `t('attendance', 'You are answering as {user}', { user: userName })`
- Wrong: `t('attendance', 'You are answering as')` followed by `<strong>{{ userName }}</strong>`

#### Plural Forms
- **Use `n()` function** for strings with counts that need singular/plural forms
- Correct: `n('attendance', '{count} attendee not checked in', '{count} attendees not checked in', count, { count })`
- Wrong: `t('attendance', '{count} attendees not yet checked in', { count })`

#### Confirmation Dialogs
- **Keep confirmation language simple** - avoid words like "really" or "all"
- Correct: `Do you want to set {count} users to {action}?`
- Wrong: `Do you really want to set all {count} users to {action}?`

### PHP Backend
- Use PHP 8.0+ syntax
- Follow PSR-12 Code Style
- Use Dependency Injection via Nextcloud Container
- All Services in `lib/Service/` directory
- Controllers in `lib/Controller/` directory
- Define API routes in `appinfo/routes.php`

### Database
- Always create migrations for schema changes
- Migration naming: `Version{Version}Date{YYYYMMDDHHMMSS}.php` in `lib/Migration/` directory
- Entities in `lib/Db/` directory with corresponding Mapper
- Use QBMapper for database access

### Permissions & Security
- Use `PermissionService` for all permission checks (not directly `isAdmin()`)
- Two main permissions: `PERMISSION_MANAGE_APPOINTMENTS` and `PERMISSION_CHECKIN`
- Perform permission checks in both backend (Service layer) and frontend
- If more permissions are needed, add them to the PermissionService and use consistent naming

## Project-Specific Patterns

### Group Summary
- Shows responses grouped by Nextcloud groups
- "Others" section for users without whitelisted group
- Expandable sections for detailed response view

### API Conventions
- Follow RESTful conventions
- Use proper HTTP status codes

## Build & Dependencies
- `package.json` for npm dependencies
- `composer.json` for PHP dependencies
- Vite as build tool (vite.config.js)
- Node version defined in `.nvmrc`

## Debugging Discipline
- Identify root cause before implementation
- Prefer minimal upstream fixes over downstream workarounds
- Add regression tests, but keep implementation minimal

## Release Management
- When I ask you to prepare a release, check if everything is commited
- Then check all changes since last release (use for example git log --oneline)
- Disable the app via occ command in the running docker container (container name master-stable31-1)
- Decide which version jump (fix, patch) based on the changes since last version to create and update version numbers in info.xml and package.json
- Enable the app via occ command in the running docker container (container name master-stable31-1)
- Write release notes in CHANGELOG.md
- Ask me to review the release notes and version number afterwards
- Commit everything you changed after my review WITHOUT claude co author in the commit
- Create a new tag based on the version number
- Push the tag to the remote repository
- Create a new release on GitHub via GitHub MCP which triggers the release process and upload to nextcloud app store

## Avoid
- NO coauthoring of commits with "claude"!
- No hardcoded admin checks - use PermissionService
- No German strings in t() calls
- No direct database access without Mapper
- No client-side file operations (use server-side Nextcloud APIs)
