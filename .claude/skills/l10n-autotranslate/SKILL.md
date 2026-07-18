---
name: l10n-autotranslate
description: Keep the German l10n files in step with the code — fill every source string that has no German yet and drop entries whose source string is gone, writing into l10n/de.json, de.js, de_DE.json and de_DE.js so the app is fully German without waiting for the next Transifex sync. Use when the user wants to auto-translate missing strings to German, remove stale/orphaned translation keys, mentions "l10n-autotranslate", "alles auf Deutsch übersetzen", "fehlende Übersetzungen ergänzen", "stale strings entfernen", "translate missing strings", or after adding/renaming/removing t()/n() strings that should show up in German immediately.
---

# German auto-translate (stopgap for missing translations)

Transifex is the **source of truth** for translations. The `l10n/*.js` and
`l10n/*.json` files are generated from the Transifex `.po` files, so any string
added with a fresh `t()` / `n()` call stays untranslated (falls back to English)
until the next `tx pull` + convert. This skill fills that gap: it finds the
untranslated source strings and writes German for them straight into the four
German l10n files, for immediate local coverage.

**Important — this is a stopgap, not the canonical path:**

- The repo's `CLAUDE.md` says translation files are managed via Transifex and
  should not be hand-edited. This skill deliberately does, and the entries it
  adds will be **overwritten (or re-matched) on the next Transifex sync**. That
  is fine and expected — the point is to not ship English-looking UI in the
  meantime.
- Only ever touch **German** (`de` and `de_DE`). Never other locales.
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

- **Informal "du"** (`Öffne …`, `Scanne …`, `Wähle …`) — never "Sie".
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

Save the map, e.g. to a scratch file:

```json
{
  "Show QR code": "QR-Code anzeigen",
  "Write NFC tag": "NFC-Tag beschreiben",
  "_%n attendee_::_%n attendees_": ["%n Teilnehmer", "%n Teilnehmer"]
}
```

## Step 3 — Apply to all four files

```bash
python3 .claude/skills/l10n-autotranslate/scripts/apply_translations.py MAP.json --dry-run
python3 .claude/skills/l10n-autotranslate/scripts/apply_translations.py MAP.json
```

The script appends each entry to `l10n/de.json`, `l10n/de.js`,
`l10n/de_DE.json` and `l10n/de_DE.js`, preserving the exact translationtool
formatting (`"key" : value`, arrays as `["a","b"]`, no trailing comma on the
last entry). It is **idempotent** — keys already present are skipped, so it is
safe to re-run and to run in several batches. `--dry-run` previews without
writing.

## Step 4 — Verify

1. Re-run `find_untranslated.py` — `count` should be `0` (or only the strings
   you deliberately left for the source-fix path).
2. `npm run build` must pass (never commit the `js/` / `css/` app build output —
   only the hand-edited `l10n/*.js|json`).
3. Sanity-check the diff: only additive lines in the four `l10n/de*` files, no
   reordering of existing entries.
4. Commit on the working branch as `fix(l10n): add German for untranslated
   strings` (this repo's convention: no Claude co-author).

## Step 5 — Remove stale strings (optional)

When source strings get renamed or removed, their old German entries linger in
the l10n files. Find them:

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
(orphaned should be empty), then `npm run build` and verify `.js` and `.json`
still agree per locale, exactly as in Step 4.
