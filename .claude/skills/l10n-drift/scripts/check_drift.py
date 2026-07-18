#!/usr/bin/env python3
"""Check translation-string drift between the Flutter app and this repo.

The Flutter app (attendance-flutter) uses easy_localization with the English
source strings as keys; Transifex only extracts strings registered in THIS
repo (t()/n() in src/, $l->t() in lib/). Every key the Flutter app uses must
therefore also appear here — mobile-only strings are registered in the
module-scope block in src/App.vue.

Extraction covers, on the Flutter side:
  - string literals directly before .tr(/.plural( — incl. multi-line literals
    and Dart implicit concatenation ("a " "b".tr() → key is the FULL string)
  - parenthesised expressions before .tr( — ternaries and ?? defaults; every
    literal inside the parens is treated as a candidate key
  - data-driven keys (labels/templates stored in lists/maps and translated
    later, e.g. r.label.tr()) via data-keys.json next to this script
  - remaining .tr( call sites on plain identifiers are reported as
    UNRESOLVED so a human can check whether data-keys.json needs updating

Exit code 0 when clean, 1 when drift (or unresolved sites) was found.
"""
import argparse
import json
import os
import re
import sys

LIT = r"(?:'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\")"
LIT_RE = re.compile(LIT)
CONCAT_TR_RE = re.compile(rf"((?:{LIT}\s+)*{LIT})\s*\.\s*(?:tr|plural)\s*\(")
TR_CALL_RE = re.compile(r"\.\s*(?:tr|plural)\s*\(")
# ncPlural(context, '%n singular', '%n plural', n) — the app's gettext-aware
# plural helper (lib/services/nextcloud_asset_loader.dart); both forms are keys.
NCPLURAL_RE = re.compile(rf"\bncPlural\s*\(\s*[^,()]*,\s*{LIT}\s*,\s*{LIT}")
WEB_T_RE = re.compile(r"""\b[tn]\s*\(\s*['"]attendance['"]\s*,\s*""" + LIT)
WEB_N2_RE = re.compile(
    r"""\bn\s*\(\s*['"]attendance['"]\s*,\s*(?:'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")\s*,\s*"""
    + LIT)
PHP_T_RE = re.compile(r"->[tn]\s*\(\s*" + LIT)
BLOCK_LINE_RE = re.compile(r"^t\(\s*'attendance',\s*" + LIT, re.M)


def unesc(s):
    s = re.sub(r'\\u([0-9a-fA-F]{4})', lambda m: chr(int(m.group(1), 16)), s)
    return (s.replace("\\'", "'").replace('\\"', '"')
            .replace('\\n', '\n').replace('\\t', '\t').replace('\\\\', '\\'))


def lit_value(match, base=0):
    """Value of a LIT match: exactly one of the two groups participated."""
    a, b = match.group(base + 1), match.group(base + 2)
    return unesc(a if a is not None else b)


def walk(root, exts, skip=('.claude', 'build', 'node_modules', '.git')):
    for dirpath, dirs, files in os.walk(root):
        dirs[:] = [d for d in dirs if d not in skip]
        for f in files:
            if f.endswith(exts):
                yield os.path.join(dirpath, f)


def extract_web(app_root):
    """All keys Transifex extracts from this repo (Vue/JS + PHP)."""
    keys = set()
    for p in walk(os.path.join(app_root, 'src'), ('.vue', '.js', '.ts')):
        src = open(p, encoding='utf-8').read()
        for m in WEB_T_RE.finditer(src):
            keys.add(lit_value(m))
        for m in WEB_N2_RE.finditer(src):
            keys.add(lit_value(m))
    for p in walk(os.path.join(app_root, 'lib'), ('.php',)):
        src = open(p, encoding='utf-8').read()
        for m in PHP_T_RE.finditer(src):
            keys.add(lit_value(m))
    return keys


def matching_paren_start(src, close_idx):
    """Index of the '(' matching src[close_idx] == ')' (skips string literals)."""
    depth = 0
    k = close_idx
    while k >= 0:
        ch = src[k]
        if ch in "'\"":
            q = ch
            k -= 1
            while k >= 0 and not (src[k] == q and (k == 0 or src[k - 1] != '\\')):
                k -= 1
        elif ch == ')':
            depth += 1
        elif ch == '(':
            depth -= 1
            if depth == 0:
                return k
        k -= 1
    return 0


