#!/usr/bin/env python3
"""Static frontend contract smoke check; does not claim database integration."""
from pathlib import Path

root = Path(__file__).resolve().parents[1]
html = (root / 'web' / 'index.html').read_text(encoding='utf-8')
php = (root / 'backend' / 'public' / 'index.php').read_text(encoding='utf-8')
required = [
    'id="login-screen"', 'id="dashboard-view"', 'id="sessions-view"',
    'id="chat-view"', 'id="chat-form"', 'id="chat-session-list"',
    'id="filter-q"', 'id="filter-sort"', 'id="next-page"',
    '/sessions/${encodeURIComponent(appState.current)}/chat',
    '/sessions/${encodeURIComponent(appState.current)}?include=project,messages,tasks',
    'bootstrap@5.3.3', 'sessionStorage',
]
for item in required:
    assert item in html, f'Missing UI feature: {item}'
assert 'id="settings-provider"' not in html
assert '<svg' not in html.lower() and '<img' not in html.lower()
for route in ["'/login'", "'/dashboard'", "'/workspace'", "'/chat'"]:
    assert route in php, f'PHP route absent: {route}'
assert "$provider = 'openai';" in php
print('PASS: simple UI structure, no provider selector/logo, API wiring and UI routes')
