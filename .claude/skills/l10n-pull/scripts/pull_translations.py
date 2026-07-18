#!/usr/bin/env python3
"""Pull the latest Transifex translations from this repo into attendance-flutter.

Translations land nightly on this repo's main branch (l10n/*.json). The
Flutter app bundles verbatim copies under assets/l10n/. This script:

  1. fetches origin/main (unless --no-fetch)
  2. copies every l10n/*.json from origin/main into the Flutter repo's
     assets/l10n/ (reads via `git show`, so the local working tree and
     current branch of this repo stay untouched)
  3. reports which locales are new/changed/removed and whether
     lib/services/nextcloud_asset_loader.dart's supportedLocales list is
     out of sync (printed as ready-to-paste Locale(...) lines)

Use --dry-run to see what would change without writing anything.
"""
import argparse
import os
import re
import subprocess
import sys


def run(args, cwd, **kw):
    return subprocess.run(args, cwd=cwd, check=True, capture_output=True, **kw)


def find_flutter_repo(app_root, override):
    if override:
        return os.path.abspath(override)
    for c in (os.path.join(app_root, '..', 'attendance-flutter'),
              os.path.join(app_root, '..', '..', 'attendance-flutter')):
        if os.path.isdir(os.path.join(c, 'assets', 'l10n')):
            return os.path.abspath(c)
    sys.exit('attendance-flutter repo not found — pass --flutter PATH')


def loader_locales(loader_src):
    """Locales declared in the supportedLocales const, as file-stem strings."""
    stems = set()
    for m in re.finditer(r"Locale\('([A-Za-z0-9]+)'(?:,\s*'([A-Za-z0-9]+)')?\)",
                         loader_src):
        stems.add(m.group(1) + ('_' + m.group(2) if m.group(2) else ''))
    # Nextcloud's sr@latin.json is declared via Locale.fromSubtags(sr, Latn).
    for m in re.finditer(
            r"Locale\.fromSubtags\(languageCode:\s*'([A-Za-z]+)',\s*"
            r"scriptCode:\s*'Latn'\)", loader_src):
        stems.add(m.group(1) + '@latin')
    return stems


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--flutter', help='path to the attendance-flutter repo')
    ap.add_argument('--no-fetch', action='store_true',
                    help='skip `git fetch origin main`')
    ap.add_argument('--dry-run', action='store_true',
                    help='report only, write nothing')
    args = ap.parse_args()

    script_dir = os.path.dirname(os.path.abspath(__file__))
    app_root = os.path.abspath(os.path.join(script_dir, '..', '..', '..', '..'))
    flutter_root = find_flutter_repo(app_root, args.flutter)
    dest_dir = os.path.join(flutter_root, 'assets', 'l10n')

    if not args.no_fetch:
        run(['git', 'fetch', 'origin', 'main'], cwd=app_root)

    names = run(['git', 'ls-tree', '--name-only', 'origin/main', 'l10n/'],
                cwd=app_root, text=True).stdout.split()
    json_paths = sorted(p for p in names if p.endswith('.json'))
    if not json_paths:
        sys.exit('no l10n/*.json found in origin/main — wrong repo?')

    added, changed, unchanged = [], [], []
    upstream_stems = set()
    for path in json_paths:
        stem = os.path.splitext(os.path.basename(path))[0]
        upstream_stems.add(stem)
        content = run(['git', 'show', f'origin/main:{path}'], cwd=app_root).stdout
        dest = os.path.join(dest_dir, os.path.basename(path))
        if not os.path.exists(dest):
            added.append(stem)
        elif open(dest, 'rb').read() != content:
            changed.append(stem)
        else:
            unchanged.append(stem)
            continue
        if not args.dry_run:
            with open(dest, 'wb') as f:
                f.write(content)

    local_stems = {os.path.splitext(f)[0] for f in os.listdir(dest_dir)
                   if f.endswith('.json')}
    orphaned = sorted(local_stems - upstream_stems)

    loader_path = os.path.join(flutter_root, 'lib', 'services',
                               'nextcloud_asset_loader.dart')
    declared = loader_locales(open(loader_path, encoding='utf-8').read())
    missing_in_loader = sorted(upstream_stems - declared)
    declared_without_file = sorted(declared - upstream_stems - {'en'})

    mode = 'DRY RUN — nothing written' if args.dry_run else f'written to {dest_dir}'
    print(f'{mode}')
    print(f'changed: {len(changed)}  new: {len(added)}  unchanged: {len(unchanged)}')
    if added:
        print('new locales:', ', '.join(added))
    if orphaned:
        print('orphaned in assets/l10n (not in origin/main — check before '
              'deleting):', ', '.join(orphaned))
    if missing_in_loader:
        print('\nADD to supportedLocales in lib/services/nextcloud_asset_loader.dart:')
        for stem in missing_in_loader:
            parts = stem.split('_', 1)
            arg = f"'{parts[0]}', '{parts[1]}'" if len(parts) == 2 else f"'{parts[0]}'"
            print(f'  Locale({arg}),')
    if declared_without_file:
        print('declared in supportedLocales but no upstream file (check/remove):',
              ', '.join(declared_without_file))
    if not missing_in_loader and not declared_without_file:
        print('supportedLocales is in sync.')


if __name__ == '__main__':
    main()
