#!/usr/bin/env python3
"""Find stale translation keys — entries in l10n/de.json no source string uses.

A key is genuinely stale only if its exact English literal appears NOWHERE in
the app source: a string used via t()/n()/$l->t()/$l->n() always has its literal
present (that is how Transifex extracts it in the first place), so a literal
absent from every source file cannot be reached by any code. Those are reported
as **orphaned** — safe to remove.

Keys that are NOT extracted but whose literal still appears in source land in
**review**: usually a whitespace/ellipsis drift (source uses ` …` with a
non-breaking space, the stale entry a plain one) or a plural bare form
(`%n day` while the live key is `_%n day_::_%n days_`). Do NOT remove these
blindly — a whitespace drift is fixed on the source side, and a genuine miss
would mean the extractor (find_untranslated) needs widening. Verify each one
by grepping the code before deciding.

Run from the attendance repo root (or pass --root PATH). Outputs JSON:
  {"orphaned": ["…"], "review": [{"key": "…", "reason": "…"}]}
"""
import argparse
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import find_untranslated as F  # noqa: E402  shared extraction + SKIP_DIRS

SRC_EXTS = ('.vue', '.js', '.ts', '.mjs', '.php')


def source_blob(root):
    """Concatenation of every scannable source file (Transifex's scope)."""
    parts = []
    for dirpath, dirs, files in os.walk(root):
        dirs[:] = [d for d in dirs if d not in F.SKIP_DIRS]
        for f in files:
            if f.endswith(SRC_EXTS):
                parts.append(open(os.path.join(dirpath, f), encoding='utf-8')
                             .read())
    return '\n'.join(parts)


def norm_ws(s):
    """Collapse non-breaking spaces to plain ones for drift comparison."""
    return s.replace(' ', ' ')


def plural_parts(used):
    """All singular/plural halves of the `_s_::_p_` keys that are in use."""
    halves = set()
    for k in used:
        if k.startswith('_') and '_::_' in k and k.endswith('_'):
            body = k[1:-1]
            s, _, p = body.partition('_::_')
            halves.add(s)
            halves.add(p)
    return halves


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--root', default='.', help='attendance repo root')
    args = ap.parse_args()
    root = os.path.abspath(args.root)

    de = os.path.join(root, 'l10n', 'de.json')
    if not os.path.isfile(de):
        sys.exit(f'not an attendance repo (no l10n/de.json under {root})')
    keys = set(json.load(open(de, encoding='utf-8'))['translations'].keys())

    used = F.extract(root)
    blob = source_blob(root)
    norm_blob = norm_ws(blob)
    used_norm = {norm_ws(u) for u in used}
    halves = plural_parts(used)

    orphaned, review = [], []
    for k in sorted(keys - used):
        if k in blob:
            review.append({'key': k, 'reason': 'literal-present-in-source'})
        elif norm_ws(k) in used_norm or norm_ws(k) in norm_blob:
            review.append({'key': k, 'reason': 'whitespace/ellipsis-drift'})
        elif k in halves:
            review.append({'key': k, 'reason': 'plural-bare-form'})
        else:
            orphaned.append(k)

    json.dump({'orphaned': orphaned, 'review': review}, sys.stdout,
              ensure_ascii=False, indent=1)
    print()


if __name__ == '__main__':
    main()
