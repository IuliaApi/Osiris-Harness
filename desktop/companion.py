"""Manually share one local window with one Osiris chat thread."""

import argparse
import json
import re
import secrets
import socket
import struct
import sys
import threading
import time
from dataclasses import dataclass
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


ADDRESS = ('127.0.0.1', 8765)
HOST = '127.0.0.1:8765'
DEFAULT_ORIGIN = 'http://127.0.0.1:8080'
PAIR_SECONDS = 120
SESSION_SECONDS = 15 * 60
MAX_BODY = 16 * 1024
MAX_TEXT = 1024
KEYS = {'Enter', 'Tab', 'Backspace', 'Escape', 'Left', 'Right', 'Up', 'Down'}
PATHS = {'/pair', '/frame', '/action', '/disconnect'}


class Rejected(Exception):
    def __init__(self, status, message):
        super().__init__(message)
        self.status = status


def parse_origin(value):
    match = re.fullmatch(r'http://(?:127\.0\.0\.1|localhost):([1-9][0-9]{0,4})', value)
    if not match or int(match[1]) > 65535:
        raise argparse.ArgumentTypeError('Use http://127.0.0.1:PORT or http://localhost:PORT (1–65535).')
    return value


def fields(data, *names):
    if type(data) is not dict or data.keys() != set(names):
        raise Rejected(400, 'The request has missing or unexpected fields.')


def same_secret(value, secret):
    return (bool(secret) and isinstance(value, str) and value.isascii()
            and secrets.compare_digest(value, secret))


def safe_title(title):
    return ''.join(c if c.isprintable() else ' ' for c in title[:240]).strip() or '(untitled window)'


@dataclass(frozen=True)
class Snapshot:
    title: str
    thread: str
    code: str
    seconds: int
    reason: str


class Sharing:
    """Only the local GUI can enable a target; all operations are serialized with revocation."""

    def __init__(self, clock=time.monotonic):
        self.clock = clock
        self.lock = threading.Lock()
        self.target = None
        self._stop('Sharing is off.')

    def _stop(self, reason):
        target = self.target
        self.target = None
        self.code = self.token = self.thread = self.frame = self.title = ''
        self.width = self.height = self.deadline = 0
        self.reason = reason
        if target is not None:
            target.close()

    def _expire(self):
        if self.target is not None and self.clock() >= self.deadline:
            self._stop('Sharing expired. Select a window and enable it again.')

    def enable(self, target):
        with self.lock:
            self._stop('Sharing is off.')
            self.title = safe_title(target.title)
            self.code = secrets.token_urlsafe(18)
            self.deadline = self.clock() + PAIR_SECONDS
            self.target = target

    def stop(self):
        with self.lock:
            self._stop('Sharing stopped.')

    def snapshot(self):
        with self.lock:
            self._expire()
            return Snapshot(self.title, self.thread, self.code,
                            max(0, int(self.deadline - self.clock() + 0.999)), self.reason)

    def handle(self, path, data, bearer):
        with self.lock:
            self._expire()
            if type(data) is not dict or not isinstance(data.get('thread'), str) or not re.fullmatch(
                    r'[A-Za-z0-9_.-]{1,128}', data['thread']):
                raise Rejected(400, 'A valid chat thread identifier is required.')
            if path == '/pair':
                fields(data, 'thread', 'code')
                if bearer is not None or not same_secret(data['code'], self.code):
                    raise Rejected(403, 'Pairing code unavailable or incorrect. Check the desktop helper.')
                token = secrets.token_urlsafe(32)
                self.code, self.thread, self.token = '', data['thread'], token
                self.deadline = self.clock() + SESSION_SECONDS
                return 200, 'application/json', json.dumps({'token': token, 'title': self.title}).encode(), ''
            if not same_secret(bearer, self.token) or data['thread'] != self.thread:
                raise Rejected(403, 'This thread is not paired. Enable sharing in the desktop helper.')
            if path == '/disconnect':
                fields(data, 'thread')
                self._stop('The chat disconnected. Sharing is off.')
            elif path == '/frame':
                fields(data, 'thread')
                self.frame = ''
                try:
                    image = self.target.capture()
                    if image[:16] != b'\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR':
                        raise ValueError('Not a PNG')
                    self.width, self.height = struct.unpack_from('>II', image, 16)
                except Exception:
                    self._target_failed()
                self.frame = secrets.token_urlsafe(18)
                return 200, 'image/png', image, self.frame
            elif path == '/action':
                if not same_secret(data.get('frame'), self.frame):
                    raise Rejected(409, 'The preview is stale. Refresh it before sending input.')
                self.frame = ''  # Even a rejected input must not be replayed against this preview.
                kind = data.get('kind')
                if kind == 'click':
                    fields(data, 'thread', 'frame', 'kind', 'x', 'y')
                    x, y = data['x'], data['y']
                    if type(x) is not int or type(y) is not int or not (0 <= x < self.width and 0 <= y < self.height):
                        raise Rejected(400, 'The click must be inside the latest preview.')
                    operation, args = self.target.click, (x, y)
                elif kind == 'text':
                    fields(data, 'thread', 'frame', 'kind', 'text')
                    text = data['text']
                    if (not isinstance(text, str) or not 1 <= len(text) <= MAX_TEXT
                            or any((ord(c) < 32 and c not in '\n\t') or 127 <= ord(c) < 160
                                   or 0xd800 <= ord(c) <= 0xdfff for c in text)):
                        raise Rejected(400, 'Send 1–1024 characters of plain text.')
                    operation, args = self.target.type_text, (text,)
                elif kind == 'key':
                    fields(data, 'thread', 'frame', 'kind', 'key')
                    if not isinstance(data['key'], str) or data['key'] not in KEYS:
                        raise Rejected(400, 'Choose one of the supported keys.')
                    operation, args = self.target.key, (data['key'],)
                else:
                    raise Rejected(400, 'Choose a click, text or key action.')
                try:
                    operation(*args)
                except Exception:
                    self._target_failed()
            else:
                raise Rejected(404, 'Unknown desktop request.')
            return 204, '', b'', ''

    def _target_failed(self):
        self._stop('The selected window is unavailable or cannot accept this operation. Sharing stopped.')
        raise Rejected(410, self.reason) from None


