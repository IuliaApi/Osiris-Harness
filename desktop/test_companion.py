"""Exercise the companion HTTP boundary on an ephemeral IPv4 loopback port."""

import http.client
import json
import struct
import threading
import unittest
import zlib
from argparse import ArgumentTypeError
from concurrent.futures import ThreadPoolExecutor

if __package__:
    from .companion import DEFAULT_ORIGIN, HOST, MAX_BODY, PAIR_SECONDS, SESSION_SECONDS, Server, Sharing, parse_origin
else:
    from companion import DEFAULT_ORIGIN, HOST, MAX_BODY, PAIR_SECONDS, SESSION_SECONDS, Server, Sharing, parse_origin


def chunk(kind, payload):
    return struct.pack('>I', len(payload)) + kind + payload + struct.pack('>I', zlib.crc32(kind + payload))


PNG = (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', 2, 1, 8, 0, 0, 0, 0))
       + chunk(b'IDAT', zlib.compress(b'\0\x40\x80')) + chunk(b'IEND', b''))
THREAD = 'test-thread-1'


class TestWindow:
    title = 'Selected window'

    def __init__(self):
        self.calls = []
        self.closed = False
        self.fail = False

    def capture(self):
        if self.fail:
            raise RuntimeError('private target detail')
        return PNG

    def click(self, x, y):
        self.calls.append(('click', x, y))

    def type_text(self, text):
        self.calls.append(('text', text))

    def key(self, name):
        self.calls.append(('key', name))
        if self.fail:
            raise RuntimeError('private target detail')

    def close(self):
        self.closed = True


