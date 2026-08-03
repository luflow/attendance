---
name: screenshots
description: Produce the app store and website screenshots for the attendance app — seed a dev instance with realistic choir demo data (English or German via --lang de), shoot the dashboard widget, appointment list, group statistics, live check-in, activity history and scheduling states at a consistent size, regenerate the thumbnails and wire them into appinfo/info.xml and the website repo. Use when the user wants new or updated screenshots, mentions "screenshots erzeugen", "neue screenshots", "screenshots aktualisieren", "deutsche screenshots", "app store screenshots", "Bilder für den App Store", "Website-Screenshots", or after UI changes that make the shipped screenshots look outdated.
---

# App store screenshots

Six screenshots ship with the app. They live in `appinfo/screens/` and are
referenced from `appinfo/info.xml` — **in this order**, which is what the
store renders (the first one is the headline visual, so the instantly readable
list view leads and the dashboard widget closes):

| # | File | What it shows |
|---|---|---|
| 1 | `appointments.jpg` | Appointment list with the full navigation sidebar |
| 2 | `admin.jpg` | Appointment card with one voice group expanded (names visible) |
| 3 | `checkin.jpg` | Check-in list mixing present / absent / pending |
| 4 | `scheduling.jpg` | Closed cards with the Scheduled / Not scheduled chips |
| 5 | `audit-log.jpg` | Activity history of the Summer Concert |
| 6 | `screen.jpg` | Dashboard widget with the live "Start check-in" button |

Each has a `-small.jpg` thumbnail next to it. The same four images are reused
in the marketing website repo (`attendance-website`, at
`~/Projekte/attendance-website`) as
`public/images/{screen,checkin,admin,appointments}.jpg`, where `screen.jpg`,
`checkin.jpg` and `admin.jpg` are wired into `src/pages/**` and
`appointments.jpg` is currently unused. The website additionally carries two
**website-only motifs** — `audit-log.jpg` (activity history) and
`scheduling.jpg` (Scheduled / Not scheduled cards), linked from the Activity
History and Scheduling cards on `features.astro` — plus a full **German set**
of all six under `public/images/de/`, referenced by `src/pages/de/index.astro`
and `src/pages/de/features.astro` (see "German screenshots" below).

**Target size: 1952x1344 px.** That is what the existing set uses (2x retina of
a 976x672 viewport). Keep it — the store scales them uniformly, and a mixed set
looks sloppy side by side.

## Step 1 — Seed the dev instance

```bash
python3 .claude/skills/screenshots/scripts/seed_demo_data.py --dry-run   # inspect
python3 .claude/skills/screenshots/scripts/seed_demo_data.py
```

Autodetects the Nextcloud and database containers, then writes 10 appointments
plus ~110 responses straight into `oc_att_appointments` / `oc_att_responses`.
Writing SQL rather than clicking through the UI is deliberate: it produces
"everyone has answered" states without logging in as eleven users.

It is **non-destructive** — it only deletes and re-inserts IDs 101-199, a
reserved range. Existing appointments survive. Re-running is safe and
idempotent.

Times are computed relative to the instance clock, so re-running months later
still yields sensible data. Rehearsals land on Tuesdays, concerts on Saturdays,
and one appointment is **running right now** (started 90 min ago) — that is what
unlocks the live "Start check-in" button on the dashboard widget.

The seed also writes a believable **activity history** for the Summer Concert
(ID 103) into `oc_att_audit_event` — created → responses → an edit → a manager
override → self check-ins → a manual check-in → auto-close. That is the data
behind the website's audit-log motif; the timeline localizes the verbs
client-side, so the same rows serve English and German shots.

**Auto-close trap (bites every run):** `autoCloseExpired()` closes any
appointment whose **start** time has passed — a future response deadline does
NOT protect it. The cron fires every few minutes, so the "running right now"
appointment (ID 104) will flip to "Closed" between seeding and shooting.
Re-open it immediately before each shot that needs it live:

```bash
CFG=$(docker exec master-stable34-1 php -r 'require "/var/www/html/config/config.php"; echo $CONFIG["dbuser"],"|",$CONFIG["dbpassword"],"|",$CONFIG["dbname"];')
DBU=${CFG%%|*}; REST=${CFG#*|}; DBP=${REST%%|*}; DBN=${REST##*|}
docker exec -i master-database-mysql-1 mysql -u"$DBU" -p"$DBP" "$DBN" \
  -e "UPDATE oc_att_appointments SET closed_at=NULL WHERE id=104;"
```

A closed first card also changes the list geometry (no response buttons, no
deadline line → shorter card), so re-measure the cut after re-opening.

### Reviewing the card states (`--design-states`)

```bash
python3 .claude/skills/screenshots/scripts/seed_demo_data.py --design-states
```

Adds six more appointments (IDs 151-156), one per state the appointment card is
designed for, so a UI change can be eyeballed against all of them at once
instead of hunting for a closed or cancelled example:

