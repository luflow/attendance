# Attendance App - Windsurf Rules

## Code Style & Conventions

### Vue.js Frontend
- Use Vue 3 Composition API (`<script setup>`)
- All translations must be defined in `l10n/` files (for frontend only in de.js)
- **Always use English keys** for `t()` calls in Vue components, never German strings
- Use and Import mainly Nextcloud components from `@nextcloud/vue`
- Styling with CSS in `<style scoped>`
- Use icons from `vue-material-design-icons`
- If you create new views that need routing, add them in vue and update the router configuration of the PHP backend in `appinfo/routes.php`
- When changing the frontend, always build the app with `npm run build`

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
- Then check all changes since last release (use git log --oneline)
- Update version numbers in info.xml
- Write release notes in CHANGELOG.md
- Ask me to review the release notes and version number afterwards

## Avoid
- No hardcoded admin checks - use PermissionService
- No German strings in t() calls
- Do NOT add translations to de.json, if the changes are only made in the frontend!
- No direct database access without Mapper
- No client-side file operations (use server-side Nextcloud APIs)
