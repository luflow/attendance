#!/usr/bin/env python3
"""Rank Nextcloud app-store apps by their best-performing single GitHub release.

Metric: for every app, the highest total download count of any *one* release
published inside the time window (default: last 6 months). Summed over all
assets of that release.

Why this metric: the app store serves the newest compatible version, so a
release's download count is roughly "how many instances updated while it was
current". That makes it a proxy for the active install base -- but it is
biased by release cadence: an app that ships every 3 days spreads the same
user base over many releases and will look smaller than an app that ships
once a quarter. The days/rel column shows the cadence so you can correct for it.

Repos come from the app store API. When that is unreachable, or with --no-store,
the built-in FALLBACK_REPOS list is used instead (smaller, hand-verified).

Requires a GitHub token (5000 req/h instead of 60):
    export GITHUB_TOKEN=ghp_...
    python3 scripts/nc-app-downloads.py --highlight attendance
"""

import argparse
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timedelta, timezone

APPSTORE = "https://apps.nextcloud.com/api/v1/platform/{platform}/apps.json"
GH_RELEASES = "https://api.github.com/repos/{owner}/{repo}/releases?per_page=100&page={page}"
GH_ASSET_RE = re.compile(
    r"https?://github\.com/([^/]+)/([^/]+)/releases/download/", re.I
)

