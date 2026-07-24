#!/usr/bin/env python3
"""Transifex translator-feedback helper for the attendance app.

Talks to the Transifex API v3 for the shared Nextcloud project
(o:nextcloud:p:nextcloud, resource r:attendance). Requires a Transifex API
token in the TRANSIFEX_TOKEN environment variable (create one under
transifex.com -> User settings -> API token).

Commands:
  list                       Print all comment threads, grouped per source
                             string, with source text and issue status.
  list --open                Only threads with an open issue or whose last
                             message is not from --me (default: magdeflow).
  list --new                 Only threads with activity newer than the
                             answered_up_to watermark in state.json (kept
                             next to the skill). Combine with --open.
  find TEXT                  Search the resource's source strings by key or
                             source text (case-insensitive substring) and
                             print their string ids.
  reply STRING_ID MESSAGE    Post a reply comment on a string's thread.
                             STRING_ID may be the full id or just the s:<hash>
                             suffix. The language relationship (required by
                             the API) is copied from the thread's existing
                             comments. With --issue, an open issue is created
                             instead of a plain comment. For strings without
                             an existing thread, pass --language (l:de_DE).
  mark-answered              Record the newest comment date seen on the
                             resource as the answered_up_to watermark in
                             state.json (or pass --date ISO explicitly).
                             Run after all replies for a session are posted.
  resolve COMMENT_ID         Mark an issue comment as resolved. NOTE: needs
                             project-maintainer rights; translator-level
                             tokens get 403 (see SKILL.md).

Everything prints JSON to stdout so the caller can parse it.
"""

import argparse
import json
import os
import subprocess
import sys
import urllib.parse

API = 'https://rest.api.transifex.com'
ORGANIZATION = 'o:nextcloud'
PROJECT = 'o:nextcloud:p:nextcloud'
RESOURCE = 'o:nextcloud:p:nextcloud:r:attendance'
STATE_FILE = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', 'state.json')


def read_state():
    try:
        with open(STATE_FILE) as f:
            return json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        return {}


def write_state(state):
    with open(STATE_FILE, 'w') as f:
        json.dump(state, f, indent=1, ensure_ascii=False)
        f.write('\n')


def token():
    value = os.environ.get('TRANSIFEX_TOKEN')
    if not value:
        sys.exit('TRANSIFEX_TOKEN is not set. Create a token under '
                 'transifex.com -> User settings -> API token.')
    return value


def request(method, url, payload=None):
    # curl instead of urllib: it picks up the egress proxy and its CA
    # bundle from the environment without extra configuration.
    # -g: pagination links from the API contain literal [] which curl would
    # otherwise treat as glob ranges.
    cmd = ['curl', '-sSg', '-X', method,
           '-H', f'Authorization: Bearer {token()}',
           '-w', '\n%{http_code}']
    if payload is not None:
        cmd += ['-H', 'Content-Type: application/vnd.api+json',
                '-d', json.dumps(payload)]
    cmd.append(url)
    out = subprocess.run(cmd, capture_output=True, text=True)
    if out.returncode != 0:
        sys.exit(f'curl failed: {out.stderr.strip()}')
    body, _, status = out.stdout.rpartition('\n')
    return int(status), json.loads(body) if body.strip() else {}


def paginate(url):
    while url:
        status, data = request('GET', url)
        if status >= 400:
            sys.exit(f'API error {status}: {json.dumps(data)[:300]}')
        yield from data.get('data', [])
        url = data.get('links', {}).get('next')


def fetch_threads():
    """All comment threads on the resource, grouped per source string."""
    params = urllib.parse.urlencode({
        'filter[organization]': ORGANIZATION,
        'filter[project]': PROJECT,
        'filter[resource]': RESOURCE,
    })
    threads = {}
    for c in paginate(f'{API}/resource_string_comments?{params}'):
        attributes = c['attributes']
        rel = c.get('relationships', {})
        sid = rel.get('resource_string', {}).get('data', {}).get('id', '?')
        threads.setdefault(sid, []).append({
            'comment_id': c['id'],
            'type': attributes.get('type'),
            'status': attributes.get('status'),
            'date': attributes.get('datetime_created', ''),
            'author': rel.get('author', {}).get('data', {}).get('id', '?'),
            'language': rel.get('language', {}).get('data', {}).get('id'),
            'message': attributes.get('message', ''),
        })
    for comments in threads.values():
        comments.sort(key=lambda c: c['date'])
    return threads


def fetch_string(sid):
    status, data = request('GET', f'{API}/resource_strings/{sid}')
    if status >= 400:
        return None
    attributes = data.get('data', {}).get('attributes', {})
    return {
        'key': attributes.get('key'),
        'source': attributes.get('strings'),
        'context': attributes.get('context'),
        'occurrences': attributes.get('occurrences'),
        'developer_comment': attributes.get('developer_comment'),
    }


def full_string_id(string_id):
    if string_id.startswith('o:'):
        return string_id
    return f'{RESOURCE}:s:{string_id.removeprefix("s:")}'


def cmd_list(args):
    threads = fetch_threads()
    watermark = read_state().get('answered_up_to', '') if args.new else ''
    if args.new and not watermark:
        print('state.json has no answered_up_to watermark yet — listing '
              'everything. Run mark-answered after replying.',
              file=sys.stderr)
    out = []
    for sid, comments in threads.items():
        has_open_issue = any(
            c['type'] == 'issue' and c['status'] == 'open' for c in comments)
        needs_attention = has_open_issue or (
            comments and comments[-1]['author'] != f'u:{args.me}')
        if args.open and not needs_attention:
            continue
        # Watermark dates come from the same API field as the comment
        # dates, so plain string comparison of the ISO timestamps works.
        if watermark and comments[-1]['date'] <= watermark:
            continue
        out.append({
            'string_id': sid,
            'string': fetch_string(sid),
            'has_open_issue': has_open_issue,
            'needs_attention': needs_attention,
            'comments': comments,
        })
    out.sort(key=lambda t: t['comments'][-1]['date'], reverse=True)
    json.dump(out, sys.stdout, indent=1, ensure_ascii=False)
    print()


