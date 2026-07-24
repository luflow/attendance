---
name: transifex
description: Handle Transifex translator feedback for the attendance app — fetch open questions/issues/suggestions from translators, triage them, fix source strings where translators are right, and post replies via the API. Use this whenever the user mentions Transifex, translator comments/questions/issues, string feedback, or wants to answer or resolve translation discussions — including phrasings like "check what translators asked", "answer the open translation questions", or "are there new comments on Transifex".
---

# Transifex translator feedback

Translations for this app live in the shared Nextcloud Transifex project
(`o:nextcloud:p:nextcloud`, resource `r:attendance` — see `.tx/config`).
Translators raise questions and issues on individual source strings there.
This skill covers the loop: fetch what's open, triage, fix source strings
where the translators have a point, persist answers as `TRANSLATORS`
context hints in the code, get the user's approval, then post replies.

## Prerequisites

- `TRANSIFEX_TOKEN` env var with a Transifex API token
  (transifex.com → User settings → API token). If it's not set, ask the
  user to add it to the environment settings (new sessions only) or paste
  it (remind them to rotate it afterwards).
- The helper script `scripts/tx_feedback.py` (relative to this skill)
  wraps the API and its quirks. Prefer it over raw curl.

## Step 1 — Fetch and reconstruct threads

```bash
python3 .claude/skills/transifex/scripts/tx_feedback.py list --open --new
```

This prints every thread that needs attention as JSON: the source string
(text, key, context), whether it has an open issue, and the full comment
transcript. `--me` defaults to `magdeflow` (the maintainer account); a
thread "needs attention" if it has an open issue or the last word wasn't
ours.

`--new` additionally drops threads without activity since the
`answered_up_to` watermark in `state.json` (next to this SKILL.md) —
that is what keeps already-handled threads from being answered twice,
especially open issues we cannot resolve ourselves (they stay "open"
forever, see Step 5). Drop `--new` only when the user explicitly wants
to revisit older threads; drop `--open` to see everything including
settled threads.

Reading the output, keep in mind:

- Entries with `"type": "issue"` carry an open/resolved **status**; plain
  comments have none — there is nothing to "resolve" on a comment thread.
- Threads are per source string; a question about one string sometimes
  refers to sibling strings (e.g. a set of related labels). Check the
  neighbours before answering.

## Step 2 — Triage into three buckets

For every thread locate the string in the code first
(`grep -rn "<source text>" src/ lib/ ../attendance-flutter/lib/`) — the
answer is almost always in the usage context, and translators cannot see
that context. Then sort:

1. **Just answer** — context questions ("noun or verb?", "what does {x}
   mean?"). Draft a reply that explains where the string appears and how
   it behaves; that is what the translator actually needs. **Every
   answered context question must also become a `TRANSLATORS` context
   hint in the code** (see "Context hints" below) — the reply helps one
   translator once, the hint helps every language forever.
2. **Fix the source string** — the translator is right (wrong spacing,
   sentence fragments, split plurals, capitalization, concatenation).
   Fix per the translation guidelines in `CLAUDE.md` (only first word
   capitalized, NBSP before `…`, no fragments, `n()` for plurals, …).
3. **Needs the user's call** — product wording decisions, renames,
   anything ambiguous. Ask instead of guessing.

## Context hints for translators (always, when answering)

Whenever a thread gets answered, persist the answer as a context hint
next to the string in the code, per
https://docs.nextcloud.com/server/latest/developer_manual/basics/translations.html#provide-context-hints-for-translators
— the extractor copies these comments into the .pot, so future
translators of every language see the hint directly in Transifex.

Syntax by location:

```php
// TRANSLATORS Shown as button label in the check-in popup
$l->t('Add new file');
```

```javascript
// TRANSLATORS Name that is appended to copied files
const label = t('attendance', 'copy')
```

```html
<!-- TRANSLATORS Marks a question as mandatory -->
<span>{{ t('attendance', 'Required') }}</span>
```

Rules:

- The comment binds only to the **first** translation call on the
  following line — one call per line, each with its own comment.
- In Vue `<template>` blocks use the HTML comment form, in `<script>`
  the `//` form.
- Word the hint as what a translator needs (where the string appears,
  noun vs. verb, what placeholders contain) — essentially a condensed
  version of the reply you are about to post.
- Mobile-only strings: the hint goes on the registration in the App.vue
  mobile block (that is what the extractor reads), not (only) in the
  Flutter code.
- Hints appear in Transifex only after the next .pot sync — mention
  that in the reply.

## Step 3 — Apply source-string fixes (both repos!)

Source strings are shared with the Flutter app (`../attendance-flutter`),
so every key change must land on **both** sides or lookups break:

- Strings used only by the mobile app are registered for extraction in
  the mobile block at the bottom of `src/App.vue` ("Strings that only
  appear in the Flutter mobile app"). Keep those registrations exactly
  identical to the `.tr()` keys in the Flutter code.
- If a string stops being used in the web UI but the app still uses it,
  move its registration into that App.vue mobile block instead of
  deleting it — otherwise it falls out of the .pot and the app loses its
  translations.
- Changing a source string changes the translation key: existing
  translations go stale until the next Transifex sync (Transifex usually
  re-matches similar strings). Say so in the reply ("will appear after
  the next sync").
- Plurals: web uses `n('attendance', singular, plural, count)`; the
  Flutter app resolves Nextcloud plural arrays through `ncPlural()`
  (`lib/services/nextcloud_asset_loader.dart`) — never a manual
  `count == 1 ? a.tr() : b.tr()` split, which breaks multi-plural
  languages like Polish.

Run the usual checks (`npm run lint`, `npm run build`, and
`flutter analyze` / `flutter test` for app-side changes) and commit on
the current working branch.

## Step 4 — Get approval, then post

Show the user every draft reply verbatim before posting anything —
replies go out publicly under their account. Match the thread's
language (rakekniven's German threads get German answers, tlend's
English ones English). Keep replies concrete: what the string does,
what was changed, what the translator should do next.

Post with:

```bash
python3 .claude/skills/transifex/scripts/tx_feedback.py reply <string_id> "<message>"
```

The script copies the mandatory `language` relationship from the
existing thread — a raw POST without it fails with 400.

Beware: the API masks missing language-team membership as a `404 Object
not found` on the resource string. The translator token can only post
comments attached to languages whose team it belongs to (de/de_DE); the
script therefore tries every language seen in the thread until one is
accepted. A persistent 404 despite the string existing means none of the
thread's languages are postable — fall back to `--language l:de_DE`.

## Step 5 — Resolving issues (usually not possible)

```bash
python3 .claude/skills/transifex/scripts/tx_feedback.py resolve <comment_id>
```

Changing issue status needs project-maintainer rights on the shared
nextcloud project; a translator-level token gets `403`. Don't fight it:
the regular reporters (e.g. rakekniven) resolve their issues themselves
once answered. Mention the limitation to the user instead of retrying.

## Step 6 — Advance the watermark

Once every reply for this session is posted (and only then), record how
far we got:

```bash
python3 .claude/skills/transifex/scripts/tx_feedback.py mark-answered
```

This writes the newest comment date seen on the resource (including our
own just-posted replies — the API is the source of truth, not the local
clock) into `answered_up_to` in `state.json`. The next `list --new` run
then only surfaces threads with newer activity, so nothing gets answered
twice. Commit `state.json` together with the session's code changes so
the watermark survives across worktrees and machines.

Skip this step if replies are still pending (e.g. the user postponed a
draft) — an advanced watermark would hide those threads from the next
run. If that happens anyway, `mark-answered --date <ISO>` can move the
watermark back explicitly.