# Verified 2026-08-08: each repo confirmed to carry appinfo/info.xml.
# Used when the app store API is unreachable (--no-store or fetch failure).
FALLBACK_REPOS = [
    ("activity", "nextcloud/activity"),
    ("afterlogic", "afterlogic/nextcloud-connector"),
    ("analytics", "rello/analytics"),
    ("announcementcenter", "nextcloud/announcementcenter"),
    ("app_api", "nextcloud/app_api"),
    ("appointments", "SergeyMosin/Appointments"),
    ("apporder", "juliusknorr/apporder"),
    ("assistant", "nextcloud/assistant"),
    ("attendance", "luflow/attendance"),
    ("audioplayer", "rello/audioplayer"),
    ("bbb", "sualko/cloud_bbb"),
    ("bookmarks", "nextcloud/bookmarks"),
    ("breezedark", "mwalbeck/nextcloud-breeze-dark"),
    ("bruteforcesettings", "nextcloud/bruteforcesettings"),
    ("calendar", "nextcloud/calendar"),
    ("carnet", "CarnetApp/CarnetNextcloud"),
    ("checksum", "westberliner/checksum"),
    ("circles", "nextcloud/circles"),
    ("collectives", "nextcloud/collectives"),
    ("contacts", "nextcloud/contacts"),
    ("context_chat", "nextcloud/context_chat"),
    ("cookbook", "christianlupus/nextcloud-cookbook"),
    ("cookbook", "nextcloud/cookbook"),
    ("dashboard", "nextcloud/dashboard"),
    ("deck", "nextcloud/deck"),
    ("documentserver_community", "nextcloud/documentserver_community"),
    ("epubreader", "e-alfred/epubreader"),
    ("facerecognition", "matiasdelellis/facerecognition"),
    ("files_accesscontrol", "nextcloud/files_accesscontrol"),
    ("files_antivirus", "nextcloud/files_antivirus"),
    ("files_automatedtagging", "nextcloud/files_automatedtagging"),
    ("files_confidential", "nextcloud/files_confidential"),
    ("files_downloadlimit", "nextcloud/files_downloadlimit"),
    ("files_lock", "nextcloud/files_lock"),
    ("files_markdown", "icewind1991/files_markdown"),
    ("files_pdfviewer", "nextcloud/files_pdfviewer"),
    ("files_retention", "nextcloud/files_retention"),
    ("firstrunwizard", "nextcloud/firstrunwizard"),
    ("flow_notifications", "nextcloud/flow_notifications"),
    ("forms", "nextcloud/forms"),
    ("fulltextsearch", "nextcloud/nextant"),
    ("gallery", "nextcloud/gallery"),
    ("galleryplus", "oparoz/galleryplus"),
    ("gpxpod", "julien-nc/gpxpod"),
    ("groupfolders", "nextcloud/groupfolders"),
    ("guests", "nextcloud/guests"),
    ("impersonate", "nextcloud/impersonate"),
    ("integration_deepl", "nextcloud/integration_deepl"),
    ("integration_discourse", "nextcloud/integration_discourse"),
    ("integration_dropbox", "nextcloud/integration_dropbox"),
    ("integration_excalidraw", "nextcloud/integration_excalidraw"),
    ("integration_giphy", "nextcloud/integration_giphy"),
    ("integration_github", "nextcloud/integration_github"),
    ("integration_gitlab", "nextcloud/integration_gitlab"),
    ("integration_google", "nextcloud/integration_google"),
    ("integration_jira", "nextcloud/integration_jira"),
    ("integration_mastodon", "nextcloud/integration_mastodon"),
    ("integration_moodle", "nextcloud/integration_moodle"),
    ("integration_notion", "nextcloud/integration_notion"),
    ("integration_onedrive", "nextcloud/integration_onedrive"),
    ("integration_openai", "nextcloud/integration_openai"),
    ("integration_openproject", "nextcloud/integration_openproject"),
    ("integration_paperless", "nextcloud/integration_paperless"),
    ("integration_reddit", "nextcloud/integration_reddit"),
    ("integration_replicate", "nextcloud/integration_replicate"),
    ("integration_zammad", "nextcloud/integration_zammad"),
    ("integration_zimbra", "nextcloud/integration_zimbra"),
    ("ldap_write_support", "nextcloud/ldap_write_support"),
    ("llm2", "nextcloud/llm2"),
    ("logreader", "nextcloud/logreader"),
    ("mail", "nextcloud/mail"),
    ("maps", "nextcloud/maps"),
    ("memories", "pulsejet/memories"),
    ("money", "powerpaul17/nc_money"),
    ("music", "owncloud/music"),
    ("music", "paulijar/music"),
    ("news", "nextcloud/news"),
    ("nextcloud_announcements", "nextcloud/nextcloud_announcements"),
    ("nextnote", "brantje/nextnote"),
    ("nexttorrent", "self20/NextTorrent"),
    ("notes", "nextcloud/notes"),
    ("notifications", "nextcloud/notifications"),
    ("notify_push", "nextcloud/notify_push"),
    ("ocr", "janis91/ocr"),
    ("ocsms", "nextcloud/ocsms"),
    ("office", "nextcloud/office"),
    ("oidc_login", "pulsejet/nextcloud-oidc-login"),
    ("onlyoffice", "ONLYOFFICE/onlyoffice-nextcloud"),
    ("paper", "andreasjacobsen93/nextcloud-paper"),
    ("passman", "nextcloud/passman"),
    ("password_policy", "nextcloud/password_policy"),
    ("personalfinances", "matiasdelellis/personalfinances"),
    ("phonetrack", "julien-nc/phonetrack"),
    ("photomap", "doc-sebastian/PhotoMap"),
    ("photos", "nextcloud/photos"),
    ("polls", "nextcloud/polls"),
    ("previewgenerator", "nextcloud/previewgenerator"),
    ("privacy", "nextcloud/privacy"),
    ("profiler", "nextcloud/profiler"),
    ("quicknotes", "matiasdelellis/quicknotes"),
    ("quota_warning", "nextcloud/quota_warning"),
    ("recognize", "nextcloud/recognize"),
    ("recommendations", "nextcloud/recommendations"),
    ("registration", "nextcloud/registration"),
    ("related_resources", "nextcloud/related_resources"),
    ("richdocuments", "nextcloud/richdocuments"),
    ("searchlight", "icewind1991/searchlight"),
    ("serverinfo", "nextcloud/serverinfo"),
    ("sketch", "ChristophWurst/sketch"),
    ("social", "nextcloud/social"),
    ("spreed", "nextcloud/spreed"),
    ("stt_whisper2", "nextcloud/stt_whisper2"),
    ("survey_client", "nextcloud/survey_client"),
    ("suspicious_login", "nextcloud/suspicious_login"),
    ("tables", "nextcloud/tables"),
    ("talk_matterbridge", "nextcloud/talk_matterbridge"),
    ("tasks", "nextcloud/tasks"),
    ("terminal", "gabor-udvari/nextcloud-terminal"),
    ("terms_of_service", "nextcloud/terms_of_service"),
    ("text", "nextcloud/text"),
    ("text2image_stablediffusion2", "nextcloud/text2image_stablediffusion2"),
    ("translate2", "nextcloud/translate2"),
    ("twofactor_admin", "nextcloud/twofactor_admin"),
    ("twofactor_gateway", "nextcloud/twofactor_gateway"),
    ("twofactor_nextcloud_notification", "nextcloud/twofactor_nextcloud_notification"),
    ("twofactor_totp", "nextcloud/twofactor_totp"),
    ("twofactor_webauthn", "nextcloud/twofactor_webauthn"),
    ("user_external", "nextcloud/user_external"),
    ("user_oidc", "nextcloud/user_oidc"),
    ("user_saml", "nextcloud/user_saml"),
    ("user_usage_report", "nextcloud/user_usage_report"),
    ("viewer", "nextcloud/viewer"),
    ("vinecellar", "ChristophWurst/vinecellar"),
    ("weather", "nextcloud/weather"),
    ("whiteboard", "nextcloud/whiteboard"),
]