| ID | State |
|---|---|
| 151 | open, own answer "maybe" |
| 152 | open, own answer "no" |
| 153 | closed, own scheduling verdict **Scheduled** (plus markdown, headings and a quote in the description) |
| 154 | closed, own scheduling verdict **Not scheduled** |
| 155 | **cancelled** |
| 156 | open, unanswered, with a response deadline |

The scheduling states need `booking_enabled=yes`, which this flag turns on.
They are **not** part of the app store set — the plain run deletes them again,
because the shipped list screenshot should show an ordinary choir list. Re-pass
the flag whenever you want them back.

The script also fixes four things that otherwise ruin the screenshots. Do not
skip them with `--no-config` unless you know they are already right:

- **Display names.** The dev accounts have none, so the UI would show `user1`,
  `user4` instead of people.
- **`whitelisted_groups`** (app config) gets all five groups. Members of any
  group missing from that list are lumped into an "Others" row in the response
  summary, which reads like a data bug.
- **A `Conductor` group** for `admin`. Without it the screenshot-taker is the
  one person in "Others".
- **`lang=en` *and* `locale=en_GB`** for admin. `lang` alone leaves dates
  German ("So., 26. Juli 2026") inside an English UI.

## Step 2 — Log in (the user has to do this)

Claude must not type passwords into login forms. Ask the user to log in as
`admin` themselves in the browser you are driving, then continue. One login is
enough — Step 1 already covered the other users' data.

