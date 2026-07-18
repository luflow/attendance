#!/usr/bin/env python3
"""Find source strings that have no German translation yet.

Transifex is the source of truth for translations, but strings added with a
fresh t()/n() call stay untranslated until the next sync. This scans every
English source string the code uses and reports the ones missing from
l10n/de.json, so they can be filled in immediately for local German coverage.

Extraction (matching what Transifex extracts into the .pot):
  - t('attendance', 'X')            -> key "X"                 (src/ .vue/.js/.ts)
  - n('attendance', 'S', 'P', …)    -> key "_S_::_P_"          (Nextcloud plural)
  - $l->t('X') / ->t('X')           -> key "X"                 (lib/ .php)
  - $l->n('S', 'P', …) / ->n(…)     -> key "_S_::_P_"
The column-0 mobile block in src/App.vue is covered by the t() rule (those
strings belong to the Flutter app and need German too).

Run from the attendance repo root (or pass --root PATH). Outputs JSON:
  {"count": N, "missing": [{"key", "kind": "string"|"plural",
                            "singular"?, "plural"?}, …]}
Exit 0 always; the caller decides what to do with the list.
"""
import argparse
import json
import os
import re
import sys

LIT = r"(?:'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\")"
T_RE = re.compile(r"\bt\s*\(\s*['\"]attendance['\"]\s*,\s*" + LIT)
N_RE = re.compile(
    r"\bn\s*\(\s*['\"]attendance['\"]\s*,\s*" + LIT + r"\s*,\s*" + LIT)
PHP_T_RE = re.compile(r"->t\s*\(\s*" + LIT)
PHP_N_RE = re.compile(r"->n\s*\(\s*" + LIT + r"\s*,\s*" + LIT)

SKIP_DIRS = {'.git', 'node_modules', 'build', 'js', 'css', '.claude', 'l10n',
             'translationfiles'}


def unesc(s):
    s = re.sub(r'\\u([0-9a-fA-F]{4})', lambda m: chr(int(m.group(1), 16)), s)
    return (s.replace("\\'", "'").replace('\\"', '"')
            .replace('\\n', '\n').replace('\\t', '\t').replace('\\\\', '\\'))


def val(m, base=0):
    """Value of the LIT group pair at offset `base` (one side participated)."""
    a, b = m.group(base + 1), m.group(base + 2)
    return unesc(a if a is not None else b)


def plural_key(sing, plur):
    return f'_{sing}_::_{plur}_'


def walk(root, exts):
    if not os.path.isdir(root):
        return
    for dirpath, dirs, files in os.walk(root):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        for f in files:
            if f.endswith(exts):
                yield os.path.join(dirpath, f)


def extract(root):
    keys = set()
    for p in walk(os.path.join(root, 'src'), ('.vue', '.js', '.ts', '.mjs')):
        s = open(p, encoding='utf-8').read()
        for m in T_RE.finditer(s):
            keys.add(val(m))
        for m in N_RE.finditer(s):
            keys.add(plural_key(val(m), val(m, 2)))
    for p in walk(os.path.join(root, 'lib'), ('.php',)):
        s = open(p, encoding='utf-8').read()
        for m in PHP_N_RE.finditer(s):
            keys.add(plural_key(val(m), val(m, 2)))
        for m in PHP_T_RE.finditer(s):
            keys.add(val(m))
    keys.discard('')
    return keys


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--root', default='.', help='attendance repo root')
    args = ap.parse_args()
    root = os.path.abspath(args.root)

    de = os.path.join(root, 'l10n', 'de.json')
    if not os.path.isfile(de):
        sys.exit(f'not an attendance repo (no l10n/de.json under {root})')
    existing = set(json.load(open(de, encoding='utf-8'))['translations'].keys())

    missing = sorted(extract(root) - existing)
    out = []
    for k in missing:
        m = re.match(r'^_(.*)_::_(.*)_$', k, re.S)
        if m:
            out.append({'key': k, 'kind': 'plural',
                        'singular': m.group(1), 'plural': m.group(2)})
        else:
            out.append({'key': k, 'kind': 'string'})

    json.dump({'count': len(out), 'missing': out}, sys.stdout,
              ensure_ascii=False, indent=1)
    print()


if __name__ == '__main__':
    main()
