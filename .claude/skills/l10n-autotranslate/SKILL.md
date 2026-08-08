---
name: l10n-autotranslate
description: Keep the German l10n files in step with the code — fill every source string that has no German yet and drop entries whose source string is gone, writing into l10n/de.json, de.js, de_DE.json and de_DE.js. German is hand-maintained in this repo and never comes back from Transifex, so this is the only way new German reaches the app. Use when the user wants to auto-translate missing strings to German, remove stale/orphaned translation keys, mentions "l10n-autotranslate", "alles auf Deutsch übersetzen", "fehlende Übersetzungen ergänzen", "stale strings entfernen", "translate missing strings", or after adding/renaming/removing t()/n() strings that should show up in German immediately.
---

# German translations (owned by this repo)

Transifex is the source of truth for every language **except German**. `de` and
`de_DE` are excluded from the sync in `.tx/config` — both are mapped to a local
directory starting with a dot, which translationtool's `findLanguages()` skips,
so `l10n/de.*` and `l10n/de_DE.*` are never regenerated. Repeated syncs had been
overwriting reviewed German with worse wording and broken placeholders.

That makes this skill the **canonical path**: a string added with a fresh `t()` /
`n()` call stays English until someone runs it. Nothing downstream will fix it
later.

**What that means in practice:**

- Entries you add are **permanent**. They are not a stopgap that the next sync
  tidies up, so write them as the wording you want to ship. Sloppy German stays
  sloppy.
- Same for removals: stale keys are never cleaned up by a sync either, so
  Step 5 is part of the job, not an optional extra.
- Only ever touch **German** (`de` and `de_DE`). Never other locales — those
  still come from Transifex and hand-edits there get overwritten.
- `de` is informal (**du**), `de_DE` is formal (**Sie**). Never mix the two
  inside one string. See Step 2.
- Never invent or change **source** strings. If a missing string looks wrong
  (a sentence fragment, a missing placeholder, a manual plural split), fix it at
  the source per `CLAUDE.md` and the `transifex` skill instead of papering over
  it with a translation.

## Step 1 — Find what's untranslated

Run from the attendance repo root:

```bash
python3 .claude/skills/l10n-autotranslate/scripts/find_untranslated.py
```

It scans `t('attendance', …)` / `n('attendance', …)` in `src/` and
`$l->t()` / `$l->n()` in `lib/` (the same strings Transifex extracts, including
the mobile-only block in `src/App.vue`), then prints JSON of every source string
missing from `l10n/de.json`:

```json
{"count": 12, "missing": [
  {"key": "Show QR code", "kind": "string"},
  {"key": "_%n attendee_::_%n attendees_", "kind": "plural",
   "singular": "%n attendee", "plural": "%n attendees"}
]}
```

`kind: "plural"` entries use Nextcloud's `_singular_::_plural_` key form and
need a two-element German array `[singular, plural]` (German has `nplurals=2`).

## Step 2 — Translate into a map file

Write German for each missing entry into a JSON map (English key → German
string, or → `[singular, plural]` for plurals). Follow the Nextcloud German
conventions already used across `l10n/de.json`:

- **Address form differs per locale.** `de` is informal (`Öffne …`, `Scanne …`,
  `Wähle …`), `de_DE` is formal (`Öffnen Sie …`, `Scannen Sie …`). Most strings
  never address the user at all (`QR-Code anzeigen`, `Kategoriename`) and are
  identical in both — write those once. Only when the German says "du" or "Sie",
  or uses a second-person imperative, give both forms (see the map format
  below). Getting this wrong fails `scripts/check-german-l10n.py`.
- **Sentence case**: capitalize only the first word (and nouns/proper names).
- **No "successfully"** wording; keep it plain.
- **Keep placeholders byte-for-byte**: `{name}`, `{count}`, `%1$s`, `%n` must
  survive unchanged and stay meaningful in the German word order.
- **Ellipsis**: keep the non-breaking space + `…` (`…` = U+2026) exactly as in
  the source key (many keys contain ` …`).
- **Reuse existing wording** for consistency — grep `l10n/de.json` for a
  neighbouring term before coining a new one:

  ```bash
  python3 -c "import json; d=json.load(open('l10n/de.json'))['translations']; \
    print([ (k,v) for k,v in d.items() if 'checkin' in k.lower().replace('-','') ])"
  ```

