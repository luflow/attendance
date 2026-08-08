#!/usr/bin/env python3
"""Guard the hand-maintained German translations.

German is no longer pulled from Transifex (see .tx/config), so nothing upstream
catches the defects that used to arrive with the nightly sync: stray whitespace,
lost placeholders, straight quotes, informal address in the formal de_DE locale.
This is that check.

    python3 scripts/check-german-l10n.py

Exits non-zero and prints one line per finding.
"""
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
LOCALES = ('de', 'de_DE')

# "Du" belongs in de, "Sie" in de_DE. Matching whole words only, and skipping
# "Sie" at the start of a sentence where it may just be the pronoun "they".
INFORMAL = re.compile(r'(?<![\w-])(?:du|dich|dir|dein|deine|deiner|deinem|deinen|deines)(?![\w-])', re.I)

# Second-person-singular imperatives that are not also a noun or infinitive here,
# so a match is always the informal address rather than a false positive.
INFORMAL_IMPERATIVE = re.compile(
    r'(?<![\w-])(?:Wähle|Scanne|Erstelle|Nutze|Öffne|Prüfe|Lege|Hole|Teile|Sieh|Klicke'
    r'|Trage|Bespreche|Halte|Ändere|Passe|Füge|Setze|Gehe|Schaue|Schicke|Kopiere'
    r'|Speichere|Wechsle|Beginne|Denke|Vergiss|Achte|Stelle)(?![\w-])')

PLACEHOLDER = re.compile(r'%\d+\$s|%[sdn]|\{\w+\}')


def values(v):
    return v if isinstance(v, list) else [v]


def check_locale(loc, findings):
    raw = (ROOT / 'l10n' / f'{loc}.json').read_text(encoding='utf-8')
    # Keep the pairs as a list so duplicate keys are reported, not collapsed.
    trans_pairs = dict(json.loads(raw, object_pairs_hook=lambda p: p))['translations']

    seen = {}
    for key, val in trans_pairs:
        if key in seen:
            findings.append(f'{loc}: duplicate key {key!r}')
        seen[key] = val

        # A plural key carries both English forms; compare each against its own form.
        if isinstance(val, list) and '_::_' in key:
            sources = [f.strip('_') for f in key.split('_::_')]
        else:
            sources = [key] * len(values(val))

        for source, s in zip(sources, values(val)):
            if s != s.strip():
                findings.append(f'{loc}: leading/trailing whitespace in {key[:60]!r} -> {s!r}')
            if '  ' in s:
                findings.append(f'{loc}: double space in {key[:60]!r} -> {s!r}')
            if '...' in s:
                findings.append(f'{loc}: literal "..." instead of "…" in {key[:60]!r}')
            if '…' in s and re.search(r'(?<![\s ])…', s):
                findings.append(f'{loc}: missing non-breaking space before "…" in {key[:60]!r}')
            # German quotes even where the English source uses "…" — the source's
            # typography is not German typography.
            if '"' in s:
                findings.append(f'{loc}: straight quotes (use „…“) in {key[:60]!r} -> {s!r}')

            want = sorted(PLACEHOLDER.findall(source))
            got = sorted(PLACEHOLDER.findall(s))
            if want != got:
                findings.append(f'{loc}: placeholder mismatch in {key[:60]!r}: source {want} vs {got}')

        if isinstance(val, list) and len(val) != 2:
            findings.append(f'{loc}: plural {key[:60]!r} has {len(val)} forms, expected 2')

    # Address form: de is informal, de_DE is formal.
    for key, val in seen.items():
        for s in values(val):
            if loc != 'de_DE':
                continue
            informal = INFORMAL.search(s) or INFORMAL_IMPERATIVE.search(s)
            if informal:
                findings.append(f'de_DE: informal "{informal.group()}" in {key[:60]!r} -> {s[:90]!r}')

    return seen


def main():
    findings = []
    per_locale = {loc: check_locale(loc, findings) for loc in LOCALES}

    # The two locales must cover the same keys, and .js must match .json.
    de, de_de = per_locale['de'], per_locale['de_DE']
    for key in set(de) ^ set(de_de):
        findings.append(f'key present in only one German locale: {key[:70]!r}')

    for loc in LOCALES:
        js = (ROOT / 'l10n' / f'{loc}.js').read_text(encoding='utf-8')
        start = js.index('{', js.index('"attendance"'))
        end = js.rindex('}')
        if json.loads(js[start:end + 1]) != per_locale[loc]:
            findings.append(f'{loc}: l10n/{loc}.js and l10n/{loc}.json disagree')

    for f in findings:
        print(f)
    print(f'\n{len(findings)} finding(s) across {", ".join(LOCALES)}.')
    return 1 if findings else 0


if __name__ == '__main__':
    sys.exit(main())
