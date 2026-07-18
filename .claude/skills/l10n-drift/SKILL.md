---
name: l10n-drift
description: Check and fix translation-string drift between the attendance-flutter mobile app and this repo — every string the Flutter app uses must be registered here so Transifex extracts it. Use whenever new strings were added to or removed from the Flutter app, before/after Flutter feature PRs, or when the user says "string abgleich", "sync flutter strings", "translation drift", or asks to copy Flutter strings to the web app.
---

# Flutter ↔ web translation-string drift

Transifex only extracts strings from THIS repo (`t()`/`n()` in `src/`,
`$l->t()` in `lib/`). The Flutter app (`attendance-flutter`, usually checked
out next to this repo) uses the same English strings as easy_localization
keys and receives the translations by copying `l10n/*.json` (see the
`l10n-pull` skill). Mobile-only strings are therefore registered in a
module-scope block in `src/App.vue` (column-0 `t('attendance', …)` lines,
kept alphabetical inside the "Strings that only appear in the Flutter mobile
app" section).

## Step 1 — Run the checker

```bash
python3 .claude/skills/l10n-drift/scripts/check_drift.py
```

Make sure the Flutter checkout is on the branch you want to compare against
(usually `main` or the feature branch being reviewed). The script reports:

- **MISSING** — keys the Flutter app uses that no `t()`/`n()`/`$l->t()` call
  here registers. These would ship untranslated.
- **STALE** — keys in the App.vue registration block that neither the
  Flutter app nor the web app uses anymore. Dead weight for translators.
- **UNRESOLVED** — `.tr()` calls on plain identifiers the script cannot
  resolve statically. Compare them against `data-keys.json` (next to the
  script): if the underlying data (labels, templates, option maps) gained or
  lost entries, update that file and re-run. Dynamic values (e.g. Nextcloud
  group names) are untranslatable — ignore those sites.

## Step 2 — Homogenize before adding (IMPORTANT)

For every MISSING string, first check whether an already-registered string
with the same meaning exists — reusing it means zero new translation work:

```bash
python3 -c "import json; d=json.load(open('l10n/de.json'))['translations']; \
  print([k for k in d if 'SEARCHWORD' in k.lower()])"
```

- If a well-matching translated string exists (e.g. a generic error like
  `Error loading data` / `Something went wrong`, or an audit-log wording),
  **change the Flutter code to use that exact string** instead of adding a
  new one. Meaning must be identical — don't force it: a verb button
  ("Check in") is not the noun ("Check-in"), and platform conventions
  ("Done") may be worth their own string.
- Otherwise register the string in the App.vue block (alphabetical order).

## Step 3 — Fix the drift

- **Add MISSING** to the App.vue block. Mind the repo translation rules
  (CLAUDE.md): sentence-case, no "successfully", ellipsis as ` …`
  (NBSP before `…` — the Flutter side writes `'… …'` in Dart, the key
  must match byte-for-byte), numbered placeholders stay as `{name}`.
- **Remove STALE** lines — but grep the Flutter repo for a literal fragment
  first; the string might be reached through data (then it belongs in
  `data-keys.json`, not deleted).
- Known trap: Dart implicit string concatenation (`"a " "b".tr()`) — the
  key is the FULL concatenated string, never a fragment.
- Strings used by web code but only reached indirectly (e.g. the audit-log
  templates in `src/utils/auditFormat.js`) are registered near their
  definition in that file, not in App.vue.

## Step 4 — Verify and finish

1. Re-run the script — it must exit 0 (no MISSING, no STALE).
2. `npm run build` (never commit `js/`/`css/` build output).
3. If Flutter code changed too, run `flutter analyze` on the touched files.
4. Commit per repo (no Claude co-author), typically as `fix(l10n): …`.
   Server-visible changes may also need the push-proxy repo
   (`attendance-push`) — e.g. new trial-feedback reason codes must be
   whitelisted in `handlers/feedback.go` there.