Save the map, e.g. to a scratch file. A plain value goes into both locales; an
object gives `de` and `de_DE` their own wording:

```json
{
  "Show QR code": "QR-Code anzeigen",
  "Write NFC tag": "NFC-Tag beschreiben",
  "_%n attendee_::_%n attendees_": ["%n Teilnehmer", "%n Teilnehmer"],
  "Create your first appointment": {
    "de": "Erstelle deinen ersten Termin",
    "de_DE": "Erstellen Sie Ihren ersten Termin"
  },
  "_%n day left_::_%n days left_": {
    "de": ["Noch %n Tag für dich", "Noch %n Tage für dich"],
    "de_DE": ["Noch %n Tag für Sie", "Noch %n Tage für Sie"]
  }
}
```

Both `de` and `de_DE` must be present in an object — a half-filled one is
rejected rather than silently copied.

## Step 3 — Apply to all four files

```bash
python3 .claude/skills/l10n-autotranslate/scripts/apply_translations.py MAP.json --dry-run
python3 .claude/skills/l10n-autotranslate/scripts/apply_translations.py MAP.json
```

The script appends each entry to `l10n/de.json`, `l10n/de.js`,
`l10n/de_DE.json` and `l10n/de_DE.js`, resolving per-locale values on the way
and preserving the exact translationtool formatting (`"key" : value`, arrays as
`["a","b"]`, no trailing comma on the last entry). It is **idempotent** — keys
already present are skipped, so it is safe to re-run and to run in several
batches. `--dry-run` previews the rendered lines per file, which is the quickest
way to confirm `de` and `de_DE` really got the wording you intended.

## Step 4 — Verify

1. Re-run `find_untranslated.py` — `count` should be `0` (or only the strings
   you deliberately left for the source-fix path).
2. `python3 scripts/check-german-l10n.py` must pass. It is the gate that used to
   be Transifex's job: placeholders against the source string, German quotes
   „…“, stray whitespace, duplicate keys, `.js`/`.json` agreement, key parity
   across the two locales, and informal address leaking into `de_DE`.
3. `npm run build` must pass (never commit the `js/` / `css/` app build output —
   only the hand-edited `l10n/*.js|json`).
4. Sanity-check the diff: only additive lines in the four `l10n/de*` files, no
   reordering of existing entries.
5. Commit on the working branch as `fix(l10n): add German for untranslated
   strings` (this repo's convention: no Claude co-author).

`./scripts/check.sh` runs steps 2 and 3 along with every other gate.

## Step 5 — Remove stale strings

When source strings get renamed or removed, their old German entries linger in
the l10n files. No sync prunes them any more, so this belongs to every run, not
just the ones that add strings. Find them:

```bash
python3 .claude/skills/l10n-autotranslate/scripts/find_stale.py
```

It reports two buckets:

- **orphaned** — the exact English literal appears in **no** source file, so no
  code can reach the key (Transifex could not have extracted it either). These
  are safe to remove.
- **review** — the key is not extracted, but its literal still shows up in
  source. Each carries a `reason`:
  - `whitespace/ellipsis-drift` — the live string uses a non-breaking space
    before `…` and this entry a plain one (or vice versa). The right fix is on
    the **source** side (make the `t()` string match), not deleting German.
  - `plural-bare-form` — a `%n …` half of a live `_%n …_::_%n …_` plural key;
    usually harmless, leave it.
  - `literal-present-in-source` — the string (or a superstring of it) is still
    in the code; likely a real use the extractor missed. **Do not remove** —
    grep the code, and widen `find_untranslated.py` if it is a genuine miss.

Only ever remove keys you have confirmed are dead. Pass a JSON list of keys, or
`find_stale.py`'s output directly (its `orphaned` list is used, `review` is
ignored):

```bash
python3 .claude/skills/l10n-autotranslate/scripts/find_stale.py > /tmp/stale.json
python3 .claude/skills/l10n-autotranslate/scripts/remove_stale.py /tmp/stale.json --dry-run
python3 .claude/skills/l10n-autotranslate/scripts/remove_stale.py /tmp/stale.json
```

`remove_stale.py` drops the keys from all four files, re-commas so the last
entry never keeps a trailing comma, and is idempotent. Re-run `find_stale.py`
(orphaned should be empty), then verify exactly as in Step 4 —
`scripts/check-german-l10n.py` catches it if a removal left the two locales or
the `.js`/`.json` pair out of step.