def unique_fields(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('Duplicate field')
        result[key] = value
    return result


def reject_constant(_):
    raise ValueError('Invalid JSON constant')


class Handler(BaseHTTPRequestHandler):
    cors = False
    timeout = 4

    def log_message(self, format, *args):
        pass  # Never log request paths, headers, bodies or desktop data.

    def reply(self, status, media='', body=b'', frame='', headers=()):
        self.close_connection = True
        self.send_response_only(status)
        self.send_header('Connection', 'close')
        self.send_header('Cache-Control', 'no-store')
        self.send_header('X-Content-Type-Options', 'nosniff')
        self.send_header('Vary', 'Origin')
        if self.cors:
            self.send_header('Access-Control-Allow-Origin', self.server.origin)
            self.send_header('Access-Control-Expose-Headers', 'X-Frame-Id')
        if media:
            self.send_header('Content-Type', media)
        if frame:
            self.send_header('X-Frame-Id', frame)
        if status != 204:
            self.send_header('Content-Length', str(len(body)))
        for name, value in headers:
            self.send_header(name, value)
        self.end_headers()
        if body and self.command != 'HEAD':
            self.wfile.write(body)

    def error(self, status, message):
        self.reply(status, 'application/json', json.dumps({'error': message}).encode())

    def send_error(self, code, message=None, explain=None):
        # Also cover parser failures and unsupported methods without echoing user input.
        self.error(405 if code == 501 else code, 'Unsupported method.' if code == 501 else 'Invalid HTTP request.')

    def header(self, name):
        values = self.headers.get_all(name, [])
        if len(values) > 1:
            raise Rejected(400, 'Duplicate request header.')
        return values[0] if values else None

    def dispatch(self):
        try:
            if self.header('Host') != HOST or self.header('Origin') != self.server.origin:
                raise Rejected(403, 'The desktop helper accepts only its configured local Osiris origin.')
            self.cors = True
            if self.path not in PATHS or self.requestline.split()[1] != self.path:
                raise Rejected(404, 'Unknown desktop request.')
            if self.header('Transfer-Encoding') is not None or self.header('Content-Encoding') is not None:
                raise Rejected(400, 'Encoded request bodies are not supported.')
            if self.command == 'OPTIONS':
                requested = self.header('Access-Control-Request-Headers')
                names = {name.strip().lower() for name in requested.split(',')} if requested is not None else set()
                allowed = {'content-type'} if self.path == '/pair' else {'authorization', 'content-type'}
                if (self.header('Access-Control-Request-Method') != 'POST' or not names <= allowed
                        or self.header('Content-Length') not in (None, '0')):
                    raise Rejected(403, 'This browser request is not allowed.')
                headers = [('Access-Control-Allow-Methods', 'POST'),
                           ('Access-Control-Allow-Headers', ', '.join(sorted(allowed)))]
                if self.header('Access-Control-Request-Private-Network') == 'true':
                    headers.append(('Access-Control-Allow-Private-Network', 'true'))
                self.reply(204, headers=headers)
                return
            media = self.header('Content-Type') or ''
            if not re.fullmatch(r'application/json(?:;\s*charset=utf-8)?', media, re.I):
                raise Rejected(415, 'Use an application/json request.')
            length = self.header('Content-Length') or ''
            if not re.fullmatch(r'[0-9]{1,8}', length) or not 1 <= int(length) <= MAX_BODY:
                raise Rejected(413, 'The request is empty or too large.')
            auth = self.header('Authorization')
            if auth is not None:
                if not re.fullmatch(r'Bearer [A-Za-z0-9_-]{1,128}', auth):
                    raise Rejected(403, 'Invalid desktop session authorization.')
                auth = auth[7:]
            body = self.rfile.read(int(length))
            if len(body) != int(length):
                raise Rejected(400, 'The request body is incomplete.')
            try:
                data = json.loads(body.decode('utf-8'), object_pairs_hook=unique_fields, parse_constant=reject_constant)
            except (ValueError, RecursionError):
                raise Rejected(400, 'The request must be valid UTF-8 JSON with unique fields.') from None
            self.reply(*self.server.sharing.handle(self.path, data, auth))
        except Rejected as exc:
            self.error(exc.status, str(exc))
        except (OSError, TimeoutError):
            self.close_connection = True
        except Exception:
            self.server.sharing.stop()
            self.error(500, 'The desktop helper stopped sharing after an internal error.')

    do_POST = do_OPTIONS = dispatch


class Server(ThreadingHTTPServer):
    allow_reuse_address = False

    def __init__(self, origin, sharing, *, port=ADDRESS[1]):
        self.origin = parse_origin(origin)
        self.sharing = sharing
        super().__init__((ADDRESS[0], port), Handler)

    def server_bind(self):
        if hasattr(socket, 'SO_EXCLUSIVEADDRUSE'):
            self.socket.setsockopt(socket.SOL_SOCKET, socket.SO_EXCLUSIVEADDRUSE, 1)
        super().server_bind()

    def handle_error(self, request, client_address):
        pass  # Includes disconnects half-way through a response; no request logging.


def copy_pair_code(hwnd, code):
    import win32clipboard as clipboard
    import win32con

    clipboard.OpenClipboard(hwnd)
    try:
        clipboard.EmptyClipboard()
        # Do not put the pairing code in Windows clipboard history or cloud sync.
        for name in ('CanIncludeInClipboardHistory', 'CanUploadToCloudClipboard'):
            clipboard.SetClipboardData(clipboard.RegisterClipboardFormat(name), b'\0' * 4)
        clipboard.SetClipboardData(win32con.CF_UNICODETEXT, code)
    finally:
        clipboard.CloseClipboard()


def run_gui(root, origin, sharing):
    import tkinter as tk
    from tkinter import ttk
    from window_target import Target, enumerate_windows

    root.title('Osiris Desktop')
    root.minsize(510, 300)
    panel = ttk.Frame(root, padding=16)
    panel.pack(fill='both', expand=True)
    panel.columnconfigure(0, weight=1)
    ttk.Label(panel, text='Share one window', font=('Segoe UI', 15, 'bold')).grid(row=0, column=0, sticky='w')
    ttk.Label(panel, text=f'Only {origin} may pair. The paired chat can view and control the selected window.',
              wraplength=480).grid(row=1, column=0, columnspan=3, sticky='w', pady=(4, 16))
    ttk.Label(panel, text='Window to share').grid(row=2, column=0, sticky='w')
    picker = ttk.Combobox(panel, state='readonly', width=54)
    picker.grid(row=3, column=0, columnspan=2, sticky='ew')
    notice = tk.StringVar()
    status = tk.StringVar()
    code = tk.StringVar()
    choices = []

    def refresh():
        if sharing.snapshot().title:
            return
        choices.clear()
        picker.set('')
        try:
            choices.extend(enumerate_windows())
            notice.set('Select a window, then choose Enable sharing.' if choices else 'No supported windows found.')
        except Exception:
            notice.set('Could not list local windows. Try Refresh windows.')
        picker.configure(values=tuple(f'{safe_title(title)}  [0x{hwnd:X}]' for hwnd, title in choices))
        render()

    def enable():
        index = picker.current()
        if sharing.snapshot().title or not 0 <= index < len(choices):
            return
        hwnd, title = choices[index]
        try:
            target = Target(hwnd)
            if target.title != title:
                target.close()
                raise ValueError('Changed selection')
            sharing.enable(target)
            notice.set('Enter the code in the Desktop dialog of the intended chat thread.')
        except Exception:
            notice.set('The selected window is no longer available. Refresh the list and choose again.')
        render()

    def copy():
        current = sharing.snapshot().code
        if current:
            try:
                copy_pair_code(root.winfo_id(), current)
                notice.set('Pairing code copied. Paste it only into the intended chat’s Desktop dialog.')
            except Exception:
                notice.set('Clipboard unavailable. Enter the displayed pairing code manually.')
        render()

    def stop():
        sharing.stop()
        notice.set('')
        render()

    refresh_button = ttk.Button(panel, text='Refresh windows', command=refresh)
    refresh_button.grid(row=3, column=2, padx=(8, 0))
    buttons = ttk.Frame(panel)
    buttons.grid(row=4, column=0, columnspan=3, sticky='w', pady=(12, 8))
    enable_button = ttk.Button(buttons, text='Enable sharing', command=enable)
    copy_button = ttk.Button(buttons, text='Copy pairing code', command=copy)
    stop_button = ttk.Button(buttons, text='Stop sharing', command=stop)
    for button in (enable_button, copy_button, stop_button):
        button.pack(side='left', padx=(0, 8))
    ttk.Label(panel, textvariable=code, font=('Consolas', 12)).grid(row=5, column=0, columnspan=3, sticky='w')
    ttk.Label(panel, textvariable=status, wraplength=480).grid(row=6, column=0, columnspan=3, sticky='w', pady=(6, 2))
    ttk.Label(panel, textvariable=notice, wraplength=480).grid(row=7, column=0, columnspan=3, sticky='w')
    previous_title = previous_thread = ''

    def render():
        nonlocal previous_title, previous_thread
        view = sharing.snapshot()
        if previous_title and not view.title or view.thread and view.thread != previous_thread:
            notice.set('')
        previous_title, previous_thread = view.title, view.thread
        picker.configure(state='disabled' if view.title else 'readonly')
        refresh_button.state(['disabled' if view.title else '!disabled'])
        enable_button.state(['!disabled' if not view.title and picker.current() >= 0 else 'disabled'])
        copy_button.state(['!disabled' if view.code else 'disabled'])
        stop_button.state(['!disabled' if view.title else 'disabled'])
        code.set('Pairing code: ' + view.code if view.code else '')
        if view.thread:
            status.set(f'Sharing: {view.title}\nConnected: {origin} · Thread: {view.thread}\n'
                       f'Session expires in {view.seconds // 60}:{view.seconds % 60:02d}.')
        elif view.code:
            status.set(f'Waiting to pair: {view.title}\nCode expires in {view.seconds} seconds.')
        else:
            status.set(view.reason)

    def tick():
        render()
        root.after(250, tick)

    def close():
        sharing.stop()
        root.destroy()

    root.protocol('WM_DELETE_WINDOW', close)
    picker.bind('<<ComboboxSelected>>', lambda event: render())
    refresh()
    tick()
    root.mainloop()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--origin', type=parse_origin, default=DEFAULT_ORIGIN, help='Exact local Osiris HTTP origin')
    args = parser.parse_args()
    if sys.platform != 'win32':
        parser.exit(1, 'Osiris Desktop requires Windows.\n')
    import tkinter as tk
    from tkinter import messagebox
    root = tk.Tk()
    root.withdraw()
    sharing = Sharing()
    try:
        server = Server(args.origin, sharing)
    except OSError:
        messagebox.showerror('Osiris Desktop', f'Cannot listen on {HOST}. Close any other desktop helper.', parent=root)
        root.destroy()
        return 1
    with server:
        worker = threading.Thread(target=server.serve_forever, daemon=True)
        worker.start()
        try:
            root.deiconify()
            run_gui(root, args.origin, sharing)
        finally:
            sharing.stop()
            server.shutdown()
            worker.join()
    return 0


if __name__ == '__main__':
    sys.exit(main())
