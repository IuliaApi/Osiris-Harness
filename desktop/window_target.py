"""Window-scoped capture and input for the local Windows companion."""

import atexit
import ctypes as C
from ctypes import wintypes as W
from contextlib import contextmanager
from dataclasses import astuple, dataclass
import io
import json
import os
import secrets
import subprocess
import sys
import time


_MAX_SIDE = 8192
_MAX_PIXELS = 3840 * 2160
_KEYS = {"Enter": 13, "Tab": 9, "Backspace": 8, "Escape": 27,
         "Left": 37, "Up": 38, "Right": 39, "Down": 40}
_SHELL_CLASSES = {"Progman", "WorkerW", "Shell_TrayWnd", "Shell_SecondaryTrayWnd"}


class _GuiThreadInfo(C.Structure):
    _fields_ = [("size", W.DWORD), ("flags", W.DWORD), ("active", W.HWND),
                ("focus", W.HWND), ("capture", W.HWND), ("menu", W.HWND),
                ("move_size", W.HWND), ("caret", W.HWND), ("rect", W.RECT)]


if sys.platform == "win32":
    import pywintypes
    import win32gui as gui
    import win32process

    def _api(dll, name, result, *args):
        fn = getattr(dll, name)
        fn.restype, fn.argtypes = result, args
        return fn

    _u = C.WinDLL("user32", use_last_error=True)
    _g = C.WinDLL("gdi32", use_last_error=True)
    _set_dpi = _api(_u, "SetThreadDpiAwarenessContext", W.HANDLE, W.HANDLE)
    _window_context = _api(_u, "GetWindowDpiAwarenessContext", W.HANDLE, W.HWND)
    _dpi = _api(_u, "GetDpiForWindow", W.UINT, W.HWND)
    _logical = _api(_u, "PhysicalToLogicalPointForPerMonitorDPI", W.BOOL, W.HWND, C.POINTER(W.POINT))
    _ancestor = _api(_u, "GetAncestor", W.HWND, W.HWND, W.UINT)
    _child_at = _api(_u, "ChildWindowFromPointEx", W.HWND, W.HWND, W.POINT, W.UINT)
    _info = _api(_u, "GetGUIThreadInfo", W.BOOL, W.DWORD, C.POINTER(_GuiThreadInfo))
    _set_prop = _api(_u, "SetPropW", W.BOOL, W.HWND, W.LPCWSTR, W.HANDLE)
    _get_prop = _api(_u, "GetPropW", W.HANDLE, W.HWND, W.LPCWSTR)
    _remove_prop = _api(_u, "RemovePropW", W.HANDLE, W.HWND, W.LPCWSTR)
    _cloaked = _api(C.WinDLL("dwmapi"), "DwmGetWindowAttribute", W.LONG, W.HWND, W.DWORD, W.LPVOID, W.DWORD)
    _layout = _api(_u, "GetKeyboardLayout", W.HANDLE, W.DWORD)
    _scan = _api(_u, "MapVirtualKeyExW", W.UINT, W.UINT, W.UINT, W.HANDLE)
    _send = _api(_u, "SendMessageTimeoutW", C.c_ssize_t, W.HWND, W.UINT, C.c_size_t,
                 C.c_ssize_t, W.UINT, W.UINT, C.POINTER(C.c_size_t))
    _print = _api(_u, "PrintWindow", W.BOOL, W.HWND, W.HDC, W.UINT)
    _dc = _api(_g, "CreateCompatibleDC", W.HDC, W.HDC)
    _dib = _api(_g, "CreateDIBSection", W.HANDLE, W.HDC, W.LPVOID, W.UINT,
                C.POINTER(W.LPVOID), W.HANDLE, W.DWORD)
    _select = _api(_g, "SelectObject", W.HANDLE, W.HDC, W.HANDLE)
    _delete = _api(_g, "DeleteObject", W.BOOL, W.HANDLE)
    _delete_dc = _api(_g, "DeleteDC", W.BOOL, W.HDC)
    _flush = _api(_g, "GdiFlush", W.BOOL)


def _require_windows():
    if sys.platform != "win32":
        raise RuntimeError("Desktop sharing requires Windows 10 or later.")


@contextmanager
def _coordinates(context=-4):  # DPI_AWARENESS_CONTEXT_PER_MONITOR_AWARE_V2
    _require_windows()
    previous = _set_dpi(context)
    if not previous:
        raise RuntimeError("The window's DPI coordinate system is unavailable.")
    try:
        yield
    except pywintypes.error as exc:
        raise RuntimeError("The window is no longer accessible.") from exc
    finally:
        _set_dpi(previous)