Note: the in-app browser pane refuses `*.local` hosts ("This site requires
per-action approval; Browser read tools are not available on it"). Use the
**chrome-devtools** MCP tools instead.

## Step 3 — Shoot at a constant output size

The trick: keep `viewport x deviceScaleFactor = 1952x1344` and trade one
against the other. A bigger viewport fits more content at a smaller apparent
font size; the resulting image stays the same pixel size. This is the
"zoom out until it fits" lever.

```
emulate  viewport: 1220x840x1.6     -> dashboard widget
emulate  viewport: 1495x1030x1.305  -> appointment list
emulate  viewport: 1743x1200x1.12   -> group statistics (one group expanded)
emulate  viewport: 1788x1231x1.092  -> check-in list
```

Those are starting points, not gospel — content length shifts with the data.
**Measure, do not guess.** Before each shot, check what actually fits:

```js
// height the card needs vs. what the viewport offers
const card = document.querySelector('.appointment-card')
const r = card.getBoundingClientRect()
;({ top: r.top, bottom: r.bottom, viewportH: window.innerHeight })
```

If `bottom > viewportH`, raise the viewport height and lower the scale factor
by the same ratio, then re-measure. For lists, aim for a cut **between** two
entries rather than through one:

```js
[...document.querySelectorAll('.user-item')]
  .map(e => ({ bottom: Math.round(e.getBoundingClientRect().bottom),
               name: e.innerText.split('\n')[0] }))
```

Pick a viewport height that lands just past one entry's `bottom`.

Then take the shot as PNG and **keep it** — Step 4 needs it:

```
take_screenshot  format: png  filePath: <repo>/.tmp-screenshots/<name>.png
```

### Three traps

- **Hover state.** The mouse parks wherever it last acted and can leave a
  button looking selected. Move it somewhere neutral (`hover` the page
  heading) before shooting, and verify against the API rather than trusting
  the pixels.
- **Stale translation bundle.** The browser caches the app's l10n bundle
  aggressively. After changing `l10n/de.js` or switching the admin language,
  a normal reload keeps serving the old registry — some strings render
  translated, newly added ones fall back to English, which looks like a
  missing translation. Always hard-reload (`navigate_page` with
  `ignoreCache: true`) after touching translations or switching languages.
- **Check-in button styling.** For a *pending* attendee both "Present" and
  "Absent" render coloured (they are offered as choices); for a decided one
  only the chosen side stays coloured. In isolation that reads as "marked
  absent". Make sure the visible slice contains at least one present, one
  absent and one pending row so the difference is legible — the seed data
  arranges exactly that among the first five alphabetical entries.

Not every crop is fixable, and that is fine: the dashboard widget has
`max-height: 450px` with internal scrolling (`src/views/Widget.vue`), so with
more than three upcoming appointments the next one always peeks out from under
the footer button. That is what a real user sees.

## Step 4 — Convert and generate thumbnails

Generate the thumbnail from the **PNG**, not from the JPG you just wrote, to
avoid compressing twice:

```bash
sips -s format jpeg -s formatOptions 88 .tmp-screenshots/NAME.png --out appinfo/screens/NAME.jpg
sips -Z 512 -s format jpeg -s formatOptions 85 .tmp-screenshots/NAME.png --out appinfo/screens/NAME-small.jpg
```

`-Z 512` scales proportionally to a 512 px long edge, giving 512x352. Verify:

```bash
for f in appinfo/screens/*.jpg; do
  printf '%-42s' "$f"; sips -g pixelWidth -g pixelHeight "$f" | grep pixel | tr '\n' ' '
  ls -lh "$f" | awk '{print "  "$5}'
done
```

Then delete `.tmp-screenshots/`. Expect ~190-320 KB for the full size and
28-40 KB for a thumbnail.

## Step 5 — Check info.xml

Per the [app store docs](https://nextcloudappstore.readthedocs.io/en/latest/developer.html#info-xml):
HTTPS URLs, max 2 MiB per screenshot, thumbnails "small so it renders fast".
No dimensions or format are prescribed. Entries render **in document order**.

The element order is a schema-enforced sequence — `screenshot` belongs after
`repository`, not next to `description`. Always validate:

```bash
curl -sfL https://apps.nextcloud.com/schema/apps/info.xsd -o /tmp/nc-info.xsd
xmllint --noout --schema /tmp/nc-info.xsd appinfo/info.xml
```

Adding a new screenshot means one more `<screenshot>` line plus both files in
`appinfo/screens/`.

**Two things gate visibility, and they differ:**

- The URLs point at `refs/heads/main`, so **replacing an existing image needs
  only a merge to main** — no release. The store fetches it live.
- **Adding an entry** changes `info.xml`, which is release metadata. It only
  appears after a new app store release (see `CLAUDE.md` → Release Management).

Until the branch is merged, a newly added filename returns 404 in the store.

Since `appinfo/**` changed, run the backend tests before handing back
(`CLAUDE.md`): `composer test:unit`. Commit with this repo's convention —
no Claude co-author.

## Step 6 — Mirror into the website repo (optional)

```bash
cp appinfo/screens/{screen,appointments,admin,checkin}.jpg \
   ~/Projekte/attendance-website/public/images/
```

The website loads them at full size in a lightbox, so use the same files.

## Shooting the audit-log & scheduling motifs

These two started as website-only shots and are now part of the store set too:

- **`audit-log.jpg`** — the appointment detail of the Summer Concert (ID 103)
  scrolled so the Activity history fills the frame. The seed writes the audit
  rows (see Step 1). Viewport `1739x1197x1.1224842`; scroll the `#app-content`
  container (NOT `window` — `window.scrollTo` is a no-op in the app layout)
  until `.audit-timeline` sits at roughly `top: 470` so the tail of the
  response summary stays visible above it. Remove the orange
  `.unanswered-banner` element first — it steals the eye.
- **`scheduling.jpg`** — the appointment list scrolled to the two closed
  design-state cards with the Scheduled / Not scheduled chips (`--design-states`
  required, IDs 153/154). Same viewport. Mind the fixed header: the scroll
  container starts ~50px down, so position the first card's top at ~80 in
  viewport coordinates or its title hides under the header bar.

Both are wired into `features.astro` (EN and `de/`) as "See an example" /
"Beispiel ansehen" lightbox buttons on the Activity History and Scheduling
cards.

## German screenshots for the website (`--lang de`)

The app store **cannot** take per-language screenshots — in the store's
`info.xsd` the `screenshot` type is a plain `secure-url` with a
`small-thumbnail` attribute and no `lang`, unlike `name`/`summary`/
`description` which are `l10n-string`/`l10n-text`. So the German set never
goes into `appinfo/screens/`; it lives in the website repo as
`public/images/de/{screen,appointments,admin,checkin,audit-log,scheduling}.jpg`,
referenced by `src/pages/de/index.astro` and `src/pages/de/features.astro`.

```bash
python3 .claude/skills/screenshots/scripts/seed_demo_data.py --lang de --design-states
```

`--lang de` handles everything that once needed manual SQL:

- German appointment names, descriptions and response comments (same data
  shape as English, translated via the tables at the top of the script)
- admin gets `lang=de` + `locale=de_DE` (dates like "Mo., 3. Aug. 2026")
- the conductor group becomes **`Chorleitung`** — a separate real group, not a
  renamed one, because the response summary renders group **IDs**, and it is
  written consistently into the whitelist AND every appointment's
  `visible_groups` (a group missing from `visible_groups` gets filtered into
  the "Others" bucket even when whitelisted)

Then shoot the same six motifs with the same viewports as the English set.
Expect the list geometry to differ by a few pixels — German button labels
("Vielleicht") are wider; measure the card cut again rather than reusing the
English numbers. Hard-reload once after the language switch (see the traps).

Every seed run is **exclusive per language**: running `--lang de` replaces the
English data, whitelist and admin locale, and plain `seed_demo_data.py` (or
`--lang en`) switches everything back. Both conductor groups may exist on the
instance simultaneously; only the seeded language's one is whitelisted. Finish
a German session with a plain English run so the instance is back in the state
the store screenshots expect.

Converting and wiring:

```bash
mkdir -p ~/Projekte/attendance-website/public/images/de
for n in screen appointments admin checkin audit-log scheduling; do
  sips -s format jpeg -s formatOptions 88 .tmp-screenshots/$n-de.png \
    --out ~/Projekte/attendance-website/public/images/de/$n.jpg
done
```

The `/de` pages already point at `/images/de/…`; a pure re-shoot needs no
page edits.
