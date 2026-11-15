#!/usr/bin/env python3
"""The one-click demo entry and its guard against robots. Needs the demo database:
   DB_NAME=care_core_demo DEMO_MODE=true php scripts/demo-reset.php  then  python3 tests/demo_entry.py"""
import http.cookiejar, os, re, subprocess, sys, time, urllib.parse, urllib.request, urllib.error

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PORT = 8992; BASE = f'http://localhost:{PORT}'
ENV = dict(os.environ, DB_NAME='care_core_demo', DEMO_MODE='true')
srv = subprocess.Popen(['php', '-S', f'localhost:{PORT}', '-t', 'public', 'public/index.php'], cwd=ROOT, env=ENV, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15'
passed = failed = 0
def check(c, what):
    global passed, failed
    if c: passed += 1; print('ok   ' + what)
    else: failed += 1; print('FAIL ' + what)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
class Client:
    def __init__(self, ua=BROWSER):
        self.ua = ua; self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)
    def req(self, method, path, data=None):
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        r = urllib.request.Request(BASE + path, data=body, method=method, headers={'User-Agent': self.ua, **({'Content-Type': 'application/x-www-form-urlencoded'} if body else {})})
        try: x = self.op.open(r); return x.status, x.read().decode('utf-8', 'replace'), x.headers
        except urllib.error.HTTPError as e: return e.code, e.read().decode('utf-8', 'replace'), e.headers
    def form(self, path='/login'):
        s, b, _ = self.req('GET', path)
        return b, re.search(r'name="_csrf" value="([a-f0-9]+)"', b).group(1), re.search(r'name="ft" value="([^"]+)"', b).group(1)

try:
    for _ in range(40):
        try: urllib.request.urlopen(BASE + '/login', timeout=2); break
        except Exception: time.sleep(0.25)
    c = Client(); page, csrf, ft = c.form()
    check('Explore as a manager' in page and 'Explore as a member of staff' in page, 'the sign-in page offers one-click entry in demo mode')
    s, b, _ = c.req('POST', '/demo/enter', {'_csrf': csrf, 'ft': ft, 'role': 'manager', 'website': ''}); check(s == 400 and 'did not work' in b, 'pressing at once (before two seconds) is refused')
    time.sleep(2.5)
    s, b, _ = c.req('POST', '/demo/enter', {'_csrf': csrf, 'ft': ft, 'role': 'manager', 'website': 'http://spam.example'}); check(s == 400 and 'did not work' in b, 'a filled-in hidden field is refused')
    s, b, _ = c.req('POST', '/demo/enter', {'_csrf': csrf, 'ft': 'forged.' + 'a' * 64, 'role': 'manager', 'website': ''}); check(s == 400 and 'did not work' in b, 'a forged token is refused')
    bot = Client('python-urllib/3.9'); bp, bc, bf = bot.form(); time.sleep(2.5)
    s, b, _ = bot.req('POST', '/demo/enter', {'_csrf': bc, 'ft': bf, 'role': 'manager', 'website': ''}); check(s == 400 and 'did not work' in b, 'a robot user agent is refused even with a good token')
    s, b, _ = c.req('POST', '/demo/enter', {'_csrf': csrf, 'ft': ft, 'role': 'manager', 'website': ''})
    check(s == 303, 'a person waiting a moment gets in as a manager')
    s, home, _ = c.req('GET', '/'); check('Three things to try' in home and 'See what a leaver does' in home and 'See it as a member of staff' in home, 'the manager sees the guide and a way to switch view')
    s, _, _ = c.req('POST', '/demo/enter', {'_csrf': re.findall(r'name="_csrf" value="([a-f0-9]+)"', home)[-1], 'ft': re.findall(r'name="ft" value="([^"]+)"', home)[-1], 'role': 'staff', 'website': ''})
    s, home, _ = c.req('GET', '/'); check('Open your profile' in home and 'See it as a manager' in home, 'switching view takes a signed-in person to the staff view at once')
    c2 = Client(); p2, cs2, ft2 = c2.form(); time.sleep(2.5)
    s, _, _ = c2.req('POST', '/demo/enter', {'_csrf': cs2, 'ft': ft2, 'role': 'staff', 'website': ''}); s, home, _ = c2.req('GET', '/')
    check('Open your profile' in home, 'a visitor can enter as a member of staff')
    s, _, _ = c2.req('POST', '/demo/enter', {'_csrf': cs2, 'ft': ft2, 'role': 'root', 'website': ''}); s, home, _ = c2.req('GET', '/'); check('Open your profile' in home, 'an unknown role cannot be chosen (the session is unchanged)')
    flood = Client(); fp, fc, ff = flood.form()
    for _ in range(25): flood.req('POST', '/demo/enter', {'_csrf': fc, 'ft': ff, 'role': 'manager', 'website': ''})
    s, b, _ = flood.req('POST', '/demo/enter', {'_csrf': fc, 'ft': ff, 'role': 'manager', 'website': ''}); check('a lot of attempts' in b, 'a flood of presses is slowed down')
finally:
    srv.terminate()
print(f'\n{passed} passed, {failed} failed'); sys.exit(1 if failed else 0)
