---
name: l10n-pull
description: Pull the latest Transifex translations from this repo's main branch into the attendance-flutter app (copies l10n/*.json into assets/l10n/ and checks the supportedLocales list). Use when the user wants to update/sync/refresh the mobile app's translations, mentions "Übersetzungen ziehen", "neueste Übersetzungen", or after new strings were translated on Transifex.
---

# Pull translations into the Flutter app

Transifex syncs translations nightly onto this repo's `main` branch
(`l10n/*.json`, commits like "fix(l10n): Update translations from
Transifex"). The Flutter app bundles verbatim copies under `assets/l10n/`
and lists its locales in `lib/services/nextcloud_asset_loader.dart`
(`supportedLocales`).

## Step 1 — Dry run

```bash
python3 .claude/skills/l10n-pull/scripts/pull_translations.py --dry-run
```

Reads `origin/main` via `git show` (fetches first), so the local branch and
working tree of this repo stay untouched. Review the report:

- **changed / new** — locale files that will be updated or added.
- **orphaned** — files in `assets/l10n/` with no upstream counterpart.
  Do NOT delete blindly: check whether the language was really dropped
  upstream or just renamed; removing a locale is user-visible.
- **ADD to supportedLocales** — ready-to-paste `Locale(...)` lines for new
  languages (special cases like `sr@latin` → `Locale.fromSubtags(languageCode:
  'sr', scriptCode: 'Latn')` are handled by the loader; the script knows the
  mapping).

## Step 2 — Apply

```bash
python3 .claude/skills/l10n-pull/scripts/pull_translations.py
```

Then, if the report asked for it, add the new `Locale(...)` entries to
`supportedLocales` in `lib/services/nextcloud_asset_loader.dart` (keep the
list sorted like the surrounding entries).

## Step 3 — Verify and finish

In the Flutter repo:

1. `flutter analyze lib/services/nextcloud_asset_loader.dart` if the loader
   changed.
2. Spot-check one updated file renders as valid JSON with the expected
   structure: `{"translations": {...}, "pluralForm": "..."}`.
3. Commit on a branch (e.g. `chore/l10n-update`) as
   `fix(l10n): Update translations from attendance` — no Claude co-author —
   and offer to open a PR.

Note: run this AFTER new mobile strings have been translated on Transifex
and synced to main; running it earlier is harmless but won't bring the new
strings yet. The counterpart direction (registering new Flutter strings for
extraction) is the `l10n-drift` skill.
