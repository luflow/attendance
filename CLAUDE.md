# Attendance App - Windsurf Rules

## Before you hand work back: run `./scripts/check.sh`

One command, every gate this repo has. **A change is not finished until it
passes.** CI (`.github/workflows/tests.yml`) runs the same set, so green here
means green there — minus the e2e suite, which needs Docker and is opt-in via
`./scripts/check.sh --e2e`.

It covers eslint, stylelint, php-cs-fixer, psalm, PHPUnit, the vite build, the
two l10n checks (`check-german-l10n.py` for the hand-maintained German files,
`check-source-strings.py` for the English strings in `t()` / `n()` calls), and a
check that the generated OpenAPI specs still match the controllers. Every gate
runs even when an earlier one fails, so one invocation gives you the whole
picture. It needs `npm ci` and `composer install` to have run first.

Rules that keep the repo clean over time:

- **Never silence a gate to make it pass.** No `eslint-disable`, no
  `@psalm-suppress`, no `stylelint-disable`, no deleting an assertion. If a
  rule genuinely does not apply, say so in the change description and let a
  human decide.
- **`psalm-baseline.xml` is the known backlog, not a dumping ground.** New
  findings get fixed in the code. Do not run `psalm --set-baseline` to make
  your own errors disappear — that hides them and rewrites 1300 unrelated
  entries. Entries only ever leave the baseline by being fixed.
- **Do not run `psalm --alter`.** Its suggestions are led by deleting
  "unused" methods (this app is DI-driven, so psalm cannot see most call
  sites) and by making classes `final` (PHPUnit cannot mock final classes, so
  it breaks the test suite).
- **`composer openapi` after any controller change**, and commit the
  regenerated specs with it.
- If you touch a dependency, say in the commit message what you deliberately
  held back and why. The PHP 8.1 floor in `appinfo/info.xml` currently pins
  doctrine/dbal, PHPUnit and `nextcloud/ocp` below their latest versions.

## Code Style & Conventions

### Vue.js Frontend
- Use Vue 3 Composition API (`<script setup>`)
- **Translations are handled via Transifex** - do NOT manually add translation files or .po files when building new features (German is the exception, see below)
- **Always use English keys** for `t()` calls in Vue components, never German strings
- Use and Import mainly Nextcloud components from `@nextcloud/vue`
- Styling with CSS in `<style scoped>`
- Use icons from `vue-material-design-icons`
- If you create new views that need routing, add them in vue and update the router configuration of the PHP backend in `appinfo/routes.php`
- When changing the frontend, always build the app with `npm run build`

### Translation Guidelines (Nextcloud Standards)
Translations are managed via **Transifex** and synced automatically. When adding new features, just use `t()` and `n()` with English strings - do NOT create or modify translation files manually.

#### German is hand-maintained, not synced

`de` and `de_DE` are the exception: they are **owned by this repo** and never
come back from Transifex. Nightly syncs kept overwriting reviewed German with
worse wording and broke placeholders, so `.tx/config` maps both to a local
directory starting with a dot, which translationtool's `findLanguages()` skips.
All other languages sync as before.

- Edit `l10n/de.json`, `de.js`, `de_DE.json` and `de_DE.js` **directly** — the
  `l10n-autotranslate` skill fills new strings.
- Keep all four in step: `.js` and `.json` must hold the same entries, and both
  locales the same keys.
- `de` is informal (**du**), `de_DE` is formal (**Sie**). Never mix the two
  inside one string.
- `scripts/check-german-l10n.py` (part of `./scripts/check.sh`) enforces this
  plus placeholders, German quotes „…“, stray whitespace and duplicate keys.
- If a `fix(l10n): Update translations from Transifex` commit ever touches
  `l10n/de*` again, the config lever stopped working — revert those four files
  and fix `.tx/config` rather than accepting the churn.

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
- This is about the wording only — the count still needs `n()` with both forms,
  as under "Plural Forms" above.

### PHP Backend
- Use PHP 8.0+ syntax
- Follow PSR-12 Code Style
- Use Dependency Injection via Nextcloud Container
- All Services in `lib/Service/` directory
- Controllers in `lib/Controller/` directory
- Define API routes in `appinfo/routes.php`
- **Whenever you change backend files (`lib/**`, `appinfo/**`), run `composer test:unit` before handing the change back.** If tests break, update them to match the new behavior or add new ones for the new logic — never leave a red suite behind. When you add a new service or controller, add matching tests in `tests/unit/`. The release workflow also runs PHPUnit in parallel with the e2e tests and will block releases on failure.

### Database
- Always create migrations for schema changes
- Migration naming: `Version{Version}Date{YYYYMMDDHHMMSS}.php` in `lib/Migration/` directory
- Entities in `lib/Db/` directory with corresponding Mapper
- Use QBMapper for database access

#### Writing migrations

A failed migration aborts the whole server upgrade and strands the instance
in maintenance mode — a 1.44.0 migration crashed a production system this
way. Every step in `lib/Migration/` follows these rules, which match how
first-party apps (spreed, mail, forms, deck) structure theirs:

