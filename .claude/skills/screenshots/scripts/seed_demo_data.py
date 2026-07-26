#!/usr/bin/env python3
"""Seed a Nextcloud dev instance with choir demo data for screenshots.

Writes appointments and responses straight into `oc_att_appointments` /
`oc_att_responses`, so no user logins are needed to produce "everyone has
answered" screenshots.

Non-destructive: only touches appointment IDs in the reserved range
101-199 and deletes/re-inserts just those. Existing appointments and every
other table are left alone.

All datetimes are naive UTC strings (`Y-m-d H:i:s`) — that is what the app
stores and what `DatetimeFormatTrait` expects. Times are computed relative
to the instance's current clock, so the "running right now" appointment
that unlocks the live check-in button stays correct whenever you re-run it.

Usage:
    python3 seed_demo_data.py --dry-run     # print SQL, change nothing
    python3 seed_demo_data.py               # apply
    python3 seed_demo_data.py --no-config   # only appointments, skip
                                            # display names / groups / locale
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import subprocess
import sys

ID_BASE = 101
ID_MAX = 199

# Voice groups expected on the instance, plus the conductor group we add so
# the person taking the screenshots does not land in the "Others" bucket of
# the response summary.
CONDUCTOR_GROUP = "Conductor"
VOICE_GROUPS = ["Sopran", "Alt", "Tenor", "Bass"]

# uid -> (display name, group). The uids are the standard nextcloud-docker-dev
# accounts; anything missing on the instance is skipped with a warning.
MEMBERS = {
    "admin": ("Christina Vogel", CONDUCTOR_GROUP),
    "alice": ("Alice Weber", "Sopran"),
    "user3": ("Sophie Krüger", "Sopran"),
    "user6": ("Mara Lindner", "Sopran"),
    "jane": ("Jana Hoffmann", "Alt"),
    "user1": ("Lena Bergmann", "Alt"),
    "user5": ("Nadine Schuster", "Alt"),
    "john": ("Jonas Richter", "Tenor"),
    "user4": ("Tobias Brandt", "Tenor"),
    "bob": ("Bernd Kaiser", "Bass"),
    "user2": ("Markus Fuchs", "Bass"),
}

ORGANIZER = "admin"

REHEARSAL_DESC = "Weekly rehearsal in the parish hall. Please bring your sheet music."
SERIES_ID = "demo-rehearsal-series"

Y, N, M = "yes", "no", "maybe"

# Per appointment: (user, response, comment, checkin_state, checkin_source)
# checkin_state "" = not checked in yet. checkin_source "manual" attributes
# the check-in to the organizer, "self_qr"/"self_nfc" to the user themselves.
RESPONSES = {
    "r1": [
        ("admin", Y, "", Y, "manual"),
        ("alice", Y, "", Y, "self_qr"),
        ("user3", Y, "", Y, "self_qr"),
        ("user6", M, "Depends on my shift, I will confirm on Monday.", Y, "self_qr"),
        ("jane", Y, "", Y, "manual"),
        ("user1", Y, "", Y, "self_qr"),
        ("user5", N, "On holiday.", "", None),
        ("john", Y, "", Y, "manual"),
        ("user4", N, "", "", None),
        ("bob", Y, "", Y, "self_nfc"),
        ("user2", Y, "", N, "manual"),
    ],
    "r2": [
        ("admin", Y, "", Y, "manual"),
        ("alice", Y, "", Y, "self_qr"),
        ("user3", M, "", Y, "self_qr"),
        ("user6", Y, "", Y, "self_qr"),
        ("jane", Y, "", Y, "manual"),
        ("user1", N, "Family birthday.", "", None),
        ("user5", Y, "", Y, "self_qr"),
        ("john", Y, "", Y, "self_nfc"),
        ("user4", Y, "", N, "manual"),
        ("bob", Y, "", Y, "self_nfc"),
        ("user2", Y, "Might be 15 minutes late.", Y, "self_qr"),
    ],
    "concert": [
        ("admin", Y, "", Y, "manual"),
        ("alice", Y, "", Y, "self_qr"),
        ("user3", Y, "", Y, "self_qr"),
        ("user6", Y, "", Y, "self_qr"),
        ("jane", Y, "I can bring the folding chairs.", Y, "manual"),
        ("user1", Y, "", Y, "self_qr"),
        ("user5", N, "On holiday that week, sorry!", "", None),
        ("john", Y, "", Y, "self_nfc"),
        ("user4", Y, "Train arrives 19:20 – will join for the warm-up.", Y, "manual"),
        ("bob", Y, "", Y, "self_nfc"),
        ("user2", Y, "", Y, "self_qr"),
    ],
    # Running right now. Deliberately mixes all three check-in states among
    # the first alphabetical entries (Alice/Bernd/Christina/Jana/Jonas) so a
    # screenshot of the top of the list shows present, absent and pending
    # side by side — the button styling only reads clearly in contrast.
    "extra": [
        ("admin", Y, "", Y, "manual"),
        ("alice", Y, "", Y, "self_qr"),
        ("user3", Y, "", Y, "self_qr"),
        ("user6", M, "Trying to swap my shift.", "", None),
        ("jane", Y, "", Y, "self_qr"),
        ("user1", Y, "", "", None),
        ("user5", N, "", "", None),
        ("john", Y, "", "", None),
        ("user4", Y, "", "", None),
        ("bob", Y, "", N, "manual"),
        ("user2", N, "Night shift.", "", None),
    ],
    # admin deliberately has NO row here, so the dashboard widget shows the
    # unanswered call-to-action instead of a filled-in state.
    "r3": [
        ("alice", Y, "", "", None),
        ("user3", Y, "", "", None),
        ("user6", M, "", "", None),
        ("jane", Y, "", "", None),
        ("user1", N, "Parents' evening at school.", "", None),
        ("user5", Y, "", "", None),
        ("john", Y, "", "", None),
        ("user4", Y, "", "", None),
        ("bob", Y, "", "", None),
        ("user2", Y, "", "", None),
    ],
    "r4": [
        ("admin", Y, "", "", None),
        ("alice", Y, "", "", None),
        ("user3", M, "", "", None),
        ("user6", Y, "", "", None),
        ("jane", Y, "", "", None),
        ("user1", Y, "", "", None),
        ("user5", N, "", "", None),
        ("john", Y, "", "", None),
        ("user4", M, "Might be 15 minutes late.", "", None),
        ("bob", Y, "", "", None),
    ],
    "r5": [
        ("admin", Y, "", "", None),
        ("alice", Y, "", "", None),
        ("user3", M, "", "", None),
        ("user6", N, "", "", None),
        ("jane", Y, "", "", None),
        ("user1", Y, "", "", None),
        ("user5", N, "Still on holiday.", "", None),
        ("john", Y, "", "", None),
        ("user2", Y, "", "", None),
    ],
    "dress": [
        ("admin", Y, "", "", None),
        ("alice", Y, "", "", None),
        ("user3", Y, "", "", None),
        ("user6", Y, "", "", None),
        ("jane", Y, "", "", None),
        ("user1", Y, "", "", None),
        ("user5", N, "Wedding of a friend.", "", None),
        ("john", Y, "", "", None),
        ("user4", Y, "", "", None),
        ("bob", Y, "", "", None),
        ("user2", Y, "", "", None),
    ],
    "gig": [
        ("admin", Y, "", "", None),
        ("alice", Y, "", "", None),
        ("user3", Y, "", "", None),
        ("user6", Y, "", "", None),
        ("jane", Y, "I can drive four people and the keyboard.", "", None),
        ("user1", Y, "", "", None),
        ("user5", Y, "", "", None),
        ("john", Y, "", "", None),
        ("user4", Y, "", "", None),
        ("bob", Y, "", "", None),
        ("user2", M, "Depends on the babysitter.", "", None),
    ],
    "weekend": [
        ("admin", Y, "", "", None),
        ("alice", Y, "", "", None),
        ("user3", Y, "", "", None),
        ("user6", M, "", "", None),
        ("jane", Y, "", "", None),
        ("user1", Y, "", "", None),
        ("user5", N, "", "", None),
        ("john", Y, "", "", None),
        ("user4", M, "", "", None),
        ("bob", Y, "", "", None),
        ("user2", M, "", "", None),
    ],
}


def run(cmd: list[str], check: bool = True) -> str:
    res = subprocess.run(cmd, capture_output=True, text=True)
    if check and res.returncode != 0:
        sys.exit(f"command failed: {' '.join(cmd)}\n{res.stderr.strip()}")
    return res.stdout


def docker_names() -> list[str]:
    return run(["docker", "ps", "--format", "{{.Names}}"]).split()


def autodetect(names: list[str], wanted: list[str], label: str) -> str:
    for want in wanted:
        for name in names:
            if want in name:
                return name
    sys.exit(f"could not autodetect the {label} container (looked for {wanted}). "
             f"Running: {', '.join(names) or 'none'}. Pass it explicitly.")


def occ(container: str, *args: str, check: bool = True) -> str:
    return run(["docker", "exec", "-u", "www-data", container, "php", "occ", *args],
               check=check)


def db_config(container: str) -> dict:
    php = ('include "/var/www/html/config/config.php";'
           'echo json_encode(["dbname"=>$CONFIG["dbname"],'
           '"dbuser"=>$CONFIG["dbuser"],"dbpassword"=>$CONFIG["dbpassword"]]);')
    out = run(["docker", "exec", container, "php", "-r", php])
    try:
        return json.loads(out.strip())
    except json.JSONDecodeError:
        sys.exit(f"could not read DB credentials from config.php:\n{out}")


def instance_now(container: str) -> dt.datetime:
    """UTC clock of the instance — appointment times must line up with it."""
    out = run(["docker", "exec", container, "date", "-u", "+%Y-%m-%d %H:%M:%S"])
    return dt.datetime.strptime(out.strip(), "%Y-%m-%d %H:%M:%S")


def q(v) -> str:
    if v is None:
        return "NULL"
    if isinstance(v, int):
        return str(v)
    return "'" + str(v).replace("\\", "\\\\").replace("'", "''") + "'"


def at(day: dt.date, hour: int, minute: int = 0) -> dt.datetime:
    """Local wall-clock time -> naive UTC. Europe/Berlin summer time is UTC+2.

    Screenshots are taken in summer; a two-hour offset keeps rehearsals at a
    plausible 20:00 local. Adjust if you shoot in winter.
    """
    return dt.datetime.combine(day, dt.time(hour, minute)) - dt.timedelta(hours=2)


def weekday_near(base: dt.date, weekday: int, offset_weeks: int) -> dt.date:
    """The `weekday` (0=Mon) of the week `offset_weeks` away from base."""
    monday = base - dt.timedelta(days=base.weekday())
    return monday + dt.timedelta(weeks=offset_weeks, days=weekday)


def build_appointments(now: dt.datetime) -> list[dict]:
    today = now.date()
    tue, sat, fri = 1, 5, 4

    # The running appointment: started 90 min ago, ends in 60 min. That is
    # inside the self-check-in window (default 30 min before start), so the
    # "Start check-in" button on the dashboard widget is live.
    extra_start = now - dt.timedelta(minutes=90)
    extra_end = now + dt.timedelta(minutes=60)

    def rehearsal(weeks: int, pos: int) -> dict:
        day = weekday_near(today, tue, weeks)
        return dict(key=f"r{pos + 1}", name="Rehearsal", desc=REHEARSAL_DESC,
                    start=at(day, 20), end=at(day, 22), series_pos=pos)

    appts = [
        rehearsal(-3, 0),
        rehearsal(-2, 1),
        dict(key="concert", name="Summer Concert",
             desc="Open air concert in the Stadtpark. Please be there by 19:00 for the warm-up.",
             start=at(weekday_near(today, sat, -1), 19, 30),
             end=at(weekday_near(today, sat, -1), 22)),
        dict(key="extra", name="Extra Rehearsal",
             desc="Last run-through before the open air gig. Full programme, no breaks.",
             start=extra_start, end=extra_end,
             # Without a deadline in the future the auto-close job would shut
             # this one (deadline falls back to start, which has passed).
             deadline=extra_end),
        rehearsal(1, 2),
        rehearsal(2, 3),
        rehearsal(3, 4),
        dict(key="dress", name="Dress Rehearsal",
             desc="Full dress rehearsal with the band. Please make sure to sing all songs by heart!",
             start=at(weekday_near(today, fri, 3), 19),
             end=at(weekday_near(today, fri, 3), 22)),
        dict(key="gig", name="Open Air Gig",
             desc="Marktplatz main stage. We will sing all songs of the summer programme.",
             start=at(weekday_near(today, sat, 3), 18, 30),
             end=at(weekday_near(today, sat, 3), 21, 30),
             deadline=at(weekday_near(today, sat, 2), 23, 59)),
        dict(key="weekend", name="Choir Weekend",
             desc="Kloster Banz. Two days of intensive rehearsals, plus singing together in the evening.",
             start=at(weekday_near(today, sat, 8), 9),
             end=at(weekday_near(today, sat, 8) + dt.timedelta(days=1), 16),
             deadline=at(weekday_near(today, sat, 6), 23, 59)),
    ]

    for i, a in enumerate(appts, start=ID_BASE):
        a["id"] = i
        a.setdefault("series_pos", None)
        a.setdefault("deadline", None)
        # Past appointments must be closed, or the auto-close job would do it
        # on the next cron run and the screenshot would go stale.
        a["closed"] = a["start"] if a["end"] < now else None
    return appts


def build_sql(appts: list[dict], known_users: set[str]) -> list[str]:
    fmt = "%Y-%m-%d %H:%M:%S"
    out = [
        "SET NAMES utf8mb4;",
        f"DELETE FROM oc_att_responses WHERE appointment_id BETWEEN {ID_BASE} AND {ID_MAX};",
        f"DELETE FROM oc_att_appointments WHERE id BETWEEN {ID_BASE} AND {ID_MAX};",
    ]
    groups = json.dumps([CONDUCTOR_GROUP] + VOICE_GROUPS)
    organizers = json.dumps([ORGANIZER])

    for a in appts:
        created = (a["start"] - dt.timedelta(days=9)).strftime(fmt)
        cols = ("id, name, description, start_datetime, end_datetime, created_by, "
                "created_at, updated_at, is_active, visible_users, visible_groups, "
                "visible_teams, series_id, series_position, send_notification, "
                "closed_at, response_deadline, cancelled_at, organizers")
        vals = ", ".join([
            str(a["id"]), q(a["name"]), q(a["desc"]),
            q(a["start"].strftime(fmt)), q(a["end"].strftime(fmt)),
            q(ORGANIZER), q(created), q(created), "1",
            "NULL", q(groups), "NULL",
            q(SERIES_ID) if a["series_pos"] is not None else "NULL",
            str(a["series_pos"]) if a["series_pos"] is not None else "NULL",
            "0",
            q(a["closed"].strftime(fmt)) if a["closed"] else "NULL",
            q(a["deadline"].strftime(fmt)) if a["deadline"] else "NULL",
            "NULL", q(organizers),
        ])
        out.append(f"INSERT INTO oc_att_appointments ({cols}) VALUES ({vals});")

        for idx, (user, resp, comment, ci, ci_src) in enumerate(RESPONSES[a["key"]]):
            if user not in known_users:
                continue
            responded = (a["start"] - dt.timedelta(days=9)
                         + dt.timedelta(hours=idx * 5 + 2)).strftime(fmt)
            ci_at = (a["start"] - dt.timedelta(minutes=18)
                     + dt.timedelta(minutes=idx * 3)).strftime(fmt) if ci else None
            ci_by = ("" if not ci else (ORGANIZER if ci_src == "manual" else user))
            rcols = ("appointment_id, user_id, response, comment, responded_at, "
                     "checkin_state, checkin_comment, checkin_by, checkin_at, "
                     "response_source, checkin_source")
            rvals = ", ".join([
                str(a["id"]), q(user), q(resp), q(comment), q(responded),
                q(ci), q(""), q(ci_by), q(ci_at),
                q("quick_link" if idx % 4 == 3 else "app"), q(ci_src),
            ])
            out.append(f"INSERT INTO oc_att_responses ({rcols}) VALUES ({rvals});")
    return out


def apply_config(nc: str, known_users: set[str]) -> None:
    print("configuring instance …")
    existing_groups = occ(nc, "group:list", "--output=json")
    try:
        groups = json.loads(existing_groups)
    except json.JSONDecodeError:
        groups = {}
    if CONDUCTOR_GROUP not in groups:
        occ(nc, "group:add", CONDUCTOR_GROUP, check=False)
        print(f"  + group {CONDUCTOR_GROUP}")

    for uid, (name, group) in MEMBERS.items():
        if uid not in known_users:
            print(f"  ! user {uid} missing on this instance — skipped")
            continue
        occ(nc, "user:setting", uid, "settings", "display_name", name, check=False)
        if group == CONDUCTOR_GROUP:
            occ(nc, "group:adduser", CONDUCTOR_GROUP, uid, check=False)
    print(f"  + display names for {len(known_users & MEMBERS.keys())} users")

    # Without every group on the whitelist, members of the missing ones are
    # lumped into an "Others" row in the response summary, which reads like a
    # data bug in a screenshot.
    whitelist = json.dumps([CONDUCTOR_GROUP] + VOICE_GROUPS)
    occ(nc, "config:app:set", "attendance", "whitelisted_groups", f"--value={whitelist}")
    print(f"  + whitelisted_groups = {whitelist}")

    # English UI with English date formats. lang=en alone would still render
    # dates German if the locale stayed de_DE.
    occ(nc, "user:setting", ORGANIZER, "core", "lang", "en", check=False)
    occ(nc, "user:setting", ORGANIZER, "core", "locale", "en_GB", check=False)
    print(f"  + {ORGANIZER}: lang=en locale=en_GB")


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__,
                                formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--nc-container", help="Nextcloud container (autodetected)")
    p.add_argument("--db-container", help="database container (autodetected)")
    p.add_argument("--dry-run", action="store_true", help="print SQL, change nothing")
    p.add_argument("--no-config", action="store_true",
                   help="only seed appointments; skip display names, groups, locale")
    args = p.parse_args()

    names = docker_names()
    nc = args.nc_container or autodetect(names, ["stable", "nextcloud-1"], "Nextcloud")
    db = args.db_container or autodetect(names, ["database", "mysql", "mariadb"], "database")

    users = set(json.loads(occ(nc, "user:list", "--output=json")).keys())
    now = instance_now(nc)
    appts = build_appointments(now)
    sql = build_sql(appts, users)

    if args.dry_run:
        print(f"-- instance: {nc} / db: {db} / now (UTC): {now}")
        print("\n".join(sql))
        return

    print(f"instance {nc}, db {db}, now (UTC) {now}")
    if not args.no_config:
        apply_config(nc, users)

    cfg = db_config(nc)
    cmd = ["docker", "exec", "-i", db, "mysql",
           f"-u{cfg['dbuser']}", f"-p{cfg['dbpassword']}", cfg["dbname"]]
    res = subprocess.run(cmd, input="\n".join(sql), capture_output=True, text=True)
    err = "\n".join(l for l in res.stderr.splitlines()
                    if "Using a password" not in l).strip()
    if res.returncode != 0:
        sys.exit(f"seeding failed:\n{err}")
    if err:
        print(err)

    print(f"seeded {len(appts)} appointments (IDs {ID_BASE}-{appts[-1]['id']}):")
    for a in appts:
        state = "closed" if a["closed"] else "open"
        running = "  <- running now" if a["start"] <= now <= a["end"] else ""
        print(f"  {a['id']}  {a['start']:%a %d %b %H:%M} UTC  {a['name']:<16} {state}{running}")


if __name__ == "__main__":
    main()