def _signature(hwnd):
    return (*win32process.GetWindowThreadProcessId(hwnd), gui.GetClassName(hwnd))


@dataclass(frozen=True)
class _Binding:
    hwnd: int
    tid: int
    pid: int
    cls: str
    prop: str

    @classmethod
    def mark(cls, hwnd):
        binding = cls(hwnd, *_signature(hwnd), "Osiris." + secrets.token_hex(16))
        if not _set_prop(hwnd, binding.prop, 1):
            raise RuntimeError("Windows did not grant access to this window.")
        try:
            binding.check()
        except Exception:
            binding.release()
            raise
        return binding

    def check(self):
        # Class, thread and process alone do not distinguish a recreated HWND.
        if _get_prop(self.hwnd, self.prop) != 1 or _signature(self.hwnd) != (self.tid, self.pid, self.cls):
            raise RuntimeError("The selected window was closed or replaced.")

    def release(self):
        if _get_prop(self.hwnd, self.prop) == 1:
            _remove_prop(self.hwnd, self.prop)


def _eligible(hwnd, helper_pid):
    if not gui.IsWindow(hwnd) or _ancestor(hwnd, 2) != hwnd or not gui.IsWindowVisible(hwnd):
        raise RuntimeError("Select a visible application window.")
    _, pid, cls = _signature(hwnd)
    if pid == helper_pid or cls in _SHELL_CLASSES:
        raise RuntimeError("Select an application other than the companion or desktop shell.")
    cloak = W.DWORD()
    if _cloaked(hwnd, 14, C.byref(cloak), C.sizeof(cloak)) != 0 or cloak.value or gui.IsIconic(hwnd):
        raise RuntimeError("Restore the window on the current desktop before sharing it.")
    if not gui.IsWindowEnabled(hwnd):
        raise RuntimeError("The selected window is disabled or has a modal dialog.")
    if gui.GetWindowLong(hwnd, -20) & 0x00400000:  # WS_EX_LAYOUTRTL
        raise RuntimeError("Mirrored window coordinates are not supported.")


_offered: dict[int, _Binding] = {}


@atexit.register
def _clear_picker():
    for binding in _offered.values():
        binding.release()
    _offered.clear()


def enumerate_windows() -> list[tuple[int, str]]:
    result = []
    with _coordinates():
        _clear_picker()

        def visit(hwnd, _):
            binding = None
            try:
                _eligible(hwnd, os.getpid())
                binding = _Binding.mark(hwnd)
                title = gui.GetWindowText(hwnd)
                if title.strip():
                    binding.check()
                    _offered[hwnd] = binding
                    binding = None
                    result.append((hwnd, title))
            except (RuntimeError, pywintypes.error):
                pass  # Includes protected/higher-integrity windows.
            finally:
                if binding:
                    binding.release()
            return True

        gui.EnumWindows(visit, None)
    return result


def _geometry(binding, helper_pid):
    binding.check()
    _eligible(binding.hwnd, helper_pid)
    _, _, physical_w, physical_h = gui.GetClientRect(binding.hwnd)
    # PrintWindow paints in the target's native units. A DPI-unaware app at
    # 225% paints 360×160 pixels even when its on-screen client is 810×360.
    with _coordinates(_window_context(binding.hwnd)):
        _, _, w, h = gui.GetClientRect(binding.hwnd)
    dpi = _dpi(binding.hwnd)
    if not dpi or min(w, h, physical_w, physical_h) < 1 or max(w, h) > _MAX_SIDE or w * h > _MAX_PIXELS:
        raise RuntimeError("Resize the window to a supported capture size (up to 4K pixels).")
    return w, h, physical_w, physical_h, dpi


