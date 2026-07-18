#!/usr/bin/env python3
"""Remove stale translation keys from the four German l10n files.

Reads a JSON file with the keys to drop — either a bare list
    ["Check-in code", "Invoice / Association"]
or the object find_stale.py prints (its "orphaned" list is used):
    {"orphaned": [...], "review": [...]}

Removes each key from l10n/de.json, de.js, de_DE.json and de_DE.js, keeping the
translationtool formatting intact (re-commas the entries so the last one never
carries a trailing comma, even when the removed key was last). Idempotent — a
key that is absent is skipped. Use --dry-run to preview counts.

Only feed this the keys you have confirmed are stale (find_stale.py's orphaned
list, or review items you have grep-verified as unused). These files are
regenerated from Transifex on the next sync; this is a stopgap for a clean diff.
"""
import argparse
import json
import os
import re
import sys

FILES = ['l10n/de.json', 'l10n/de.js', 'l10n/de_DE.json', 'l10n/de_DE.js']
ENTRY = re.compile(r'^\s*"((?:[^"\\]|\\.)*)"\s*:')


def load_keys(path):
    data = json.load(open(path, encoding='utf-8'))
    if isinstance(data, dict):
        data = data.get('orphaned', [])
    if not isinstance(data, list) or not all(isinstance(x, str) for x in data):
        sys.exit('key file must be a JSON list of strings (or {"orphaned": [...]})')
    return set(data)


def strip_trailing_comma(line):
    s = line.rstrip()
    return s[:-1].rstrip() if s.endswith(',') else s


def apply_file(root, relpath, remove, dry_run):
    path = os.path.join(root, relpath)
    lines = open(path, encoding='utf-8').read().split('\n')
    idx = [i for i, ln in enumerate(lines) if ENTRY.match(ln)]
    if not idx:
        sys.exit(f'no translation entries found in {relpath}')
    first, last = idx[0], idx[-1]
    entries = lines[first:last + 1]

    kept, removed = [], 0
    for ln in entries:
        m = ENTRY.match(ln)
        if m and json.loads('"' + m.group(1) + '"') in remove:
            removed += 1
            continue
        kept.append(strip_trailing_comma(ln))
    if not kept:
        sys.exit(f'refusing to remove every entry in {relpath}')

    rebuilt = [ln + (',' if i < len(kept) - 1 else '')
               for i, ln in enumerate(kept)]
    if not dry_run:
        out = lines[:first] + rebuilt + lines[last + 1:]
        with open(path, 'w', encoding='utf-8') as fh:
            fh.write('\n'.join(out))
    return removed


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('keys', help='JSON list of keys, or find_stale.py output')
    ap.add_argument('--root', default='.', help='attendance repo root')
    ap.add_argument('--dry-run', action='store_true')
    args = ap.parse_args()
    root = os.path.abspath(args.root)
    remove = load_keys(args.keys)
    if not remove:
        sys.exit('no keys to remove')

    for relpath in FILES:
        n = apply_file(root, relpath, remove, args.dry_run)
        print(f'{relpath}: {"would remove" if args.dry_run else "removed"} {n}')


if __name__ == '__main__':
    main()
