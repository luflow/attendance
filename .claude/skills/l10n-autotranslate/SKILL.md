---
name: l10n-autotranslate
description: Find every source string in the code that has no German translation yet and fill it in — write the missing German into l10n/de.json, de.js, de_DE.json and de_DE.js so the app is fully German without waiting for the next Transifex sync. Use when the user wants to auto-translate missing strings to German, mentions "l10n-autotranslate", "alles auf Deutsch übersetzen", "fehlende Übersetzungen ergänzen", "translate missing strings", or after adding new t()/n() strings that should show up in German immediately.
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
