#!/usr/bin/env python3
"""Prove no-DB HTTP routing, media errors and request correlation using PHP's built-in server.

Requires PHP CLI + Python 3, but NOT PostgreSQL or Docker. This intentionally does NOT
claim the read/write/collection/composite database-backed endpoints work end-to-end.
"""
import json
from pathlib import Path
import socket
import subprocess
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parent.parent
with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0))
    port = sock.getsockname()[1]

process = subprocess.Popen(
    ['php', '-d', 'expose_php=0', '-S', f'127.0.0.1:{port}',
     '-t', 'backend/public', 'backend/public/index.php'],
    cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
)
ids = []

def http(path, method='GET', accept='application/json'):
    req = Request(f'http://127.0.0.1:{port}{path}', method=method,
                  headers={'Accept': accept})
    try:
        result = urlopen(req, timeout=2)
    except HTTPError as exc:
        result = exc
    with result:
        status, headers = result.status, dict(result.headers.items())
        payload = result.read().decode('utf-8')
    rid = next((value for key, value in headers.items() if key.lower() == 'x-request-id'), None)
    assert rid and len(rid) == 16, (path, rid)
    ids.append(rid)
    return status, headers, payload

try:
    for attempt in range(40):
        try:
            status, headers, body = http('/')
            break
        except (URLError, TimeoutError):
            if process.poll() is not None:
                raise RuntimeError('PHP dev server exited before becoming ready')
            time.sleep(0.1)
    else:
        raise RuntimeError('PHP dev server did not start')
    assert status == 200 and '<title>Osiris' in body and 'text/html' in headers['Content-Type']
    print('PASS public browser route returns HTML without PostgreSQL')

    status, headers, body = http('/api/runtime')
    runtime = json.loads(body)
    assert status == 200 and runtime['capabilities']['shell_execution'] is False
    assert {p['id'] for p in runtime['ai']['providers']} == {'openai', 'anthropic', 'ollama'}
    assert runtime['ai']['connected'] is False
    assert 'sk-test-secret-do-not-show' not in body
    print('PASS runtime provider inventory without PostgreSQL; no secret disclosed')

    status, headers, body = http('/sessions/ses_101/chat', method='GET')
    assert status == 405 and headers['Allow'] == 'POST'
    print('PASS chat route only allows POST (before database access)')

    status, headers, body = http('/not-a-route')
    item = json.loads(body)
    assert status == 404 and item['code'] == 'not_found' and item['requestId'] == ids[-1]
    print('PASS 404 structured JSON error and correlated request ID')

    status, headers, body = http('/sessions', accept='image/png')
    item = json.loads(body)
    assert status == 406 and item['code'] == 'not_acceptable' and item['requestId'] == ids[-1]
    print('PASS unsupported Accept gives 406 without leaking warnings or DB errors')

    status, headers, body = http('/sessions/ses_101', method='POST')
    item = json.loads(body)
    assert status == 405 and headers['Allow'] == 'GET, PATCH, DELETE' and item['requestId'] == ids[-1]
    print('PASS unsupported route method gives 405 with Allow and correlated error')
finally:
    process.terminate()
    try:
        _stdout, stderr = process.communicate(timeout=5)
    except subprocess.TimeoutExpired:
        process.kill()
        _stdout, stderr = process.communicate(timeout=5)

for rid in ids:
    assert rid in stderr and f'"requestId":"{rid}"' in stderr, (rid, stderr)
print('PASS PHP request logs include the matching IDs for all HTTP responses')