def http_json(url, token=None, retries=4):
    """GET url, return parsed JSON. Backs off on secondary/primary rate limits."""
    headers = {
        "Accept": "application/vnd.github+json",
        "User-Agent": "nc-app-download-compare",
    }
    if token and "api.github.com" in url:
        headers["Authorization"] = f"Bearer {token}"

    for attempt in range(retries):
        req = urllib.request.Request(url, headers=headers)
        try:
            with urllib.request.urlopen(req, timeout=60) as resp:
                return json.loads(resp.read().decode()), resp.headers
        except urllib.error.HTTPError as e:
            # 403/429 with a reset header means rate limited, not forbidden.
            if e.code in (403, 429):
                reset = e.headers.get("x-ratelimit-reset")
                remaining = e.headers.get("x-ratelimit-remaining")
                if remaining == "0" and reset:
                    wait = max(0, int(reset) - int(time.time())) + 2
                    print(f"  rate limited, sleeping {wait}s", file=sys.stderr)
                    time.sleep(wait)
                    continue
                # A 403 without rate-limit headers is a policy denial (blocked
                # egress, unauthorised repo). Retrying it just burns minutes.
                raise RuntimeError(f"403 denied: {e.read(200).decode(errors='replace')}")
            if e.code == 404:
                return None, None
            if attempt == retries - 1:
                raise
            time.sleep(2 ** attempt)
        except (urllib.error.URLError, TimeoutError):
            if attempt == retries - 1:
                raise
            time.sleep(2 ** attempt)
    return None, None


def discover_apps(platforms, use_store=True):
    """Map app id -> (owner, repo), derived from app-store release download URLs."""
    repos, names = {}, {}
    if not use_store:
        return dict(( a, tuple(r.split("/")) ) for a, r in FALLBACK_REPOS), {}
    for platform in platforms:
        try:
            data, _ = http_json(APPSTORE.format(platform=platform))
        except Exception as e:
            print(f"  app store unreachable ({e})", file=sys.stderr)
            data = None
        if not data:
            print(f"  no app list for platform {platform}", file=sys.stderr)
            continue
        print(f"  platform {platform}: {len(data)} apps", file=sys.stderr)
        for app in data:
            app_id = app.get("id")
            if not app_id or app_id in repos:
                continue
            trans = app.get("translations", {})
            names[app_id] = (
                trans.get("en", {}).get("name") or app_id
            )
            for rel in app.get("releases", []):
                m = GH_ASSET_RE.match(rel.get("download") or "")
                if m:
                    repos[app_id] = (m.group(1), m.group(2))
                    break
    if not repos:
        print("  falling back to the built-in verified repo list", file=sys.stderr)
        return dict(( a, tuple(r.split("/")) ) for a, r in FALLBACK_REPOS), {}
    return repos, names


