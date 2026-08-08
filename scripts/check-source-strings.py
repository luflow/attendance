#!/usr/bin/env python3
"""Guard the English source strings that go into t() / n() calls.

These are the rules from CLAUDE.md that nothing else enforces: a bad source
string reaches Transifex, gets translated into 80 languages, and only then is
the mistake expensive. Complements scripts/check-german-l10n.py, which looks at
the German output rather than the calls that produce it.

    python3 scripts/check-source-strings.py

Exits non-zero and prints one file:line per finding.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

LIT = r"(?:'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\")"
JS_T = re.compile(r"\bt\s*\(\s*['\"]attendance['\"]\s*,\s*" + LIT)
JS_N = re.compile(r"\bn\s*\(\s*['\"]attendance['\"]\s*,\s*" + LIT + r"\s*,\s*" + LIT)
PHP_T = re.compile(r"->t\s*\(\s*" + LIT)
PHP_N = re.compile(r"->n\s*\(\s*" + LIT + r"\s*,\s*" + LIT)

SKIP_DIRS = {'.git', 'node_modules', 'build', 'js', 'css', '.claude', 'l10n',
             'translationfiles', 'vendor', 'vendor-bin', 'tests'}

# A count placeholder carrying a noun phrase needs plural forms. "(3)" as a bare
# tally and "{count} of {limit}" do not — nothing in them changes with the count.
COUNT_PLACEHOLDER = re.compile(r'(?:%n|\{count\})\s+(?!of\b)[^\W\d_]')
# Positional placeholders; %1$s and friends are the numbered form we want.
POSITIONAL = re.compile(r'%(?![\d]+\$)[sd]')
# German that slipped into a source string.
GERMAN = re.compile(
    r'[äöüßÄÖÜ]|(?<![\w-])(?:der|die|das|und|oder|nicht|kein|keine|eine|einen'
    r'|wird|werden|wurde|Termin|Termine|Benutzer|Antwort|Antworten|Gruppe'
    r'|Gruppen|Einstellungen|Anwesenheit|Teilnehmer)(?![\w-])')
# Sentence-final punctuation, or a colon introducing a following value.
ENDS_CLEANLY = re.compile(r'[.!?:……)\]}»"\'%]$')


def walk(rel, exts):
    base = os.path.join(ROOT, rel)
    if not os.path.isdir(base):
        return
    for dirpath, dirs, files in os.walk(base):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        for f in sorted(files):
            if f.endswith(exts):
                yield os.path.join(dirpath, f)


def unesc(s):
    s = re.sub(r'\\u([0-9a-fA-F]{4})', lambda m: chr(int(m.group(1), 16)), s)
    return (s.replace("\\'", "'").replace('\\"', '"')
            .replace('\\n', '\n').replace('\\t', '\t').replace('\\\\', '\\'))


def val(m, base=0):
    a, b = m.group(base + 1), m.group(base + 2)
    return unesc(a if a is not None else b)


def calls(text, php):
    """Yield (kind, strings, start_offset, end_offset) for every t()/n() call."""
    for m in (PHP_N if php else JS_N).finditer(text):
        yield 'n', [val(m), val(m, 2)], m.start(), m.end()
    for m in (PHP_T if php else JS_T).finditer(text):
        yield 't', [val(m)], m.start(), m.end()


def is_mobile_registration(path, text, start):
    """Column-0 t()/n() in src/App.vue exist only so Transifex extracts the
    Flutter app's strings. Their keys are that app's contract, so switching one
    to n() here would strand older mobile builds until a paired Flutter PR."""
    return (path.endswith(os.path.join('src', 'App.vue'))
            and start - text.rfind('\n', 0, start) - 1 == 0)


def check_string(s, kind, findings, where, plural_check=True):
    if '...' in s:
        findings.append(f'{where}: literal "..." — use the ellipsis character … '
                        f'in {s[:60]!r}')
    if '…' in s and re.search(r'(?<![\s ])…', s):
        findings.append(f'{where}: no non-breaking space before … in {s[:60]!r}')
    if re.search(r'\bsuccessfully\b', s, re.I):
        findings.append(f'{where}: "successfully" is redundant in {s[:60]!r}')
    if GERMAN.search(s):
        findings.append(f'{where}: German in a source string: {s[:60]!r}')
    if plural_check and kind == 't' and COUNT_PLACEHOLDER.search(s):
        findings.append(f'{where}: t() with a count placeholder — use n() with '
                        f'singular and plural forms: {s[:60]!r}')


def check_php_placeholders(s, findings, where):
    positional = POSITIONAL.findall(s)
    if len(positional) >= 2:
        findings.append(f'{where}: {len(positional)} positional placeholders — '
                        f'use %1$s, %2$s so translators can reorder: {s[:60]!r}')


def check_fragment(text, s, end, findings, where):
    """A string that stops mid-sentence and hands off to the next element."""
    if ENDS_CLEANLY.search(s.strip()) or len(s.strip()) < 4:
        return
    # Walk past the rest of the call and its mustache, then look at what follows.
    tail = text[end:end + 120]
    m = re.match(r"[^)]*\)\s*\}\}(.*)", tail, re.S)
    if not m:
        return
    following = m.group(1).lstrip()
    # Only a value handed over counts: a bare mustache, or one wrapped in an
    # inline text tag. An icon component or a named slot is not a continuation.
    if (following.startswith('{{')
            or re.match(r'<(strong|b|em|i|span|code|mark)\b[^>]*>\s*\{\{', following)):
        findings.append(f'{where}: sentence continues in the next element — put '
                        f'the placeholder inside the string: {s[:60]!r}')


def main():
    findings = []
    files = ([(p, False) for p in walk('src', ('.vue', '.js', '.ts', '.mjs'))]
             + [(p, True) for p in walk('lib', ('.php',))])

    for path, php in files:
        text = open(path, encoding='utf-8').read()
        rel = os.path.relpath(path, ROOT)
        for kind, strings, start, end in calls(text, php):
            line = text.count('\n', 0, start) + 1
            where = f'{rel}:{line}'
            plural_check = not is_mobile_registration(path, text, start)
            for s in strings:
                check_string(s, kind, findings, where, plural_check)
                if php:
                    check_php_placeholders(s, findings, where)
            if not php and kind == 't':
                check_fragment(text, strings[0], end, findings, where)

    for f in findings:
        print(f)
    print(f'\n{len(findings)} finding(s) across {len(files)} source file(s).')
    return 1 if findings else 0


if __name__ == '__main__':
    sys.exit(main())