def _render(binding, helper_pid, geometry):
    """Run only in the killable renderer: PrintWindow has no timeout API."""
    import struct
    from PIL import Image

    with _coordinates():
        if _geometry(binding, helper_pid) != geometry:
            raise RuntimeError("The selected window changed size.")
        w, h = geometry[:2]
        # Top-down 32-bpp BI_RGB DIB. No desktop/window DC or screen readback.
        header = struct.pack("<IiiHHIIiiII", 40, w, -h, 1, 32, 0, 0, 0, 0, 0, 0)
        dc = _dc(None)
        if not dc:
            raise RuntimeError("Cannot allocate a capture surface.")
        bits = W.LPVOID()
        bitmap = original = None
        try:
            bitmap = _dib(dc, header, 0, C.byref(bits), None, 0)
            if not bitmap or not bits.value:
                raise RuntimeError("Cannot allocate a capture bitmap.")
            original = _select(dc, bitmap)
            if not original or original == C.c_void_p(-1).value:
                raise RuntimeError("Cannot select the capture bitmap.")
            size = 4 * w * h
            C.memset(bits, 0xA5, size)
            binding.check()
            if not _print(binding.hwnd, dc, 3) or not _flush():  # PW_CLIENTONLY | PW_RENDERFULLCONTENT
                raise RuntimeError("This window does not support private capture.")
            if _geometry(binding, helper_pid) != geometry:
                raise RuntimeError("The window changed during capture.")
            image = Image.frombytes("RGB", (w, h), C.string_at(bits, size), "raw", "BGRX")
        finally:
            if original and original != C.c_void_p(-1).value:
                _select(dc, original)
            if bitmap:
                _delete(bitmap)
            _delete_dc(dc)
        if all(lo == hi == 165 for lo, hi in image.getextrema()) or not image.getbbox():
            raise RuntimeError("The application returned no visible content.")
        out = io.BytesIO()
        image.save(out, "PNG", compress_level=1)
        return out.getvalue()