def best_release(owner, repo, since, token):
    """Return (max downloads for one release, tag, release count) inside window."""
    best, best_tag, count = 0, None, 0
    for page in (1, 2, 3):
        data, _ = http_json(
            GH_RELEASES.format(owner=owner, repo=repo, page=page), token=token
        )
        if not data:
            break
        oldest_in_window = False
        for rel in data:
            if rel.get("draft"):
                continue
            published = rel.get("published_at")
            if not published:
                continue
            when = datetime.fromisoformat(published.replace("Z", "+00:00"))
            if when < since:
                oldest_in_window = True
                continue
            count += 1
            total = sum(a.get("download_count", 0) for a in rel.get("assets", []))
            if total > best:
                best, best_tag = total, rel.get("tag_name")
        if len(data) < 100 or oldest_in_window:
            break
    return best, best_tag, count


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--months", type=int, default=6, help="window size (default 6)")
    p.add_argument(
        "--platforms",
        default="32.0.0,31.0.0,30.0.0",
        help="comma-separated Nextcloud versions to pull the app list for",
    )
    p.add_argument("--highlight", default="attendance", help="app id to centre on")
    p.add_argument("--band", type=float, default=2.0,
                   help="show apps within this factor of the highlighted app")
    p.add_argument("--json-out", help="write the full result table here")
    p.add_argument("--no-store", action="store_true",
                   help="skip the app store, use the built-in verified repo list")
    args = p.parse_args()

    token = os.environ.get("GITHUB_TOKEN") or os.environ.get("GH_TOKEN")
    if not token:
        print("WARNING: no GITHUB_TOKEN set -- 60 requests/hour, this will crawl.",
              file=sys.stderr)

    since = datetime.now(timezone.utc) - timedelta(days=30 * args.months)
    print(f"Window: releases published after {since:%Y-%m-%d}\n", file=sys.stderr)

    print("Discovering apps from the Nextcloud app store ...", file=sys.stderr)
    repos, names = discover_apps(args.platforms.split(","), use_store=not args.no_store)
    print(f"{len(repos)} apps ship their tarball from GitHub releases\n",
          file=sys.stderr)

    rows = []
    for i, (app_id, (owner, repo)) in enumerate(sorted(repos.items()), 1):
        print(f"[{i}/{len(repos)}] {app_id} ({owner}/{repo})", file=sys.stderr)
        # First-party apps publish the store tarball from the release mirror, so
        # the source repo's own releases can be empty or carry no assets.
        candidates = [(owner, repo)]
        if owner.lower() == "nextcloud":
            candidates.append(("nextcloud-releases", repo))
        best, tag, count, src = 0, None, 0, f"{owner}/{repo}"
        for cand_owner, cand_repo in candidates:
            try:
                b, t, c = best_release(cand_owner, cand_repo, since, token)
            except Exception as e:  # a single dead repo must not kill the run
                print(f"  {cand_owner}/{cand_repo} skipped: {e}", file=sys.stderr)
                continue
            if b > best:
                best, tag, src = b, t, f"{cand_owner}/{cand_repo}"
            count = max(count, c)
        if count == 0 or best == 0:
            continue
        rows.append({
            "app": app_id,
            "name": names.get(app_id, app_id),
            "repo": src,
            "max_downloads": best,
            "tag": tag,
            "releases_in_window": count,
            "days_between_releases": round(30 * args.months / count, 1),
        })

    rows.sort(key=lambda r: r["max_downloads"], reverse=True)

    if args.json_out:
        with open(args.json_out, "w") as fh:
            json.dump(rows, fh, indent=2)

    hl = next((r for r in rows if r["app"] == args.highlight), None)

    print(f"\n{'#':>4}  {'app':<28} {'max/release':>11} {'tag':<12} "
          f"{'rels':>5} {'days/rel':>8}")
    print("-" * 76)
    for rank, r in enumerate(rows, 1):
        inband = hl and (
            hl["max_downloads"] / args.band
            <= r["max_downloads"]
            <= hl["max_downloads"] * args.band
        )
        if not inband and r["app"] != args.highlight:
            continue
        mark = " <<<" if r["app"] == args.highlight else ""
        print(f"{rank:>4}  {r['app']:<28} {r['max_downloads']:>11,} "
              f"{(r['tag'] or '-'):<12} {r['releases_in_window']:>5} "
              f"{r['days_between_releases']:>8}{mark}")

    if hl:
        rank = rows.index(hl) + 1
        print(f"\n{args.highlight}: rank {rank} of {len(rows)} "
              f"({hl['max_downloads']:,} downloads on {hl['tag']})")


if __name__ == "__main__":
    main()
