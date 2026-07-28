---
name: screenshots
description: Produce the app store and website screenshots for the attendance app — seed a dev instance with realistic choir demo data, shoot the dashboard widget, appointment list, group statistics and live check-in at a consistent size, regenerate the thumbnails and wire them into appinfo/info.xml. Use when the user wants new or updated screenshots, mentions "screenshots erzeugen", "neue screenshots", "screenshots aktualisieren", "app store screenshots", "Bilder für den App Store", or after UI changes that make the shipped screenshots look outdated.
---

# App store screenshots

Four screenshots ship with the app. They live in `appinfo/screens/` and are
referenced from `appinfo/info.xml`:

| File | Role in the store listing | What it shows |
|---|---|---|
| `screen.jpg` | RSVP / responses | Dashboard widget with the live "Start check-in" button |
| `appointments.jpg` | overview | Appointment list with the full navigation sidebar |
| `admin.jpg` | group statistics | Appointment card with one voice group expanded (names visible) |
| `checkin.jpg` | real-time check-in | Check-in list mixing present / absent / pending |

Each has a `-small.jpg` thumbnail next to it. The same four images are reused
in the marketing website repo (`attendance-website`) as
`public/images/{screen,checkin,admin,appointments}.jpg`, where `screen.jpg`,
`checkin.jpg` and `admin.jpg` are wired into `src/pages/**` and
`appointments.jpg` is currently unused.

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

### Two traps

- **Hover state.** The mouse parks wherever it last acted and can leave a
  button looking selected. Move it somewhere neutral (`hover` the page
  heading) before shooting, and verify against the API rather than trusting
  the pixels.
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
   ../attendance-website/public/images/
```

The website loads them at full size in a lightbox, so use the same files.