def extract_flutter(flutter_root):
    """Keys used by the Flutter app + unresolved (identifier-based) call sites."""
    keys = set()
    unresolved = []
    for p in walk(os.path.join(flutter_root, 'lib'), ('.dart',)):
        src = open(p, encoding='utf-8').read()
        for m in CONCAT_TR_RE.finditer(src):
            parts = [lit_value(mm) for mm in LIT_RE.finditer(m.group(1))]
            keys.add(''.join(parts))
        for m in NCPLURAL_RE.finditer(src):
            keys.add(lit_value(m))
            keys.add(lit_value(m, base=2))
        for m in TR_CALL_RE.finditer(src):
            j = m.start() - 1
            while j >= 0 and src[j].isspace():
                j -= 1
            if j < 0 or src[j] in "'\"":
                continue  # literal-adjacent: handled above
            if src[j] == ')':
                start = matching_paren_start(src, j)
                for mm in LIT_RE.finditer(src[start:j + 1]):
                    keys.add(lit_value(mm))
            else:
                line_no = src.count('\n', 0, m.start()) + 1
                line = src.splitlines()[line_no - 1].strip()
                if line.startswith('//'):
                    continue  # doc/line comment, not code
                rel = os.path.relpath(p, flutter_root)
                site = f'{rel}:{line_no}: {line[:100]}'
                if site not in unresolved:
                    unresolved.append(site)
    keys.discard('')
    return keys, unresolved


def load_data_keys(script_dir):
    path = os.path.join(script_dir, '..', 'data-keys.json')
    data = json.load(open(path, encoding='utf-8'))
    keys = set()
    for group in data['keys'].values():
        keys.update(group)
    return keys


def app_vue_block(app_root):
    """Keys in the module-scope registration block (column-0 t() lines)."""
    src = open(os.path.join(app_root, 'src/App.vue'), encoding='utf-8').read()
    return {lit_value(m) for m in BLOCK_LINE_RE.finditer(src)}


def web_without_block(app_root):
    """Web keys with App.vue's column-0 registration lines removed."""
    keys = set()
    for p in walk(os.path.join(app_root, 'src'), ('.vue', '.js', '.ts')):
        src = open(p, encoding='utf-8').read()
        if os.path.basename(p) == 'App.vue' and 'src/App.vue' in p.replace(os.sep, '/'):
            src = BLOCK_LINE_RE.sub('', src)
        for m in WEB_T_RE.finditer(src):
            keys.add(lit_value(m))
        for m in WEB_N2_RE.finditer(src):
            keys.add(lit_value(m))
    for p in walk(os.path.join(app_root, 'lib'), ('.php',)):
        src = open(p, encoding='utf-8').read()
        for m in PHP_T_RE.finditer(src):
            keys.add(lit_value(m))
    return keys


def find_flutter_repo(app_root, override):
    if override:
        return os.path.abspath(override)
    candidates = [
        os.path.join(app_root, '..', 'attendance-flutter'),
        os.path.join(app_root, '..', '..', 'attendance-flutter'),
    ]
    for c in candidates:
        if os.path.isdir(os.path.join(c, 'lib')):
            return os.path.abspath(c)
    sys.exit('attendance-flutter repo not found — pass --flutter PATH')


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--flutter', help='path to the attendance-flutter repo')
    ap.add_argument('--json', action='store_true', help='machine-readable output')
    args = ap.parse_args()

    script_dir = os.path.dirname(os.path.abspath(__file__))
    app_root = os.path.abspath(os.path.join(script_dir, '..', '..', '..', '..'))
    flutter_root = find_flutter_repo(app_root, args.flutter)

    web = extract_web(app_root)
    flutter, unresolved = extract_flutter(flutter_root)
    flutter |= load_data_keys(script_dir)

    missing = sorted(flutter - web)
    stale = sorted(app_vue_block(app_root) - flutter - web_without_block(app_root))

    if args.json:
        print(json.dumps({'missing': missing, 'stale': stale,
                          'unresolved': unresolved}, indent=1, ensure_ascii=False))
    else:
        print(f'Flutter repo: {flutter_root}')
        print(f'MISSING in web app ({len(missing)}):')
        for s in missing:
            print('  ', json.dumps(s, ensure_ascii=False))
        print(f'STALE in App.vue block ({len(stale)}):')
        for s in stale:
            print('  ', json.dumps(s, ensure_ascii=False))
        print(f'UNRESOLVED .tr() call sites ({len(unresolved)}) — verify these are '
              'covered by data-keys.json or dynamic (untranslatable) values:')
        for s in unresolved:
            print('  ', s)

    sys.exit(1 if missing or stale else 0)


if __name__ == '__main__':
    main()
