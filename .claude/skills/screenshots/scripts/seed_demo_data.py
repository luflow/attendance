#!/usr/bin/env python3
"""Seed a Nextcloud dev instance with choir demo data for screenshots.

Writes appointments and responses straight into `oc_att_appointments` /
`oc_att_responses`, so no user logins are needed to produce "everyone has
answered" screenshots.

Non-destructive: only touches IDs in the reserved range 101-399 and
deletes/re-inserts just those. Anything created through the UI afterwards
gets an ID from 1000 up, so it survives a re-run.

All datetimes are naive UTC strings (`Y-m-d H:i:s`) — that is what the app
stores and what `DatetimeFormatTrait` expects. Times are computed relative
to the instance's current clock, so the "running right now" appointment
that unlocks the live check-in button stays correct whenever you re-run it.

Usage:
    python3 seed_demo_data.py --dry-run     # print SQL, change nothing
    python3 seed_demo_data.py               # apply (English UI + data)
    python3 seed_demo_data.py --lang de     # German UI + data (website shots)
    python3 seed_demo_data.py --no-config   # only appointments, skip
                                            # display names / groups / locale
    python3 seed_demo_data.py --test-cases  # plus one appointment per case a
                                            # release test has to look at
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import subprocess
import sys
from zoneinfo import ZoneInfo

ID_BASE = 101
ID_MAX = 399
# First ID handed to rows created through the UI after a seed.
FREE_ID_BASE = 1000

# The redesign showcase (--design-states) lives at the top of the reserved
# range so it never collides with the ten app store appointments.
DESIGN_ID_BASE = 151

# The release test cases (--test-cases) sit above everything a screenshot run
# seeds, so the two never have to know about each other.
TEST_ID_BASE = 201

# Voice groups, plus the conductor group we add so
# the person taking the screenshots does not land in the "Others" bucket of
# the response summary. The conductor group is per language because the
# response summary renders group IDs, not display names — renaming the
# display name of "Conductor" would not change what the screenshot shows.
CONDUCTOR_GROUP = "Conductor"  # sentinel in MEMBERS; resolved per language
CONDUCTOR_GROUPS = {"en": "Conductor", "de": "Chorleitung"}
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

# Dev-instance logins for looking at the app as an ordinary member (--test-cases).
# Local test data only; the accounts are the standard nextcloud-docker-dev ones.
TEST_PASSWORD = "attendance-test-1"
TEST_LOGINS = ["alice", "john"]

SERIES_ID = "demo-rehearsal-series"
COACHING_SERIES_ID = "demo-coaching-series"
SPRING_SERIES_ID = "demo-spring-project"
ALLDAY_SERIES_ID = "demo-allday-series"

BERLIN = ZoneInfo("Europe/Berlin")

# Demo categories, written into `oc_att_categories` in the same reserved ID
# range as the appointments. Icons must be keys of CategoryService::ICONS.
# (category key, icon, name per language)
CATEGORIES = [
    ("rehearsal", "microphone", {"en": "Rehearsal", "de": "Probe"}),
    ("performance", "theater", {"en": "Performance", "de": "Auftritt"}),
    ("weekend", "cabin", {"en": "Choir weekend", "de": "Chorwochenende"}),
    ("party", "celebration", {"en": "Party", "de": "Fest"}),
]

# Category templates, written by --test-cases: what a new appointment of that
# category starts with. Every field is optional; "party" has no template at
# all, to see the unfilled form. Per category: description, groups the
# appointment is limited to, and the other fields (name/location per language).
CATEGORY_TEMPLATES = [
    ("rehearsal", {"en": "Weekly rehearsal. Please bring your sheet music.",
                   "de": "Wöchentliche Probe. Bitte Noten mitbringen."}, VOICE_GROUPS,
     {"name": {"en": "Rehearsal", "de": "Probe"},
      "location": {"en": "Parish hall St. Martin", "de": "Gemeindesaal St. Martin"},
      "responseDeadlineValue": 2, "responseDeadlineUnit": "days",
      "allowMaybe": True}),
    ("performance", {"en": "Performance. Please be there 45 minutes early for the warm-up.",
                     "de": "Auftritt. Bitte 45 Minuten vorher zum Einsingen da sein."},
     ["Sopran", "Alt"],
     {"name": {"en": "Performance", "de": "Auftritt"},
      "responseDeadlineValue": 1, "responseDeadlineUnit": "weeks",
      "maxAttendees": 40, "waitlistEnabled": True,
      "sendNotification": True}),
    ("weekend", {"en": "Choir weekend with full board.",
                 "de": "Chorwochenende mit Vollverpflegung."}, [],
     {"location": {"en": "Kloster Banz", "de": "Kloster Banz"},
      "maxAttendees": 30, "waitlistEnabled": False,
      "allowMaybe": False}),
]

# appointment key -> category key. d_unanswered stays uncategorized on
# purpose so the design states also show the no-category card.
CATEGORY_FOR = {
    "r1": "rehearsal", "r2": "rehearsal", "r3": "rehearsal",
    "r4": "rehearsal", "r5": "rehearsal",
    "extra": "rehearsal", "dress": "rehearsal",
    "concert": "performance", "gig": "performance",
    "weekend": "weekend",
    "d_maybe": "rehearsal", "d_no": "rehearsal",
    "d_booked": "party", "d_declined": "performance",
    "d_cancelled": "rehearsal",
    "t_nomaybe": "rehearsal", "t_org_only": "rehearsal",
    "t_not_involved": "rehearsal", "t_checkin": "rehearsal",
    "t_coaching": "rehearsal", "t_spring": "rehearsal",
    "t_waitlist": "weekend", "t_full": "performance",
    "t_spots": "performance", "t_retract": "performance",
    "t_cancelled": "party",
    "t_allday_single": "party", "t_allday_multi": "weekend",
    "t_allday_series": "performance",
}

# Only a few appointments carry a location — screenshots should show both the
# with- and without-location card. Proper nouns stay German in both languages.
LOCATIONS = {
    "extra": {"en": "Parish hall St. Martin", "de": "Gemeindesaal St. Martin"},
    "concert": {"en": "Stadtpark, Bamberg", "de": "Stadtpark, Bamberg"},
    "weekend": {"en": "Kloster Banz, Bad Staffelstein",
                "de": "Kloster Banz, Bad Staffelstein"},
    "t_waitlist": {"en": "Music school, room 3", "de": "Musikschule, Raum 3"},
    "t_retract": {"en": "Schloss Seehof, Memmelsdorf",
                  "de": "Schloss Seehof, Memmelsdorf"},
}

# Appointment name + description per language, keyed like RESPONSES. The
# store screenshots ship in English; `--lang de` produces the same data in
# German for the website's /de pages (the app store itself cannot take
# per-language screenshots — info.xsd's screenshot type has no lang attribute).
TEXTS = {
    "rehearsal": {
        "en": ("Rehearsal",
               "Weekly rehearsal in the parish hall. Please bring your sheet music."),
        "de": ("Probe",
               "Wöchentliche Probe im Gemeindesaal. Bitte Noten mitbringen."),
    },
    "concert": {
        "en": ("Summer Concert",
               "Open air concert in the Stadtpark. Please be there by 19:00 for the warm-up."),
        "de": ("Sommerkonzert",
               "Open-Air-Konzert im Stadtpark. Bitte bis 19:00 zum Einsingen da sein."),
    },
    "extra": {
        "en": ("Extra Rehearsal",
               "Last run-through before the open air gig. Full programme, no breaks."),
        "de": ("Zusatzprobe",
               "Letzter Durchlauf vor dem Open-Air-Auftritt. Volles Programm, ohne Pausen."),
    },
    "dress": {
        "en": ("Dress Rehearsal",
               "Full dress rehearsal with the band. Please make sure to sing all songs by heart!"),
        "de": ("Generalprobe",
               "Generalprobe mit Band. Bitte alle Stücke auswendig können!"),
    },
    "gig": {
        "en": ("Open Air Gig",
               "Marktplatz main stage. We will sing all songs of the summer programme."),
        "de": ("Open-Air-Auftritt",
               "Hauptbühne am Marktplatz. Wir singen das komplette Sommerprogramm."),
    },
    "weekend": {
        "en": ("Choir Weekend",
               "Kloster Banz. Two days of intensive rehearsals, plus singing together in the evening."),
        "de": ("Chorwochenende",
               "Kloster Banz. Zwei Tage intensive Proben, abends gemeinsames Singen."),
    },
    "d_maybe": {
        "en": ("Voice rehearsal — Tenor & Bass",
               "Tenor and bass only. We work on the transitions in the second movement."),
        "de": ("Stimmgruppenprobe Tenor",
               "Nur Tenor und Bass. Wir arbeiten an den Übergängen im zweiten Satz."),
    },
    "d_no": {
        "en": ("Rehearsal with orchestra",
               "Joint rehearsal with the chamber orchestra. Please be on time."),
        "de": ("Probe mit Orchester",
               "Gemeinsame Probe mit dem Kammerorchester. Bitte pünktlich sein."),
    },
    "d_booked": {
        "en": ("Summer party at the club house",
               "We are celebrating the end of the season! Food and drinks are taken care of.\n\n"
               "## What to bring\n\nGood spirits, and sturdy shoes for the games.\n\n"
               "> Please answer by Friday so we can plan ahead."),
        "de": ("Sommerfest im Vereinsheim",
               "Wir feiern den Saisonabschluss! Für Essen und Getränke ist gesorgt.\n\n"
               "## Mitbringen\n\nGute Laune und festes Schuhwerk für die Spiele.\n\n"
               "> Bitte bis Freitag antworten, damit wir planen können."),
    },
    "d_declined": {
        "en": ("Town festival gig — Shift 2",
               "Second shift on the main stage. We only need half the choir."),
        "de": ("Auftritt Stadtfest — Schicht 2",
               "Zweite Schicht auf der Hauptbühne. Wir brauchen nur die halbe Besetzung."),
    },
    "d_cancelled": {
        "en": ("Voice rehearsal — Sopran",
               "Cancelled — the room is booked that evening."),
        "de": ("Stimmgruppenprobe Sopran",
               "Fällt aus — der Raum ist an dem Abend belegt."),
    },
    "d_unanswered": {
        "en": ("Choir trip planning meeting",
               "Short meeting about the choir trip: date, lodging, programme."),
        "de": ("Chorfahrt Planungstreffen",
               "Kurzes Treffen zur Chorfahrt: Termin, Unterkunft, Programm."),
    },
    # --- release test cases (--test-cases) ----------------------------------
    # The name says which case it is, the description what to check.
    "t_nomaybe": {
        "en": ("Sectional rehearsal (no “Maybe”)",
               "Test case: only Yes and No are offered, “Maybe” is switched off for "
               "this appointment. Nadine is on vacation and was set to No automatically."),
        "de": ("Registerprobe (ohne „Vielleicht“)",
               "Testfall: Hier gibt es nur Ja und Nein, „Vielleicht“ ist für diesen "
               "Termin abgeschaltet. Nadine ist im Urlaub und wurde automatisch auf "
               "Nein gesetzt."),
    },
    "t_waitlist": {
        "en": ("Vocal workshop (max. 4, waitlist)",
               "Test case: 4 spots, all taken, Sophie and Lena on the waitlist. "
               "Answering Yes puts you in line; once somebody with a spot declines, "
               "the next person moves up."),
        "de": ("Stimm-Workshop (max. 4, Warteliste)",
               "Testfall: 4 Plätze, alle belegt, Sophie und Lena stehen auf der "
               "Warteliste. Mit Ja landest du auf der Warteliste; sagt jemand mit "
               "Platz ab, rückt die nächste Person nach."),
    },
    "t_full": {
        "en": ("Small ensemble (max. 3, no waitlist)",
               "Test case: 3 spots, all taken, no waitlist — Yes is refused until "
               "a spot frees up."),
        "de": ("Kleines Ensemble (max. 3, ohne Warteliste)",
               "Testfall: 3 Plätze, alle belegt, keine Warteliste — Ja wird "
               "abgelehnt, bis ein Platz frei wird."),
    },
    "t_spots": {
        "en": ("Care home concert (max. 8, spots left)",
               "Test case: 3 of 8 spots taken. Two organizers, Christina and Jana, "
               "for the organizer row of the export."),
        "de": ("Vorsingen im Seniorenheim (max. 8, Plätze frei)",
               "Testfall: 3 von 8 Plätzen belegt. Zwei Organisatorinnen, Christina "
               "und Jana, für die Organisatoren-Zeile im Export."),
    },
    "t_cancelled": {
        "en": ("Choir social (cancelled)",
               "Test case: cancelled — stays in the list while no status filter is "
               "set, and disappears under Opened and Closed."),
        "de": ("Chor-Stammtisch (abgesagt)",
               "Testfall: abgesagt — bleibt ohne Statusfilter in der Liste und "
               "verschwindet unter Offen und Geschlossen."),
    },
    "t_org_only": {
        "en": ("Sopran & Alt sectional (organizer only)",
               "Test case: Christina organizes this but is not invited — no answer "
               "buttons, and it does not count as unanswered."),
        "de": ("Probe Sopran & Alt (nur Organisation)",
               "Testfall: Christina organisiert, ist aber nicht eingeladen — keine "
               "Antwort-Buttons, und der Termin zählt nicht als unbeantwortet."),
    },
    "t_not_involved": {
        "en": ("Tenor & Bass sectional (not involved)",
               "Test case: organized by Jonas for Tenor and Bass only. Christina "
               "sees it through her manage permission, neither invited nor organizer."),
        "de": ("Probe Tenor & Bass (nicht beteiligt)",
               "Testfall: von Jonas nur für Tenor und Bass organisiert. Christina "
               "sieht den Termin über ihr Verwaltungsrecht, ist weder eingeladen "
               "noch Organisatorin."),
    },
    "t_checkin": {
        "en": ("Special rehearsal (check-in without answer)",
               "Test case: Tobias and Markus never answered but were checked in — "
               "the summary shows the check-in mark next to their names. Nadine "
               "never answered and did not come."),
        "de": ("Sonderprobe (Check-in ohne Antwort)",
               "Testfall: Tobias und Markus haben nie geantwortet, wurden aber "
               "eingecheckt — die Übersicht zeigt das Check-in-Zeichen neben ihren "
               "Namen. Nadine hat nie geantwortet und war nicht da."),
    },
    "t_retract": {
        "en": ("Wedding gig (take back scheduling)",
               "Test case: closed; Christina, Alice, Jana and Jonas are scheduled, "
               "Bernd and Sophie were told they are not. Reopen, take Alice out of "
               "the plan, close again — Alice has to end up as not scheduled."),
        "de": ("Hochzeitsauftritt (Einplanung zurücknehmen)",
               "Testfall: geschlossen; Christina, Alice, Jana und Jonas sind "
               "eingeplant, Bernd und Sophie haben eine Absage bekommen. Wieder "
               "öffnen, Alice ausplanen, erneut schließen — Alice muss danach als "
               "nicht eingeplant dastehen."),
    },
    "t_allday_single": {
        "en": ("Choir outing (all day)",
               "Test case: a single all-day appointment — shown as a date without a "
               "time in every list, card, check-in and notification."),
        "de": ("Chorausflug (ganztägig)",
               "Testfall: ein einzelner ganztägiger Termin — überall als Datum ohne "
               "Uhrzeit, in Listen, Karten, Check-in und Benachrichtigungen."),
    },
    "t_allday_multi": {
        "en": ("Choir trip (3 days, across the clock change)",
               "Test case: all day, Saturday to Monday over the switch to winter "
               "time. The last day is named as the end, not the day after it."),
        "de": ("Chorfahrt (3 Tage, über die Zeitumstellung)",
               "Testfall: ganztägig, Samstag bis Montag über die Umstellung auf "
               "Winterzeit. Das Ende nennt den letzten Tag, nicht den Tag danach."),
    },
    "t_allday_series": {
        "en": ("Open day (all-day series)",
               "Test case: a weekly all-day series over the clock change — every "
               "occurrence has to stay on whole days."),
        "de": ("Tag der offenen Tür (ganztägige Serie)",
               "Testfall: wöchentliche ganztägige Serie über die Zeitumstellung — "
               "jeder Termin muss auf ganzen Tagen bleiben."),
    },
    "t_coaching": {
        "en": ("Vocal coaching",
               "Weekly vocal coaching in small groups. A long series — test data "
               "for paging the list and for the series filter of the export."),
        "de": ("Stimmbildung",
               "Wöchentliche Stimmbildung in Kleingruppen. Eine lange Serie — "
               "Testdaten für das Nachladen der Liste und den Serienfilter im Export."),
    },
    "t_spring": {
        "en": ("Spring project choir",
               "A finished series — listed under the completed series in the export."),
        "de": ("Projektchor Frühjahr",
               "Eine abgeschlossene Serie — steht im Export unter den "
               "abgeschlossenen Serien."),
    },
}

# Vacation periods for --test-cases, as day offsets from today:
# (user, first day, last day, note per language or None).
VACATIONS = [
    ("user5", 5, 19, {"en": "Mallorca", "de": "Mallorca"}),
    ("john", 45, 52, {"en": "Business trip", "de": "Dienstreise"}),
    ("admin", 78, 91, {"en": "Christmas break", "de": "Weihnachtsurlaub"}),
    ("user3", 110, 117, {"en": "Skiing", "de": "Skiurlaub"}),
    ("alice", -30, -20, None),
]

# Response comments are authored in English in RESPONSES; `--lang de` swaps
# them through this table so no English sneaks into a German screenshot.
COMMENTS_DE = {
    "Depends on my shift, I will confirm on Monday.": "Hängt an meiner Schicht, ich sage Montag Bescheid.",
    "On holiday.": "Im Urlaub.",
    "Family birthday.": "Geburtstag in der Familie.",
    "Might be 15 minutes late.": "Komme evtl. 15 Minuten später.",
    "I can bring the folding chairs.": "Ich kann die Klappstühle mitbringen.",
    "On holiday that week, sorry!": "In der Woche im Urlaub, sorry!",
    "On holiday that week.": "In der Woche im Urlaub.",
    "Train arrives 19:20 – will join for the warm-up.": "Zug kommt 19:20 an – ich bin beim Einsingen dabei.",
    "Trying to swap my shift.": "Ich versuche, meine Schicht zu tauschen.",
    "Night shift.": "Nachtschicht.",
    "Parents' evening at school.": "Elternabend in der Schule.",
    "Still on holiday.": "Immer noch im Urlaub.",
    "Wedding of a friend.": "Hochzeit einer Freundin.",
    "I can drive four people and the keyboard.": "Ich kann vier Leute und das Keyboard fahren.",
    "Depends on the babysitter.": "Hängt vom Babysitter ab.",
}


def text(key: str, lang: str) -> tuple[str, str]:
    return TEXTS[key][lang]


def localize_comment(comment: str, lang: str) -> str:
    if lang == "de" and comment:
        return COMMENTS_DE.get(comment, comment)
    return comment

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
    # --- redesign showcase (--design-states) -------------------------------
    # One appointment per state the appointment card was designed for. The
    # optional 6th tuple element is the scheduling verdict: "booked" (planned
    # in), "declined" (not planned in) or absent/None (undecided).
    "d_maybe": [
        ("admin", M, "Trying to swap my shift.", "", None),
        ("alice", Y, "", "", None),
        ("user3", Y, "", "", None),
        ("user6", M, "", "", None),
        ("jane", Y, "", "", None),
        ("user1", N, "Parents' evening at school.", "", None),
        ("user5", Y, "", "", None),
        ("john", Y, "", "", None),
        ("bob", Y, "", "", None),
    ],
    "d_no": [
        ("admin", N, "On holiday that week.", "", None),
        ("alice", Y, "", "", None),
        ("user3", Y, "", "", None),
        ("user6", Y, "", "", None),
        ("jane", M, "", "", None),
        ("user1", Y, "", "", None),
        ("user5", N, "", "", None),
        ("john", Y, "", "", None),
        ("bob", Y, "", "", None),
    ],
    "d_booked": [
        ("admin", Y, "I can bring the folding chairs.", "", None, "booked"),
        ("alice", Y, "", "", None, "booked"),
        ("user3", Y, "", "", None, "booked"),
        ("user6", Y, "", "", None, "declined"),
        ("jane", Y, "", "", None, "declined"),
        ("user1", M, "", "", None),
        ("user5", N, "", "", None),
        ("john", Y, "", "", None, "booked"),
        ("bob", Y, "", "", None, "declined"),
    ],
    "d_declined": [
        ("admin", Y, "", "", None, "declined"),
        ("alice", Y, "", "", None, "booked"),
        ("user3", Y, "", "", None, "booked"),
        ("user6", Y, "", "", None, "booked"),
        ("jane", Y, "", "", None, "booked"),
        ("user1", M, "", "", None),
        ("user5", N, "Night shift.", "", None),
        ("john", Y, "", "", None, "declined"),
        ("bob", Y, "", "", None, "booked"),
    ],
    "d_cancelled": [
        ("admin", Y, "", "", None),
        ("alice", Y, "", "", None),
        ("user3", M, "", "", None),
        ("user6", Y, "", "", None),
        ("jane", N, "", "", None),
        ("user1", Y, "", "", None),
        ("john", Y, "", "", None),
    ],
    # Nobody from the screenshot account — shows the "no response yet" card
    # plus the response deadline line.
    "d_unanswered": [
        ("alice", Y, "", "", None),
        ("user3", M, "", "", None),
        ("user6", Y, "", "", None),
        ("jane", Y, "", "", None),
        ("user1", N, "", "", None),
        ("john", Y, "", "", None),
    ],
    # --- release test cases (--test-cases) ----------------------------------
    # The optional 7th tuple element carries what only these rows need:
    # "source" (response_source), "notified" (the scheduling verdict a closing
    # handed out) and "waitlist" (what the person was last told about their
    # place). A response of None is a row that only holds a check-in.
    "t_nomaybe": [
        ("alice", Y, "", "", None),
        ("user3", N, "", "", None),
        ("user6", Y, "", "", None),
        ("jane", Y, "", "", None),
        ("user1", Y, "", "", None),
        ("user5", N, "", "", None, None, {"source": "vacation"}),
        ("john", Y, "", "", None),
        ("bob", N, "", "", None),
        ("user2", Y, "", "", None),
    ],
    # Row order is the order the spots were claimed in: four in, two waiting.
    "t_waitlist": [
        ("alice", Y, "", "", None),
        ("jane", Y, "", "", None),
        ("john", Y, "", "", None),
        ("bob", Y, "", "", None),
        ("user3", Y, "", "", None, None, {"waitlist": "waitlisted"}),
        ("user1", Y, "", "", None, None, {"waitlist": "waitlisted"}),
        ("user6", N, "", "", None),
        ("user5", N, "", "", None, None, {"source": "vacation"}),
        ("user2", M, "", "", None),
    ],
    "t_full": [
        ("alice", Y, "", "", None),
        ("user6", Y, "", "", None),
        ("user4", Y, "", "", None),
        ("jane", N, "", "", None),
        ("bob", M, "", "", None),
    ],
    "t_spots": [
        ("admin", Y, "", "", None),
        ("john", Y, "", "", None),
        ("user2", Y, "", "", None),
        ("user1", N, "", "", None),
        ("alice", M, "", "", None),
    ],
    "t_cancelled": [
        ("admin", Y, "", "", None),
        ("alice", Y, "", "", None),
        ("jane", Y, "", "", None),
        ("john", N, "", "", None),
        ("bob", Y, "", "", None),
    ],
    "t_org_only": [
        ("alice", Y, "", "", None),
        ("user3", Y, "", "", None),
        ("jane", M, "", "", None),
        ("user1", Y, "", "", None),
        ("user5", N, "", "", None, None, {"source": "vacation"}),
    ],
    "t_not_involved": [
        ("john", Y, "", "", None),
        ("user4", Y, "", "", None),
        ("bob", N, "", "", None),
    ],
    "t_allday_single": [
        ("alice", Y, "", "", None),
        ("jane", Y, "", "", None),
        ("john", M, "", "", None),
        ("user3", N, "", "", None),
    ],
    "t_allday_multi": [
        ("alice", Y, "", "", None),
        ("jane", Y, "", "", None),
        ("john", Y, "", "", None),
        ("user6", N, "", "", None),
        ("bob", M, "", "", None),
    ],
    "t_allday_series": [
        ("alice", Y, "", "", None),
        ("jane", N, "", "", None),
        ("bob", Y, "", "", None),
    ],
    "t_checkin": [
        ("admin", Y, "", Y, "manual"),
        ("alice", Y, "", Y, "self_qr"),
        ("user3", Y, "", Y, "self_qr"),
        ("user6", M, "", Y, "self_qr"),
        ("jane", Y, "", Y, "manual"),
        ("user1", Y, "", N, "manual"),
        ("john", N, "", "", None),
        ("bob", Y, "", Y, "self_nfc"),
        ("user4", None, "", Y, "manual"),
        ("user2", None, "", Y, "self_qr"),
    ],
    "t_retract": [
        ("admin", Y, "", "", None, "booked", {"notified": "booked"}),
        ("alice", Y, "", "", None, "booked", {"notified": "booked"}),
        ("jane", Y, "", "", None, "booked", {"notified": "booked"}),
        ("john", Y, "", "", None, "booked", {"notified": "booked"}),
        ("bob", Y, "", "", None, None, {"notified": "declined"}),
        ("user3", Y, "", "", None, None, {"notified": "declined"}),
        ("user6", N, "", "", None),
        ("user1", M, "", "", None),
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


def mysql(container: str, cfg: dict, sql: list[str]) -> None:
    cmd = ["docker", "exec", "-i", container, "mysql",
           f"-u{cfg['dbuser']}", f"-p{cfg['dbpassword']}", cfg["dbname"]]
    res = subprocess.run(cmd, input="\n".join(sql), capture_output=True, text=True)
    err = "\n".join(l for l in res.stderr.splitlines()
                    if "Using a password" not in l).strip()
    if res.returncode != 0:
        sys.exit(f"seeding failed:\n{err}")
    if err:
        print(err)


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
    """Europe/Berlin wall-clock time -> naive UTC, daylight saving included."""
    local = dt.datetime.combine(day, dt.time(hour, minute), tzinfo=BERLIN)
    return local.astimezone(dt.timezone.utc).replace(tzinfo=None)


def weekday_near(base: dt.date, weekday: int, offset_weeks: int) -> dt.date:
    """The `weekday` (0=Mon) of the week `offset_weeks` away from base."""
    monday = base - dt.timedelta(days=base.weekday())
    return monday + dt.timedelta(weeks=offset_weeks, days=weekday)


def build_appointments(now: dt.datetime, lang: str) -> list[dict]:
    today = now.date()
    tue, sat, fri = 1, 5, 4

    # The running appointment: started 90 min ago, ends in 60 min. That is
    # inside the self-check-in window (default 30 min before start), so the
    # "Start check-in" button on the dashboard widget is live.
    extra_start = now - dt.timedelta(minutes=90)
    extra_end = now + dt.timedelta(minutes=60)

    def named(key: str, **kw) -> dict:
        name, desc = text(key, lang)
        return dict(key=key, name=name, desc=desc, **kw)

    def rehearsal(weeks: int, pos: int) -> dict:
        day = weekday_near(today, tue, weeks)
        name, desc = text("rehearsal", lang)
        return dict(key=f"r{pos + 1}", name=name, desc=desc,
                    start=at(day, 20), end=at(day, 22), series_pos=pos)

    appts = [
        rehearsal(-3, 0),
        rehearsal(-2, 1),
        named("concert",
              start=at(weekday_near(today, sat, -1), 19, 30),
              end=at(weekday_near(today, sat, -1), 22)),
        named("extra",
              start=extra_start, end=extra_end,
              # NOTE: this deadline does NOT protect the appointment from the
              # auto-close job — autoCloseExpired() closes as soon as the START
              # time has passed, deadline or not. Expect the cron to close it a
              # few minutes after seeding; re-open before each shot (see the
              # skill's "Traps" section).
              deadline=extra_end),
        rehearsal(1, 2),
        rehearsal(2, 3),
        rehearsal(3, 4),
        named("dress",
              start=at(weekday_near(today, fri, 3), 19),
              end=at(weekday_near(today, fri, 3), 22)),
        named("gig",
              start=at(weekday_near(today, sat, 3), 18, 30),
              end=at(weekday_near(today, sat, 3), 21, 30),
              deadline=at(weekday_near(today, sat, 2), 23, 59)),
        named("weekend",
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


def build_design_appointments(now: dt.datetime, lang: str) -> list[dict]:
    """One appointment per state the redesigned card was drawn for.

    Kept out of the default seed so the app store screenshots keep showing the
    plain choir list. Names follow --lang so a German design review and a
    German website shot use the same command.
    """
    today = now.date()
    tue, sat = 1, 5

    def day(weeks: int, weekday: int) -> dt.date:
        return weekday_near(today, weekday, weeks)

    def named(key: str, **kw) -> dict:
        name, desc = text(key, lang)
        return dict(key=key, name=name, desc=desc, **kw)

    appts = [
        named("d_maybe", start=at(day(4, tue), 19, 30), end=at(day(4, tue), 21, 30)),
        named("d_no", start=at(day(5, tue), 19), end=at(day(5, tue), 22)),
        named("d_booked", start=at(day(5, sat), 15), end=at(day(5, sat), 19), closed=True),
        named("d_declined", start=at(day(6, sat), 14), end=at(day(6, sat), 18), closed=True),
        named("d_cancelled", start=at(day(6, tue), 19), end=at(day(6, tue), 21, 30), cancelled=True),
        named("d_unanswered", start=at(day(7, tue), 19), end=at(day(7, tue), 20, 30),
              deadline=at(day(6, sat), 23, 59)),
    ]

    for i, a in enumerate(appts, start=DESIGN_ID_BASE):
        a["id"] = i
        a.setdefault("series_pos", None)
        a.setdefault("deadline", None)
        # Closing/cancelling happened a week before the appointment itself.
        stamp = a["start"] - dt.timedelta(days=7)
        a["closed"] = stamp if a.pop("closed", False) else None
        a["cancelled"] = stamp if a.pop("cancelled", False) else None
    return appts


def on_vacation(user: str, day: dt.date, today: dt.date) -> bool:
    return any(u == user and first <= (day - today).days <= last
               for u, first, last, _ in VACATIONS)


def filler_responses(pos: int, day: dt.date, today: dt.date) -> list[tuple]:
    """Deterministic answers for a long series, so 70 rows need no table.

    The further out an occurrence is, the fewer people have answered it. The
    screenshot account stops answering after the next one or two, which leaves
    more unanswered appointments than one page holds.
    """
    past = day < today
    weeks_out = (day - today).days // 7
    rows = []
    for i, uid in enumerate(MEMBERS):
        if uid == ORGANIZER and weeks_out >= 2:
            continue
        if not past and on_vacation(uid, day, today):
            rows.append((uid, N, "", "", None, None, {"source": "vacation"}))
            continue
        if weeks_out > 6 and (i + pos) % 3:
            continue
        roll = (pos * 7 + i * 3) % 10
        if roll == 9:
            continue
        resp = Y if roll < 6 else N if roll < 8 else M
        checked = past and resp == Y
        rows.append((uid, resp, "", Y if checked else "",
                     ("self_qr" if i % 2 else "manual") if checked else None))
    return rows


def build_test_appointments(now: dt.datetime, lang: str) -> list[dict]:
    """One appointment per case a release test has to look at.

    Plus a 67-part weekly series, about half of it in the past, so every list
    view has more rows than one page holds, and a finished series for the
    export's "completed" section.
    """
    today = now.date()
    mon, wed, thu, fri, sat, sun = 0, 2, 3, 4, 5, 6

    def day(weeks: int, weekday: int) -> dt.date:
        return weekday_near(today, weekday, weeks)

    def named(key: str, **kw) -> dict:
        name, desc = text(key, lang)
        return dict(key=key, name=name, desc=desc, **kw)

    yesterday = today - dt.timedelta(days=1)
    appts = [
        named("t_not_involved", start=at(day(1, wed), 19, 30), end=at(day(1, wed), 21),
              groups=["Tenor", "Bass"], organizers=["john"], created_by="john"),
        named("t_cancelled", start=at(day(1, fri), 20), end=at(day(1, fri), 22),
              cancelled=True),
        named("t_org_only", start=at(day(2, mon), 19, 30), end=at(day(2, mon), 21),
              groups=["Sopran", "Alt"]),
        named("t_nomaybe", start=at(day(2, wed), 19), end=at(day(2, wed), 21),
              allow_maybe=False),
        named("t_waitlist", start=at(day(2, sat), 10), end=at(day(2, sat), 16),
              max_attendees=4),
        named("t_full", start=at(day(2, sun), 17), end=at(day(2, sun), 19),
              max_attendees=3, waitlist=False),
        named("t_spots", start=at(day(4, fri), 15), end=at(day(4, fri), 16, 30),
              max_attendees=8, organizers=[ORGANIZER, "jane"]),
        named("t_retract", start=at(day(4, sat), 14), end=at(day(4, sat), 17),
              closed=True),
        named("t_checkin", start=at(yesterday, 19), end=at(yesterday, 21)),
    ]
    for pos, weeks in enumerate(range(-30, 37)):
        d = day(weeks, thu)
        appts.append(named("t_coaching", start=at(d, 18), end=at(d, 18, 45),
                           series_id=COACHING_SERIES_ID, series_pos=pos,
                           responses=filler_responses(pos, d, today)))
    for pos, weeks in enumerate(range(-24, -20)):
        d = day(weeks, sat)
        appts.append(named("t_spring", start=at(d, 10), end=at(d, 13),
                           series_id=SPRING_SERIES_ID, series_pos=pos,
                           responses=filler_responses(pos, d, today)))

    def all_day(first: dt.date, last: dt.date) -> dict:
        # The creator's midnight of the first day to the midnight after the last.
        return dict(start=at(first, 0), end=at(last + dt.timedelta(days=1), 0),
                    all_day=True)

    appts.append(named("t_allday_single", **all_day(day(1, sun), day(1, sun))))
    # Sat 24 Oct to Mon 26 Oct 2026 spans the end of summer time (25 Oct).
    appts.append(named("t_allday_multi",
                       **all_day(dt.date(2026, 10, 24), dt.date(2026, 10, 26))))
    for pos, d in enumerate([dt.date(2026, 10, 18), dt.date(2026, 10, 25),
                             dt.date(2026, 11, 1), dt.date(2026, 11, 8)]):
        appts.append(named("t_allday_series", series_id=ALLDAY_SERIES_ID,
                           series_pos=pos, **all_day(d, d)))

    for i, a in enumerate(appts, start=TEST_ID_BASE):
        a["id"] = i
        a.setdefault("series_pos", None)
        a.setdefault("deadline", None)
        # Never in the future: these states exist the moment the seed has run.
        stamp = min(a["start"] - dt.timedelta(days=7), now - dt.timedelta(hours=1))
        done = a["start"] if a["end"] < now else None
        a["closed"] = stamp if a.pop("closed", False) else done
        a["cancelled"] = stamp if a.pop("cancelled", False) else None
    return appts


def category_ids() -> dict[str, int]:
    return {key: ID_BASE + i for i, (key, _, _) in enumerate(CATEGORIES)}


def build_category_sql(lang: str) -> list[str]:
    """Demo categories in the reserved ID range, idempotent like the rest.

    The name column is UNIQUE, so a manually created category with the same
    name (e.g. from an earlier UI experiment) is deleted too — otherwise the
    insert would fail.
    """
    names = ", ".join(q(names[lang]) for _, _, names in CATEGORIES)
    out = [
        f"DELETE FROM oc_att_categories WHERE id BETWEEN {ID_BASE} AND {ID_MAX};",
        f"DELETE FROM oc_att_categories WHERE name IN ({names});",
    ]
    ids = category_ids()
    for key, icon, cat_names in CATEGORIES:
        out.append(f"INSERT INTO oc_att_categories (id, name, icon) VALUES "
                   f"({ids[key]}, {q(cat_names[lang])}, {q(icon)});")
    return out


def build_template_sql(lang: str) -> list[str]:
    """Templates for the seeded categories; a plain run leaves the column empty."""
    ids = category_ids()
    out = []
    for key, description, groups, extra in CATEGORY_TEMPLATES:
        template = {"name": "", "description": description[lang], "location": "",
                    "responseDeadlineValue": None, "responseDeadlineUnit": None,
                    "maxAttendees": None, "waitlistEnabled": None,
                    "allowMaybe": None, "sendNotification": None,
                    "visibleUsers": [], "visibleGroups": groups, "visibleTeams": []}
        for field, value in extra.items():
            template[field] = value[lang] if isinstance(value, dict) else value
        out.append(f"UPDATE oc_att_categories SET template = "
                   f"{q(json.dumps(template, ensure_ascii=False))} WHERE id = {ids[key]};")
    return out


def build_sql(appts: list[dict], known_users: set[str], lang: str,
              now: dt.datetime) -> list[str]:
    fmt = "%Y-%m-%d %H:%M:%S"
    out = [
        "SET NAMES utf8mb4;",
        f"DELETE FROM oc_att_responses WHERE appointment_id BETWEEN {ID_BASE} AND {ID_MAX};",
        f"DELETE FROM oc_att_appointments WHERE id BETWEEN {ID_BASE} AND {ID_MAX};",
    ]
    out += build_category_sql(lang)
    everyone = [CONDUCTOR_GROUPS[lang]] + VOICE_GROUPS
    cat_ids = category_ids()

    for a in appts:
        # Never after the seed itself: an appointment months away exists by now.
        created_at = min(a["start"] - dt.timedelta(days=9), now - dt.timedelta(days=3))
        created = created_at.strftime(fmt)
        cat_key = CATEGORY_FOR.get(a["key"])
        location = LOCATIONS.get(a["key"], {}).get(lang)
        cols = ("id, name, description, start_datetime, end_datetime, created_by, "
                "created_at, updated_at, is_active, visible_users, visible_groups, "
                "visible_teams, series_id, series_position, send_notification, "
                "closed_at, response_deadline, cancelled_at, organizers, "
                "location, category_id, allow_maybe, max_attendees, "
                "waitlist_enabled, all_day")
        vals = ", ".join([
            str(a["id"]), q(a["name"]), q(a["desc"]),
            q(a["start"].strftime(fmt)), q(a["end"].strftime(fmt)),
            q(a.get("created_by", ORGANIZER)), q(created), q(created), "1",
            "NULL", q(json.dumps(a.get("groups", everyone))), "NULL",
            q(a.get("series_id", SERIES_ID)) if a["series_pos"] is not None else "NULL",
            str(a["series_pos"]) if a["series_pos"] is not None else "NULL",
            "0",
            q(a["closed"].strftime(fmt)) if a["closed"] else "NULL",
            q(a["deadline"].strftime(fmt)) if a["deadline"] else "NULL",
            q(a["cancelled"].strftime(fmt)) if a.get("cancelled") else "NULL",
            q(json.dumps(a.get("organizers", [ORGANIZER]))),
            q(location),
            str(cat_ids[cat_key]) if cat_key else "NULL",
            "NULL" if a.get("allow_maybe") is None else str(int(a["allow_maybe"])),
            q(a.get("max_attendees")),
            str(int(a.get("waitlist", True))),
            str(int(a.get("all_day", False))),
        ])
        out.append(f"INSERT INTO oc_att_appointments ({cols}) VALUES ({vals});")

        for idx, row in enumerate(a.get("responses") or RESPONSES[a["key"]]):
            user, resp, comment, ci, ci_src = row[:5]
            comment = localize_comment(comment, lang)
            booking = row[5] if len(row) > 5 else None
            extra = row[6] if len(row) > 6 else {}
            if user not in known_users:
                continue
            responded = (created_at + dt.timedelta(hours=idx * 5 + 2)).strftime(fmt)
            if resp is None:
                responded = None
            source = extra.get("source") or ("quick_link" if idx % 4 == 3 else "app")
            notified = extra.get("notified")
            waitlist = extra.get("waitlist")
            ci_at = (a["start"] - dt.timedelta(minutes=18)
                     + dt.timedelta(minutes=idx * 3)).strftime(fmt) if ci else None
            ci_by = ("" if not ci else (ORGANIZER if ci_src == "manual" else user))
            rcols = ("appointment_id, user_id, response, comment, responded_at, "
                     "checkin_state, checkin_comment, checkin_by, checkin_at, "
                     "response_source, checkin_source, booking_status, "
                     "spot_claimed_at, booking_notified_status, "
                     "booking_notified_at, waitlist_notified_status, "
                     "waitlist_notified_at")
            rvals = ", ".join([
                str(a["id"]), q(user), q(resp), q(comment), q(responded),
                q(ci), q(""), q(ci_by), q(ci_at),
                q(source if resp else None), q(ci_src), q(booking),
                # The order of these stamps is the order of the waitlist.
                q(responded if resp == Y else None),
                q(notified),
                q(a["closed"].strftime(fmt) if notified and a["closed"] else None),
                q(waitlist), q(responded if waitlist else None),
            ])
            out.append(f"INSERT INTO oc_att_responses ({rcols}) VALUES ({rvals});")
    return out


def build_vacation_sql(now: dt.datetime, known_users: set[str], lang: str,
                       seed: bool) -> list[str]:
    """Vacation periods in the reserved ID range; a plain run only clears them."""
    out = [f"DELETE FROM oc_att_vacations WHERE id BETWEEN {ID_BASE} AND {ID_MAX};"]
    if not seed:
        return out
    today = now.date()
    stamp = (now - dt.timedelta(days=3)).strftime("%Y-%m-%d %H:%M:%S")
    cols = "id, user_id, start_date, end_date, note, created_at, updated_at"
    for i, (user, first, last, note) in enumerate(VACATIONS, start=ID_BASE):
        if user not in known_users:
            continue
        vals = ", ".join([
            str(i), q(user),
            q(str(today + dt.timedelta(days=first))),
            q(str(today + dt.timedelta(days=last))),
            q(note[lang] if note else None), q(stamp), q(stamp),
        ])
        out.append(f"INSERT INTO oc_att_vacations ({cols}) VALUES ({vals});")
    return out


def build_reserve_sql() -> list[str]:
    # Without this the next row created in the UI gets the first free ID
    # inside the reserved range, and the next seed run deletes it.
    return [f"ALTER TABLE {table} AUTO_INCREMENT = {FREE_ID_BASE};"
            for table in ("oc_att_appointments", "oc_att_categories",
                          "oc_att_vacations")]


# One believable activity history for the concert appointment: created →
# responses trickling in → an edit → a response change → a manager override →
# self check-ins → a manual check-in → auto-close. Verbs live in
# lib/Audit/Verb.php; meta shapes mirror lib/Service/AuditEventService.php.
# The timeline renders verbs localized client-side, so these rows are
# language-independent and serve both the EN and DE audit-log motif.
#
# (verb, actor, subject, meta, source, offset) — offset is relative to the
# appointment's start; negative days reach back into the response phase.
AUDIT_EVENTS = [
    ("appointment.created", "admin", None, {}, "app",
     dt.timedelta(days=-9)),
    ("response.submitted", "alice", "alice",
     {"response": "yes", "comment": ""}, "app", dt.timedelta(days=-9, hours=3)),
    ("response.submitted", "john", "john",
     {"response": "yes", "comment": ""}, "app", dt.timedelta(days=-8, hours=-4)),
    ("response.submitted", "user6", "user6",
     {"response": "yes", "comment": ""}, "app", dt.timedelta(days=-7, hours=2)),
    ("appointment.updated", "admin", None,
     {"fields": ["time", "description", "location"]}, "app",
     dt.timedelta(days=-6, hours=-2)),
    ("response.submitted", "bob", "bob",
     {"response": "maybe", "comment": ""}, "app", dt.timedelta(days=-5, hours=5)),
    ("response.changed", "user6", "user6",
     {"from": "yes", "to": "maybe", "commentChanged": True}, "app",
     dt.timedelta(days=-3, hours=-6)),
    ("response.changed", "admin", "user2",
     {"from": "maybe", "to": "yes", "commentChanged": False}, "admin_response",
     dt.timedelta(days=-1, hours=4)),
    ("checkin.recorded", "alice", "alice",
     {"checkinState": "yes", "checkinComment": ""}, "self_checkin",
     dt.timedelta(minutes=-26)),
    ("checkin.recorded", "john", "john",
     {"checkinState": "yes", "checkinComment": ""}, "self_checkin",
     dt.timedelta(minutes=-21)),
    ("checkin.recorded", "admin", "bob",
     {"checkinState": "no", "checkinComment": ""}, "admin_checkin",
     dt.timedelta(minutes=-4)),
    ("appointment.closed", None, None, {}, "auto_close",
     dt.timedelta(seconds=2)),
]


def build_audit_sql(appts: list[dict], known_users: set[str]) -> list[str]:
    fmt = "%Y-%m-%d %H:%M:%S"
    concert = next((a for a in appts if a["key"] == "concert"), None)
    out = [f"DELETE FROM oc_att_audit_event WHERE appointment_id "
           f"BETWEEN {ID_BASE} AND {ID_MAX};"]
    if concert is None:
        return out
    cols = "appointment_id, verb, actor_id, subject_id, meta, source, created_at"
    for verb, actor, subject, meta, source, offset in AUDIT_EVENTS:
        if (actor and actor not in known_users) or (subject and subject not in known_users):
            continue
        stamp = (concert["start"] + offset).strftime(fmt)
        vals = ", ".join([
            str(concert["id"]), q(verb), q(actor), q(subject),
            q(json.dumps(meta)), q(source), q(stamp),
        ])
        out.append(f"INSERT INTO oc_att_audit_event ({cols}) VALUES ({vals});")
    return out


def apply_config(nc: str, known_users: set[str], lang: str,
                 args_test_cases: bool = False) -> None:
    print("configuring instance …")
    conductor = CONDUCTOR_GROUPS[lang]
    existing_groups = occ(nc, "group:list", "--output=json")
    try:
        groups = json.loads(existing_groups)
    except json.JSONDecodeError:
        groups = {}
    for group in [conductor] + VOICE_GROUPS:
        if group not in groups:
            occ(nc, "group:add", group, check=False)
            print(f"  + group {group}")

    for uid, (name, group) in MEMBERS.items():
        if uid not in known_users:
            print(f"  ! user {uid} missing on this instance — skipped")
            continue
        occ(nc, "user:setting", uid, "settings", "display_name", name, check=False)
        occ(nc, "group:adduser", conductor if group == CONDUCTOR_GROUP else group,
            uid, check=False)
    print(f"  + display names for {len(known_users & MEMBERS.keys())} users")

    # Without every group on the whitelist, members of the missing ones are
    # lumped into an "Others" row in the response summary, which reads like a
    # data bug in a screenshot. The list carries the language's conductor
    # group, matching the visible_groups build_sql writes.
    whitelist = json.dumps([conductor] + VOICE_GROUPS)
    occ(nc, "config:app:set", "attendance", "whitelisted_groups", f"--value={whitelist}")
    print(f"  + whitelisted_groups = {whitelist}")

    # UI language and date format must agree — lang alone would still render
    # dates in the old locale's format.
    ui_lang, ui_locale = ("de", "de_DE") if lang == "de" else ("en", "en_GB")
    occ(nc, "user:setting", ORGANIZER, "core", "lang", ui_lang, check=False)
    occ(nc, "user:setting", ORGANIZER, "core", "locale", ui_locale, check=False)
    print(f"  + {ORGANIZER}: lang={ui_lang} locale={ui_locale}")

    # The check-in list 403s when permission_checkin is left at mode "nobody"
    # — there is no admin bypass, and an e2e run leaves it that way. Point it
    # at the conductor group so the screenshot account can check people in.
    occ(nc, "config:app:set", "attendance", "permission_checkin_mode",
        "--value=groups")
    occ(nc, "config:app:set", "attendance", "permission_checkin",
        f"--value={json.dumps([conductor])}")
    print(f"  + permission_checkin = groups {conductor}")

    if args_test_cases:
        for uid in TEST_LOGINS:
            if uid in known_users:
                run(["docker", "exec", "-u", "www-data", "-e", f"OC_PASS={TEST_PASSWORD}",
                     nc, "php", "occ", "user:resetpassword", "--password-from-env", uid],
                    check=False)
        print(f"  + login for {', '.join(TEST_LOGINS)}: password {TEST_PASSWORD}")

    # Unset, "manage appointments" means everybody, so every dev account would
    # be a manager and the plain member's view could not be looked at.
    occ(nc, "config:app:set", "attendance", "permission_manage_appointments_mode",
        "--value=groups")
    occ(nc, "config:app:set", "attendance", "permission_manage_appointments",
        f"--value={json.dumps([conductor])}")
    print(f"  + permission_manage_appointments = groups {conductor}")

    # The category badge on the cards, and the "Scheduled" / "Not scheduled"
    # states, only render with their feature on.
    occ(nc, "config:app:set", "attendance", "booking_enabled", "--value=yes")
    print("  + booking_enabled = yes")


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__,
                                formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--nc-container", help="Nextcloud container (autodetected)")
    p.add_argument("--db-container", help="database container (autodetected)")
    p.add_argument("--dry-run", action="store_true", help="print SQL, change nothing")
    p.add_argument("--no-config", action="store_true",
                   help="only seed appointments; skip display names, groups, locale")
    p.add_argument("--design-states", action="store_true",
                   help="also seed one appointment per designed card state "
                        "(open/maybe/no, closed + scheduled, closed + not "
                        "scheduled, cancelled, unanswered with deadline)")
    p.add_argument("--test-cases", action="store_true",
                   help="also seed the release test cases (IDs from 201): no "
                        "\"Maybe\", attendance limit with and without "
                        "waitlist, cancelled, organizer only, not involved, "
                        "check-in without answer, a scheduling verdict to "
                        "take back, a 67-part series for paging, a finished "
                        "series and a few vacation periods")
    p.add_argument("--reopen-running", action="store_true",
                   help="re-open the seeded appointment that is running right "
                        "now (autoCloseExpired() closes it minutes after "
                        "seeding) and exit, changing nothing else. Cheap "
                        "enough to call in a loop while shooting.")
    p.add_argument("--lang", choices=["en", "de"], default="en",
                   help="language of the seeded data AND the admin UI. "
                        "'en' for the app store set (the store cannot take "
                        "per-language screenshots), 'de' for the website's "
                        "/de pages. Each run overwrites the other language's "
                        "state — data, whitelist and admin locale.")
    args = p.parse_args()

    names = docker_names()
    nc = args.nc_container or autodetect(names, ["stable", "nextcloud-1"], "Nextcloud")
    db = args.db_container or autodetect(names, ["database", "mysql", "mariadb"], "database")

    if args.reopen_running:
        now = instance_now(nc)
        stamp = f"{now:%Y-%m-%d %H:%M:%S}"
        mysql(db, db_config(nc), [
            f"UPDATE oc_att_appointments SET closed_at = NULL "
            f"WHERE id BETWEEN {ID_BASE} AND {ID_MAX} AND closed_at IS NOT NULL "
            f"AND start_datetime <= '{stamp}' AND end_datetime >= '{stamp}';"
        ])
        return

    users = set(json.loads(occ(nc, "user:list", "--output=json")).keys())
    now = instance_now(nc)
    appts = build_appointments(now, args.lang)
    if args.design_states:
        appts += build_design_appointments(now, args.lang)
    if args.test_cases:
        appts += build_test_appointments(now, args.lang)
    sql = (build_sql(appts, users, args.lang, now) + build_audit_sql(appts, users)
           + build_vacation_sql(now, users, args.lang, args.test_cases)
           + (build_template_sql(args.lang) if args.test_cases else [])
           + build_reserve_sql())

    if args.dry_run:
        print(f"-- instance: {nc} / db: {db} / now (UTC): {now} / lang: {args.lang}")
        print("\n".join(sql))
        return

    print(f"instance {nc}, db {db}, now (UTC) {now}, lang {args.lang}")
    if not args.no_config:
        apply_config(nc, users, args.lang, args.test_cases)

    mysql(db, db_config(nc), sql)

    print(f"seeded {len(CATEGORIES)} categories and {len(appts)} appointments "
          f"(IDs {ID_BASE}-{appts[-1]['id']}):")
    series = {key: sum(a["key"] == key for a in appts)
              for key in ("t_coaching", "t_spring", "t_allday_series")}
    for a in appts:
        if a["key"] in series:
            continue
        state = "cancelled" if a.get("cancelled") else "closed" if a["closed"] else "open"
        running = "  <- running now" if a["start"] <= now <= a["end"] else ""
        cat = CATEGORY_FOR.get(a["key"], "-")
        loc = "  @ " + LOCATIONS[a["key"]][args.lang] if a["key"] in LOCATIONS else ""
        print(f"  {a['id']}  {a['start']:%a %d %b %H:%M} UTC  {a['name']:<20} "
              f"{state:<9} [{cat}]{running}{loc}")
    for key, count in series.items():
        if count:
            print(f"  + {count} × {text(key, args.lang)[0]} (series)")
    if args.test_cases:
        print(f"seeded {len(VACATIONS)} vacation periods")


if __name__ == "__main__":
    main()