class ProtocolTest(unittest.TestCase):
    def setUp(self):
        self.now = 1000
        self.state = Sharing(clock=lambda: self.now)
        self.window = TestWindow()
        self.server = Server(DEFAULT_ORIGIN, self.state, port=0)
        self.worker = threading.Thread(target=self.server.serve_forever, args=(0.01,), daemon=True)
        self.worker.start()
        self.addCleanup(self.state.stop)
        self.addCleanup(self.server.server_close)
        self.addCleanup(self.worker.join)
        self.addCleanup(self.server.shutdown)

    def request(self, path, data=None, *, token=None, method='POST', headers=None, raw=None, extra_headers=()):
        conn = http.client.HTTPConnection(*self.server.server_address, timeout=3)
        body = raw if raw is not None else (json.dumps(data).encode() if data is not None else b'')
        base = {'Host': HOST, 'Origin': DEFAULT_ORIGIN, 'Content-Type': 'application/json', 'Content-Length': str(len(body))}
        if token is not None:
            base['Authorization'] = 'Bearer ' + token
        base.update(headers or {})
        try:
            conn.putrequest(method, path, skip_host=True, skip_accept_encoding=True)
            for name, value in [*base.items(), *extra_headers]:
                if value is not None:
                    conn.putheader(name, value)
            conn.endheaders(body)
            response = conn.getresponse()
            return response.status, dict(response.getheaders()), response.read()
        finally:
            conn.close()

    def enable(self):
        self.state.enable(self.window)
        return self.state.snapshot().code

    def pair(self, code=None):
        code = self.enable() if code is None else code
        status, headers, body = self.request('/pair', {'thread': THREAD, 'code': code})
        self.assertEqual(status, 200)
        self.assertEqual(headers['Cache-Control'], 'no-store')
        self.assertEqual(headers['Access-Control-Allow-Origin'], DEFAULT_ORIGIN)
        data = json.loads(body)
        self.assertEqual(set(data), {'token', 'title'})
        self.assertEqual(data['title'], self.window.title)
        self.assertRegex(data['token'], r'^[A-Za-z0-9_-]{43}$')
        self.assertNotIn(code, body.decode())
        return data['token']

    def frame(self, token):
        status, headers, body = self.request('/frame', {'thread': THREAD}, token=token)
        self.assertEqual(status, 200)
        self.assertEqual(headers['Content-Type'], 'image/png')
        self.assertEqual(body, PNG)
        self.assertEqual(headers['Access-Control-Expose-Headers'], 'X-Frame-Id')
        self.assertEqual(headers['Cache-Control'], 'no-store')
        return headers['X-Frame-Id']

    def action(self, token, frame, **fields):
        return self.request('/action', {'thread': THREAD, 'frame': frame, **fields}, token=token)[0]

    def test_origin_configuration(self):
        for origin in ('http://localhost:81', DEFAULT_ORIGIN, 'http://localhost:65535'):
            self.assertEqual(parse_origin(origin), origin)
        for origin in ('null', 'http://localhost', 'http://localhost:0', 'http://localhost:65536',
                       'https://localhost:8080', 'http://localhost.:8080', 'http://localhost:08080',
                       'http://127.0.0.2:8080', 'http://127.0.0.1:8080/', 'http://localhost:8080?a=b',
                       'http://localhost:8080#fragment', 'http://user@localhost:8080'):
            with self.subTest(origin=origin), self.assertRaises(ArgumentTypeError):
                parse_origin(origin)

    def test_pair_only_after_enable_once_and_bound_to_one_thread(self):
        self.assertEqual(self.request('/pair', {'thread': THREAD, 'code': ''})[0], 403)
        code = self.enable()
        self.assertRegex(code, r'^[A-Za-z0-9_-]{24}$')
        self.assertEqual(self.request('/pair', {'thread': THREAD, 'code': 'é'})[0], 403)
        token = self.pair(code)
        self.assertEqual(self.state.snapshot().thread, THREAD)
        self.assertEqual(self.state.snapshot().code, '')
        self.assertEqual(self.request('/pair', {'thread': THREAD, 'code': code})[0], 403)
        for path in ('/frame', '/action', '/disconnect'):
            with self.subTest(path=path):
                self.assertEqual(self.request(path, {'thread': THREAD})[0], 403)
                self.assertEqual(self.request(path, {'thread': THREAD}, token='invalid')[0], 403)
                self.assertEqual(self.request(path, {'thread': 'other'}, token=token)[0], 403)
        self.frame(token)
        self.assertEqual(self.request('/disconnect', {'thread': THREAD}, token=token)[0], 204)
        self.assertTrue(self.window.closed)
        self.assertEqual(self.request('/frame', {'thread': THREAD}, token=token)[0], 403)
        self.assertEqual(self.request('/pair', {'thread': THREAD, 'code': code})[0], 403)

    def test_exact_origin_host_and_preflight(self):
        code = self.enable()
        for name, value in [('Host', 'localhost:8765'), ('Host', None), ('Host', '127.0.0.1'),
                            ('Origin', 'http://localhost:8080'), ('Origin', None), ('Origin', 'null'),
                            ('Origin', 'http://127.0.0.1:8080.attacker.example')]:
            with self.subTest(name=name, value=value):
                for path, method in [('/frame', 'OPTIONS'), ('/pair', 'POST')]:
                    status, headers, _ = self.request(path, {'thread': THREAD, 'code': code},
                                                      headers={name: value}, method=method)
                    self.assertEqual(status, 403)
                    self.assertNotIn('Access-Control-Allow-Origin', headers)
        for name, value in [('Host', HOST), ('Origin', DEFAULT_ORIGIN)]:
            status, headers, _ = self.request('/pair', {'thread': THREAD, 'code': code}, extra_headers=[(name, value)])
            self.assertEqual(status, 400)
            self.assertNotIn('Access-Control-Allow-Origin', headers)
        allowed = {'Access-Control-Request-Method': 'POST', 'Access-Control-Request-Headers': 'Authorization, Content-Type'}
        status, headers, body = self.request('/action', method='OPTIONS', headers=allowed)
        self.assertEqual((status, body), (204, b''))
        self.assertEqual(headers['Access-Control-Allow-Methods'], 'POST')
        self.assertEqual(headers['Access-Control-Allow-Origin'], DEFAULT_ORIGIN)
        self.assertEqual(set(headers['Access-Control-Allow-Headers'].split(', ')), {'authorization', 'content-type'})
        self.assertNotIn('Access-Control-Allow-Credentials', headers)
        for override in [{'Access-Control-Request-Method': 'PUT'}, {'Access-Control-Request-Headers': 'x-selected-window'}]:
            self.assertEqual(self.request('/action', method='OPTIONS', headers=allowed | override)[0], 403)
        self.assertEqual(self.state.snapshot().code, code)

    def test_reject_malformed_requests_without_pairing(self):
        code = self.enable()
        valid = {'thread': THREAD, 'code': code}
        for path in ('/enable', '/windows', '/pair?code=hidden', '//pair', '/pair/'):
            status, _, body = self.request(path, valid)
            self.assertEqual(status, 404)
            self.assertNotIn('hidden', body.decode())
        for method in ('GET', 'PUT', 'DELETE'):
            self.assertEqual(self.request('/pair', valid, method=method)[0], 405)
        for raw in [b'[]', b'{', b'{}', b'\xff', b'{"thread":"x","thread":"y","code":"a"}', b'{"thread":NaN}',
                    b'{"code":"x","thread":"bad\\nthread"}']:
            self.assertEqual(self.request('/pair', raw=raw)[0], 400)
        self.assertEqual(self.request('/pair', valid | {'target': 'remote target selection'})[0], 400)
        self.assertEqual(self.request('/pair', valid, headers={'Content-Type': 'text/plain'})[0], 415)
        self.assertEqual(self.request('/pair', raw=b'x' * (MAX_BODY + 1))[0], 413)
        self.assertEqual(self.request('/pair', valid, headers={'Transfer-Encoding': 'chunked'})[0], 400)
        self.assertEqual(self.request('/pair', valid, headers={'Content-Encoding': 'gzip'})[0], 400)
        self.assertEqual(self.request('/pair', valid, extra_headers=[('Content-Length', '0')])[0], 400)
        self.assertEqual(self.request('/pair', valid, token='other')[0], 403)
        self.assertEqual(self.state.snapshot().code, code)

    def test_only_latest_frame_and_one_action_each(self):
        token = self.pair()
        first, second = self.frame(token), self.frame(token)
        self.assertNotEqual(first, second)
        self.assertEqual(self.action(token, first, kind='click', x=0, y=0), 409)
        self.assertEqual(self.action(token, second, kind='click', x=1, y=0), 204)
        self.assertEqual(self.action(token, second, kind='click', x=1, y=0), 409)
        self.assertEqual(self.action(token, self.frame(token), kind='text', text='Price: €5'), 204)
        self.assertEqual(self.action(token, self.frame(token), kind='key', key='Left'), 204)
        self.assertEqual(self.window.calls, [('click', 1, 0), ('text', 'Price: €5'), ('key', 'Left')])

    def test_reject_invalid_inputs_without_touching_target(self):
        token = self.pair()
        for fields in [{'kind': 'click', 'x': True, 'y': 0}, {'kind': 'click', 'x': 2, 'y': 0},
                       {'kind': 'click', 'x': 0, 'y': -1}, {'kind': 'click', 'x': 0, 'y': 0, 'hwnd': 7},
                       {'kind': 'key', 'key': 'Ctrl+V'}, {'kind': 'key', 'key': []}, {'kind': 'execute'},
                       {'kind': 'text', 'text': ''}, {'kind': 'text', 'text': 'x' * 1025},
                       {'kind': 'text', 'text': '\0'}, {'kind': 'text', 'text': '\ud800'}]:
            with self.subTest(fields=fields):
                frame = self.frame(token)
                self.assertEqual(self.action(token, frame, **fields), 400)
                self.assertEqual(self.action(token, frame, kind='key', key='Tab'), 409)
        self.assertEqual(self.window.calls, [])

    def test_concurrent_actions_do_not_duplicate_input(self):
        token = self.pair()
        frame = self.frame(token)
        barrier = threading.Barrier(2)

        def send():
            barrier.wait(timeout=3)
            return self.action(token, frame, kind='key', key='Enter')

        with ThreadPoolExecutor(max_workers=2) as pool:
            requests = [pool.submit(send) for _ in range(2)]
            self.assertCountEqual([request.result() for request in requests], [204, 409])
        self.assertEqual(self.window.calls, [('key', 'Enter')])

    def test_absolute_expiries_and_local_revocation(self):
        code = self.enable()
        self.now += PAIR_SECONDS
        self.assertEqual(self.request('/pair', {'thread': THREAD, 'code': code})[0], 403)
        self.assertTrue(self.window.closed)
        self.window = TestWindow()
        token = self.pair()
        self.now += SESSION_SECONDS - 1
        frame = self.frame(token)
        self.now += 1
        self.assertEqual(self.action(token, frame, kind='key', key='Tab'), 403)
        self.assertEqual(self.window.calls, [])
        self.assertTrue(self.window.closed)
        self.window = TestWindow()
        token = self.pair()
        self.state.stop()
        self.assertEqual(self.request('/frame', {'thread': THREAD}, token=token)[0], 403)
        self.assertFalse(self.state.snapshot().title)

    def test_target_error_revokes_and_does_not_disclose_details_or_retry(self):
        for capture in (True, False):
            with self.subTest(capture=capture):
                self.window = TestWindow()
                token = self.pair()
                frame = self.frame(token)
                self.window.fail = True
                if capture:
                    status, headers, body = self.request('/frame', {'thread': THREAD}, token=token)
                else:
                    status, headers, body = self.request('/action', {'thread': THREAD, 'frame': frame,
                                                                     'kind': 'key', 'key': 'Enter'}, token=token)
                self.assertEqual(status, 410)
                self.assertEqual(headers['Content-Type'], 'application/json')
                self.assertIn('error', json.loads(body))
                self.assertNotIn(b'private target detail', body)
                self.assertTrue(self.window.closed)
                self.assertEqual(self.window.calls, [] if capture else [('key', 'Enter')])
                self.assertEqual(self.request('/frame', {'thread': THREAD}, token=token)[0], 403)


if __name__ == '__main__':
    unittest.main()
