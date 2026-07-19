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
  reply STRING_ID MESSAGE    Post a reply comment on a string's thread.
                             STRING_ID may be the full id or just the s:<hash>
                             suffix. The language relationship (required by
                             the API) is copied from the thread's existing
                             comments.
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


def token():
    value = os.environ.get('TRANSIFEX_TOKEN')
    if not value:
        sys.exit('TRANSIFEX_TOKEN is not set. Create a token under '
                 'transifex.com -> User settings -> API token.')
    return value


def request(method, url, payload=None):
    # curl instead of urllib: it picks up the egress proxy and its CA
    # bundle from the environment without extra configuration.
    cmd = ['curl', '-sS', '-X', method,
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
    out = []
    for sid, comments in threads.items():
        has_open_issue = any(
            c['type'] == 'issue' and c['status'] == 'open' for c in comments)
        needs_attention = has_open_issue or (
            comments and comments[-1]['author'] != f'u:{args.me}')
        if args.open and not needs_attention:
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


def cmd_reply(args):
    sid = full_string_id(args.string_id)
    threads = fetch_threads()
    thread = threads.get(sid)
    if not thread:
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
    status, data = None, None
    for language in candidates:
        payload = {'data': {
            'type': 'resource_string_comments',
            'attributes': {'message': args.message, 'type': 'comment'},
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
    p_list.add_argument('--me', default='magdeflow',
                        help='username treated as "us" for needs_attention')
    p_list.set_defaults(func=cmd_list)

    p_reply = sub.add_parser('reply', help='post a reply on a thread')
    p_reply.add_argument('string_id')
    p_reply.add_argument('message')
    p_reply.add_argument('--language', help='override, e.g. l:de_DE')
    p_reply.set_defaults(func=cmd_reply)

    p_resolve = sub.add_parser('resolve', help='resolve an issue comment')
    p_resolve.add_argument('comment_id')
    p_resolve.set_defaults(func=cmd_resolve)

    args = parser.parse_args()
    args.func(args)


if __name__ == '__main__':
    main()
