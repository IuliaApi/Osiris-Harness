"""Interactive Windows regression: screenshot pixels must select the matching control."""
import io
import subprocess
import sys
import unittest


@unittest.skipUnless(sys.platform == 'win32', 'requires an interactive Windows desktop')
class NativeWindowTest(unittest.TestCase):
    def test_click_in_native_image_reaches_right_edit(self):
        import win32gui
        from PIL import Image
        if __package__:
            from .window_target import Target
        else:
            from window_target import Target

        # No user windows are enumerated. This process owns both edit controls;
        # a DPI-unaware target reproduces the coordinate mismatch on scaled monitors.
        app = '''import ctypes, win32con as c, win32gui as g
u = ctypes.windll.user32
u.SetThreadDpiAwarenessContext.argtypes = [ctypes.c_void_p]
assert u.SetThreadDpiAwarenessContext(-1)
root = g.CreateWindow('Static', 'Osiris native window test', c.WS_POPUP, 10, 10, 360, 160, 0, 0, 0, None)
children = [g.CreateWindow('Edit', '', c.WS_CHILD | c.WS_VISIBLE | c.WS_BORDER, x, 0, 180, 160, root, 0, 0, None) for x in (0, 180)]
g.ShowWindow(root, c.SW_SHOWNOACTIVATE)
g.UpdateWindow(root)
print(root, *children, flush=True)
g.PumpMessages()
'''
        with subprocess.Popen([sys.executable, '-c', app], stdout=subprocess.PIPE, text=True) as proc:
            try:
                hwnd, left, right = map(int, proc.stdout.readline().split())
                target = Target(hwnd)
                try:
                    with Image.open(io.BytesIO(target.capture())) as image:
                        self.assertEqual(image.size, (360, 160))
                    target.click(270, 80)
                    target.type_text('test')
                    target.key('Backspace')
                    self.assertEqual(win32gui.SendMessage(right, 0x000e, 0, 0), 3)  # WM_GETTEXTLENGTH
                    self.assertEqual(win32gui.SendMessage(left, 0x000e, 0, 0), 0)
                finally:
                    target.close()
            finally:
                proc.terminate()