class Target:
    def __init__(self, hwnd: int):
        if type(hwnd) is not int or hwnd <= 0:
            raise ValueError("Invalid window handle.")
        with _coordinates():
            _eligible(hwnd, os.getpid())
            offered = _offered.get(hwnd)
            if offered:
                offered.check()
            self.title = gui.GetWindowText(hwnd)
            if not self.title.strip():
                raise RuntimeError("Select a titled application window.")
            self._root = _Binding.mark(hwnd)
            self._selected = None
            self._frame = None
            try:
                if offered:
                    offered.check()
                _geometry(self._root, os.getpid())
            except Exception:
                self.close()
                raise

    def close(self):
        self._frame = None
        if self._selected:
            self._selected.release()
            self._selected = None
        if self._root:
            self._root.release()
            self._root = None

    def _geometry(self):
        if not self._root:
            raise RuntimeError("Window sharing has stopped.")
        return _geometry(self._root, os.getpid())

    def capture(self) -> bytes:
        with _coordinates():
            self._frame = None
            geometry = self._geometry()
            request = json.dumps([astuple(self._root), os.getpid(), geometry]).encode("ascii")
            try:
                result = subprocess.run([sys.executable, "-I", os.path.abspath(__file__), "--render"],
                                        input=request, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                        creationflags=subprocess.CREATE_NO_WINDOW, timeout=4, check=False)
            except (subprocess.TimeoutExpired, OSError) as exc:
                raise RuntimeError("The selected window did not respond to capture.") from exc
            if result.returncode or not result.stdout.startswith(b"\x89PNG\r\n\x1a\n"):
                raise RuntimeError("This window could not be captured privately.")
            if self._geometry() != geometry:
                raise RuntimeError("The window was resized during capture.")
            self._frame = geometry
            return result.stdout

    def _owned(self, hwnd):
        if (not hwnd or _ancestor(hwnd, 2) != self._root.hwnd or not gui.IsWindowVisible(hwnd)
                or not gui.IsWindowEnabled(hwnd) or _signature(hwnd)[1] != self._root.pid):
            raise RuntimeError("The destination control is unavailable or outside the selected window.")

    def _message(self, dest, msg, wp, lp, deadline):
        remaining = int(1000 * (deadline - time.monotonic()))
        if remaining < 1:
            raise RuntimeError("Input was interrupted; part of it may have been delivered.")
        self._root.check()
        dest.check()
        self._owned(dest.hwnd)
        result = C.c_size_t()
        # SMTO_BLOCK | SMTO_ABORTIFHUNG | SMTO_ERRORONEXIT; never post globally.
        if not _send(dest.hwnd, msg, wp, lp, 0x23, min(remaining, 450), C.byref(result)):
            raise RuntimeError("The control did not respond; input may be incomplete.")

    def click(self, x: int, y: int):
        with _coordinates():
            geometry = self._geometry()
            if geometry != self._frame:
                raise RuntimeError("Capture the current window before clicking.")
            w, h, physical_w, physical_h, _ = geometry
            if type(x) is not int or type(y) is not int or not (0 <= x < w and 0 <= y < h):
                raise ValueError("The click is outside the captured client area.")
            hwnd = self._root.hwnd
            screen = gui.ClientToScreen(hwnd, (x * physical_w // w, y * physical_h // h))
            while True:
                self._owned(hwnd)
                if gui.GetWindowLong(hwnd, -20) & 0x00400000:
                    raise RuntimeError("Mirrored control coordinates are not supported.")
                point = W.POINT(*gui.ScreenToClient(hwnd, screen))
                child = _child_at(hwnd, point, 0x05)  # Skip invisible and transparent children.
                if not child:
                    raise RuntimeError("The control moved outside the click position.")
                if child == hwnd:
                    break
                hwnd = child
            # Messages carry coordinates in the destination's DPI space, not the renderer's.
            point = W.POINT(*screen)
            if not _logical(hwnd, C.byref(point)):
                raise RuntimeError("Cannot map the click into this control.")
            with _coordinates(_window_context(hwnd)):
                cx, cy = gui.ScreenToClient(hwnd, (point.x, point.y))
                _, _, cw, ch = gui.GetClientRect(hwnd)
            if not (0 <= cx < min(32768, cw) and 0 <= cy < min(32768, ch)):
                raise RuntimeError("The control changed during the click.")
            if self._selected:
                self._selected.release()
                self._selected = None
            if hwnd != self._root.hwnd:
                self._selected = _Binding.mark(hwnd)
            dest = self._selected or self._root
            lp = (cy << 16) | cx
            deadline = time.monotonic() + 1
            self._message(dest, 0x0201, 1, lp, deadline)  # WM_LBUTTONDOWN, MK_LBUTTON
            self._message(dest, 0x0202, 0, lp, deadline)  # WM_LBUTTONUP

    @contextmanager
    def _keyboard_destination(self):
        preferred = self._selected or self._root
        preferred.check()
        self._owned(preferred.hwnd)
        info = _GuiThreadInfo(size=C.sizeof(_GuiThreadInfo))
        if not _info(preferred.tid, C.byref(info)):
            raise RuntimeError("Cannot determine this window's focused control.")
        focus = info.focus
        if focus and _ancestor(focus, 2) == self._root.hwnd and focus != preferred.hwnd:
            self._owned(focus)
            dest = self._root if focus == self._root.hwnd else _Binding.mark(focus)
            try:
                yield dest
            finally:
                if dest is not self._root:
                    dest.release()
        else:
            yield preferred

    def type_text(self, text: str):
        with _coordinates():
            self._geometry()
            if not isinstance(text, str) or not 1 <= len(text) <= 4096:
                raise ValueError("Text must contain between 1 and 4096 characters.")
            text = text.replace("\r\n", "\r").replace("\n", "\r")
            if any(ord(c) < 32 and c not in "\r\t" or c == "\x7f" for c in text):
                raise ValueError("Unsupported control character in text.")
            data = text.encode("utf-16-le")
            with self._keyboard_destination() as dest:
                deadline = time.monotonic() + 3
                for i in range(0, len(data), 2):
                    self._message(dest, 0x0102, data[i] | data[i + 1] << 8, 1, deadline)  # WM_CHAR

    def key(self, key: str):
        with _coordinates():
            self._geometry()
            if key not in _KEYS:
                raise ValueError("Unsupported key.")
            vk = _KEYS[key]
            with self._keyboard_destination() as dest:
                code = _scan(vk, 0, _layout(dest.tid)) & 255
                lp = 1 | (code << 16) | ((key in {"Left", "Right", "Up", "Down"}) << 24)
                deadline = time.monotonic() + 1
                self._message(dest, 0x0100, vk, lp, deadline)  # WM_KEYDOWN
                if vk in (8, 9, 13, 27):
                    # TranslateMessage is bypassed by direct delivery.
                    self._message(dest, 0x0102, vk, lp, deadline)
                self._message(dest, 0x0101, vk, lp | 0xC0000000, deadline)  # WM_KEYUP


if __name__ == "__main__":
    if sys.argv[1:] != ["--render"]:
        sys.exit(2)
    try:
        fields, helper_pid, geometry = json.loads(sys.stdin.buffer.read(4096))
        sys.stdout.buffer.write(_render(_Binding(*fields), helper_pid, tuple(geometry)))
    except Exception:
        sys.exit(1)