def cmd_find(args):
    params = urllib.parse.urlencode({'filter[resource]': RESOURCE})
    needle = args.text.lower()
    out = []
    for s in paginate(f'{API}/resource_strings?{params}'):
        attributes = s['attributes']
        haystack = (attributes.get('key') or '') + ' ' + json.dumps(
            attributes.get('strings') or {}, ensure_ascii=False)
        if needle in haystack.lower():
            out.append({
                'string_id': s['id'],
                'key': attributes.get('key'),
                'source': attributes.get('strings'),
            })
    json.dump(out, sys.stdout, indent=1, ensure_ascii=False)
    print()


def cmd_reply(args):
    sid = full_string_id(args.string_id)
    threads = fetch_threads()
    thread = threads.get(sid, [])
    if not thread and not args.language:
        sys.exit(f'No existing thread found for {sid} — replies attach to a '
                 'language, which is taken from the thread. For a brand-new '
                 'comment, pass --language (e.g. l:de_DE).')
    # The API masks missing language-team membership as a 404 on the
    # resource string. Try every language seen in the thread (newest comment
    # first) until one is accepted.
    if args.language:
        candidates = [args.language]
    else:
        candidates = []
        for c in reversed(thread):
            if c['language'] and c['language'] not in candidates:
                candidates.append(c['language'])
    # New issues are created open; the API rejects an explicit 'status'
    # attribute on creation.
    attributes = {'message': args.message,
                  'type': 'issue' if args.issue else 'comment'}
    status, data = None, None
    for language in candidates:
        payload = {'data': {
            'type': 'resource_string_comments',
            'attributes': attributes,
            'relationships': {
                'resource_string': {
                    'data': {'type': 'resource_strings', 'id': sid}},
                'language': {'data': {'type': 'languages', 'id': language}},
            },
        }}
        status, data = request(
            'POST', f'{API}/resource_string_comments', payload)
        if status != 404:
            break
    print(json.dumps({'http': status, 'ok': status < 300,
                      'response': data if status >= 300 else data.get(
                          'data', {}).get('id')}))
    sys.exit(0 if status < 300 else 1)


def cmd_mark_answered(args):
    if args.date:
        newest = args.date
    else:
        # Take the newest comment date from the API itself instead of the
        # local clock — clocks may drift, the API is the source of truth.
        newest = max(
            (c['date'] for comments in fetch_threads().values()
             for c in comments), default='')
        if not newest:
            sys.exit('No comments found on the resource; pass --date '
                     'to set the watermark explicitly.')
    state = read_state()
    previous = state.get('answered_up_to', '')
    if previous and newest < previous and not args.date:
        sys.exit(f'Refusing to move the watermark backwards '
                 f'({previous} -> {newest}); pass an explicit --date '
                 f'if that is really intended.')
    state['answered_up_to'] = newest
    write_state(state)
    print(json.dumps({'ok': True, 'answered_up_to': newest,
                      'previous': previous or None}))


def cmd_resolve(args):
    payload = {'data': {
        'id': args.comment_id,
        'type': 'resource_string_comments',
        'attributes': {'status': 'resolved'},
    }}
    status, data = request(
        'PATCH', f'{API}/resource_string_comments/{args.comment_id}', payload)
    if status == 403:
        print(json.dumps({'http': status, 'ok': False, 'hint':
              'Resolving needs project-maintainer rights on the shared '
              'nextcloud project; translator tokens get 403. Ask the issue '
              'reporter to resolve, or use the Transifex web UI.'}))
    else:
        print(json.dumps({'http': status, 'ok': status < 300}))
    sys.exit(0 if status < 300 else 1)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='command', required=True)

    p_list = sub.add_parser('list', help='list comment threads')
    p_list.add_argument('--open', action='store_true',
                        help='only threads needing attention')
    p_list.add_argument('--new', action='store_true',
                        help='only threads with activity newer than the '
                             'answered_up_to watermark in state.json')
    p_list.add_argument('--me', default='magdeflow',
                        help='username treated as "us" for needs_attention')
    p_list.set_defaults(func=cmd_list)

    p_mark = sub.add_parser(
        'mark-answered',
        help='record the newest comment date as the answered watermark')
    p_mark.add_argument('--date',
                        help='explicit ISO timestamp instead of the newest '
                             'comment date from the API')
    p_mark.set_defaults(func=cmd_mark_answered)

    p_find = sub.add_parser('find', help='search source strings by text/key')
    p_find.add_argument('text')
    p_find.set_defaults(func=cmd_find)

    p_reply = sub.add_parser('reply', help='post a reply on a thread')
    p_reply.add_argument('string_id')
    p_reply.add_argument('message')
    p_reply.add_argument('--language', help='override, e.g. l:de_DE')
    p_reply.add_argument('--issue', action='store_true',
                         help='open an issue instead of a plain comment')
    p_reply.set_defaults(func=cmd_reply)

    p_resolve = sub.add_parser('resolve', help='resolve an issue comment')
    p_resolve.add_argument('comment_id')
    p_resolve.set_defaults(func=cmd_resolve)

    args = parser.parse_args()
    args.func(args)


if __name__ == '__main__':
    main()