- **Constructor DI of core services only** (`IDBConnection`, `IAppConfig`,
  `IConfig`, `LoggerInterface`, …) — the ecosystem-standard shape. Never
  inject or call app services (`lib/Service/`), mappers, or entities from a
  migration: their DI chain can fail in the half-loaded upgrade context, and
  migrations also run at install and on multi-version jumps (1.40 → 1.50),
  where today's service logic no longer matches the state the step was
  written for. Copy the values a step needs (permission lists, defaults,
  config keys) into the migration as literals — a frozen snapshot, like
  `Version000018` — even though that duplicates a service constant.
- **Schema changes only in `changeSchema()`** via the `ISchemaWrapper` from
  `$schemaClosure()`; data backfills go in `postSchemaChange()`.
- **Idempotent by construction.** After a failed update, `app:enable`
  re-runs pending steps — guard every insert/backfill so a second run is a
  no-op (`hasTable`/`hasColumn` checks, "skip when rows exist" guards,
  re-writing the same value).
- **Executed migrations are frozen.** Never change what a shipped step
  *does* — new behavior means a new `Version...` file. Only repairs that
  keep an old step executable may touch it.

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

### OpenAPI Documentation
The API is documented using Nextcloud's `openapi-extractor`. OpenAPI specs are auto-generated from PHP attributes and docblock annotations.

#### Required PHP attributes on every controller method
- **API endpoints** (return JSON): `#[NoAdminRequired]`, `#[NoCSRFRequired]`, `#[OpenAPI]`
- **Admin-only endpoints**: `#[NoCSRFRequired]`, `#[OpenAPI(OpenAPI::SCOPE_ADMINISTRATION)]` (no `#[NoAdminRequired]`)
- **Public endpoints** (no login): `#[PublicPage]`, `#[NoCSRFRequired]`, `#[OpenAPI]` (or `SCOPE_IGNORE` for non-JSON)
- **Frontend page routes**: `#[NoAdminRequired]`, `#[NoCSRFRequired]`, `#[OpenAPI(OpenAPI::SCOPE_IGNORE)]`

#### Required imports for annotated controllers
```php
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
```

#### Required docblock annotations on every API method
- **`@param`** for each parameter with type and description
- **`@return`** with full Psalm generic type, e.g.:
  ```php
  @return DataResponse<Http::STATUS_OK, array{success: bool}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
  ```
- Use typed arrays (`list<string>`, `list<int>`) instead of bare `array` in both `@param` and method signatures
- **Never use `$this->request->getParam()`** — always use controller method parameters instead

#### Response type definitions
- All reusable `@psalm-type` aliases go in `lib/ResponseDefinitions.php`
- Type names **must** start with `Attendance` (e.g., `AttendanceAppointmentData`, `AttendanceResponseData`)
- When adding a new response shape, define it in `ResponseDefinitions.php` and reference it in the `@return` annotation

#### After any API change
- Run `composer openapi` to regenerate `openapi.json`, `openapi-administration.json`, and `openapi-full.json`
- Commit the updated spec files together with the controller changes

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
- Disable the app via occ command in the running docker containers (container names: master-stable32-1 and master-nextcloud-1)
- Decide which version jump (fix, patch) based on the changes since last version to create and update version numbers in info.xml and package.json
- Enable the app via occ command in the running docker containers (container names: master-stable32-1 and master-nextcloud-1)
- Write release notes in CHANGELOG.md
- Ask me to review the release notes and version number afterwards
- Commit everything you changed after my review WITHOUT claude co author in the commit
- Create a new tag based on the version number
- Push the tag to the remote repository
- Create a new release on GitHub via GitHub MCP which triggers the release process and upload to nextcloud app store

## Mobile (Flutter) compatibility

A Flutter mobile client (repo `luflow/attendance-flutter`) is a first-class
consumer of the API. Older builds stay in users' hands long after the server
is updated, so:

- **Always check API and behavioural changes for breaking impact on the mobile
  client.** Adding a required field to a response, removing a field, changing
  semantics of an endpoint, or removing an endpoint are all breaking. Ask
  yourself: does an old client still work against this server?
- **For new client-visible features, expose a feature flag in
  `getCapabilities()`** (`AppointmentController::getCapabilities`,
  psalm-type `AttendanceCapabilities`). The mobile client gates UI on these
  flags so that older app versions don't show buttons that hit endpoints
  the server they're talking to may also be older than. New feature → new
  capability key, default `false` semantics on absence.
- Examples already in the wild: `calendarSyncAvailable`, `teamsAvailable`,
  `notificationsAppEnabled`, `closing` (close-inquiry feature).
- When you add a capability, also add the corresponding gate on the Flutter
  side in the same change (or a paired PR), so the rollout is atomic.
- Migrations may add nullable columns freely, but **do not remove columns**
  the mobile client may still send/expect — schedule a deprecation cycle
  instead.

## Comments: short and meaningful, no bloat

Comment the **why**, never the what. If the code already says it, the comment is
noise. A useful comment is usually **one line**; two is the ceiling, and only for
something a reader would otherwise get wrong or "fix" back.

- No restating the next statement in prose.
- No paragraph-length explanations of a one-line change — that belongs in the
  commit message or the PR, where it stays out of the way.
- No banner or section-divider comments, no scaffolding like `// Step 1:`.
- Deleting a comment that has gone stale beats updating it.

## Avoid
- NO coauthoring of commits with "claude"!
- No hardcoded admin checks - use PermissionService
- No German strings in t() calls
- No direct database access without Mapper
- No client-side file operations (use server-side Nextcloud APIs)
