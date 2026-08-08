#!/usr/bin/env python3
"""Insert German translations into the four German l10n files.

Reads a JSON map produced by hand from find_untranslated.py's output:
    {
      "Show QR code": "QR-Code anzeigen",
      "_%n attendee_::_%n attendees_": ["%n Teilnehmer", "%n Teilnehmer"],
      "Create your first appointment": {
        "de": "Erstelle deinen ersten Termin",
        "de_DE": "Erstellen Sie Ihren ersten Termin"
      }
    }
and appends every not-yet-present entry to all of:
    l10n/de.json  l10n/de.js  l10n/de_DE.json  l10n/de_DE.js
preserving the Nextcloud translationtool formatting exactly (4-space indent,
`"key" : value`, arrays as ["a","b"], no trailing comma on the last entry).

A plain string (or [singular, plural]) goes into both locales unchanged, which
is right for anything that does not address the user. Whenever the German does
address the user, give the {"de": …, "de_DE": …} form instead: de is informal
(du), de_DE is formal (Sie), and scripts/check-german-l10n.py rejects informal
address in de_DE.

Idempotent: keys already present in a file are skipped, so re-running is safe.
Only appends — never rewrites or reorders existing entries. Use --dry-run to
preview counts and the rendered lines without touching the files.
"""
import argparse
import json
import os
import re
import sys

# path -> regex whose match start is the '}' that closes the translations object
TARGETS = {
    'l10n/de.json': re.compile(r'\},\s*"pluralForm"'),
    'l10n/de_DE.json': re.compile(r'\},\s*"pluralForm"'),
    'l10n/de.js': re.compile(r'\},\s*"nplurals'),
    'l10n/de_DE.js': re.compile(r'\},\s*"nplurals'),
}


LOCALES = ('de', 'de_DE')


def render_value(v):
    if isinstance(v, list):
        return '[' + ','.join(json.dumps(x, ensure_ascii=False) for x in v) + ']'
    return json.dumps(v, ensure_ascii=False)


def for_locale(v, locale):
    return v[locale] if isinstance(v, dict) else v


def locale_of(relpath):
    return os.path.basename(relpath).rsplit('.', 1)[0]


def sibling_json(relpath):
    return relpath[:-3] + '.json' if relpath.endswith('.js') else relpath


def existing_keys(root, relpath):
    # de.js and de.json always share the same content; use the .json as the
    # authoritative key set for both.
    path = os.path.join(root, sibling_json(relpath))
    return set(json.load(open(path, encoding='utf-8'))['translations'].keys())


def apply_file(root, relpath, marker, trans, have, dry_run):
    path = os.path.join(root, relpath)
    text = open(path, encoding='utf-8').read()
    new = {k: v for k, v in trans.items() if k not in have}
    if not new:
        return 0, ''
    m = marker.search(text)
    if not m:
        sys.exit(f'close marker not found in {relpath} — file format changed?')
    close = m.start()  # index of the '}' that closes translations
    before = text[:close].rstrip()
    after = text[close:]
    locale = locale_of(relpath)
    lines = [f'    {json.dumps(k, ensure_ascii=False)} : '
             f'{render_value(for_locale(v, locale))}'
             for k, v in new.items()]
    insert = ',\n' + ',\n'.join(lines) + '\n'
    if not dry_run:
        with open(path, 'w', encoding='utf-8') as fh:
            fh.write(before + insert + after)
    return len(new), '\n'.join(lines)


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('map', help='JSON file: {"English": "Deutsch", …}')
    ap.add_argument('--root', default='.', help='attendance repo root')
    ap.add_argument('--dry-run', action='store_true',
                    help='print what would change, write nothing')
    args = ap.parse_args()
    root = os.path.abspath(args.root)

    trans = json.load(open(args.map, encoding='utf-8'))
    if not isinstance(trans, dict) or not trans:
        sys.exit('map must be a non-empty JSON object')
    def check_one(key, v, where):
        if isinstance(v, list):
            if len(v) != 2 or not all(isinstance(x, str) for x in v):
                sys.exit(f'plural value for {key!r}{where} must be [singular, plural]')
        elif not isinstance(v, str):
            sys.exit(f'value for {key!r}{where} must be a string or [singular, plural]')

    for k, v in trans.items():
        if isinstance(v, dict):
            if set(v) != set(LOCALES):
                sys.exit(f'per-locale value for {k!r} needs exactly the keys '
                         f'{list(LOCALES)}, got {sorted(v)}')
            for loc in LOCALES:
                check_one(k, v[loc], f' [{loc}]')
        else:
            check_one(k, v, '')

    # Snapshot existing keys for every file BEFORE writing anything — de.js and
    # de.json share content, so writing de.json first must not make de.js think
    # the keys already exist.
    snapshots = {rel: existing_keys(root, rel) for rel in TARGETS}

    for relpath, marker in TARGETS.items():
        n, preview = apply_file(root, relpath, marker, trans,
                                snapshots[relpath], args.dry_run)
        tag = 'would add' if args.dry_run else 'added'
        print(f'{relpath}: {tag} {n}')
        if args.dry_run and preview:
            print(preview)


if __name__ == '__main__':
    main()
