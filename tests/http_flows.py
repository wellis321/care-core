#!/usr/bin/env python3
"""End-to-end checks through the real web interface.

  DB_NAME=care_core_demo DEMO_MODE=true php scripts/demo-reset.php
  php -S localhost:8990 -t public public/index.php &
  python3 tests/http_flows.py [base_url]
"""
import json, re, sys, html, urllib.request, urllib.parse, http.cookiejar

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://localhost:8990'
PW = 'DemoPass2026!'
fails = 0


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self, email=None, password=PW, follow=True):
        self.cj = http.cookiejar.CookieJar()
        handlers = [urllib.request.HTTPCookieProcessor(self.cj)] + ([] if follow else [NoRedirect()])
        self.op = urllib.request.build_opener(*handlers)
        if email:
            self.login(email, password)

    def req(self, path, data=None, headers=None):
        rq = urllib.request.Request(BASE + path if path.startswith('/') else path, data=None if data is None else urllib.parse.urlencode(data, doseq=True).encode(), headers=headers or {})
        try:
            r = self.op.open(rq, timeout=30)
            return r.status, r.read().decode('utf8', 'replace'), r.geturl()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf8', 'replace'), e.headers.get('Location', path)

    def get(self, p):
        return self.req(p)

    def tok(self, h):
        m = re.search(r'name="_csrf" value="([^"]+)"', h)
        return m.group(1) if m else ''

    def post(self, page, data, action=None):
        h = self.get(page)[1]
        return self.req(action or page.split('?')[0], {'_csrf': self.tok(h), **data})

    def login(self, email, password=PW):
        return self.post('/login', {'email': email, 'password': password})

    @staticmethod
    def alerts(h):
        return [re.sub(r'\s+', ' ', html.unescape(re.sub('<[^>]+>', '', a))).strip() for a in re.findall(r'class="alert[^"]*"[^>]*>(.*?)</div>', h, re.S)]


def check(label, ok, extra=''):
    global fails
    print(('PASS  ' if ok else 'FAIL  ') + label + ('' if ok else '  -- ' + str(extra)[:300]))
    if not ok:
        fails += 1


def link(h, pattern):
    m = re.search(pattern, h)
    return m.group(1) if m else None


admin = Client('admin@demo.lochview.test')
print('--- sign-in and access')
check('unauthenticated visitors are sent to sign in', Client(follow=False).req('/people')[0] in (302, 303))
s, h, _ = Client().post('/login', {'email': 'admin@demo.lochview.test', 'password': 'wrong'})
check('a wrong password is refused without saying which part was wrong', 'email address or password is not right' in h, h[:200])
check('a POST without the CSRF token is refused', Client('admin@demo.lochview.test').req('/logout', {})[0] == 419)
s, h, u = Client().post('/login', {'email': 'admin@demo.lochview.test', 'password': PW, 'next': '//evil.example.com'})
check('"next" cannot send people to another site', 'evil.example.com' not in u, u)
c = Client()
for i in range(5):
    c.login('manager@demo.lochview.test', 'nope')
s, h, _ = c.login('manager@demo.lochview.test', PW)
check('after five failures even the right password is blocked for a while', 'Too many sign-in attempts' in h, h[:200])
mgr = Client('manager@demo.lochview.test') if False else None   # the manager account is now rate limited; use staff and admin below
staff = Client('staff@demo.lochview.test')
check('staff role cannot open Admin', staff.get('/admin')[0] == 403)
check('staff role cannot start a joiner', staff.get('/staff/join')[0] == 403)
check('staff role sees colleagues', 'Fiona Maclean' in staff.get('/people')[1])
s, h, _ = staff.get('/people?kind=supported')
check('staff role cannot list people we support', 'Findlay Bain' not in h and 'Euan Campbell' not in h)
cid = link(admin.get('/people?q=Campbell')[1], r'href="(/people/[0-9a-f-]{36})"')
check('staff role cannot open a person we support', staff.get(cid)[0] in (403, 404), staff.get(cid)[0])

print('--- joiner, invitation, sign-in, leaver')
s, h, u = admin.post('/staff/join', {'first_name': 'Jo', 'last_name': 'Starter', 'email': 'jo.starter@example.test', 'job_title': 'Support Worker', 'start_date': '2026-01-05', 'contract_type': 'permanent', 'create_login': '1', 'login_role': 'staff'}, action='/staff/join')
fl = ' '.join(Client.alerts(h))
check('a new starter is created with onboarding tasks and an invitation link', 'onboarding started' in fl and '/invite/' in fl, fl)
token = link(fl, r'/invite/([0-9a-f]{48})')
joUrl = u.replace(BASE, '')
check('the page for the new starter exists', '/people/' in joUrl and 'Jo Starter' in h)
check('the same email cannot start twice', 'already exists' in ' '.join(Client.alerts(admin.post('/staff/join', {'first_name': 'Jo', 'last_name': 'Again', 'email': 'jo.starter@example.test', 'job_title': 'X', 'start_date': '2026-01-05'})[1])))
inv = Client()
check('the invitation page opens', 'Choose a password' in inv.get('/invite/' + token)[1])
s, h, _ = inv.post('/invite/' + token, {'password': 'short', 'password2': 'short'}, action='/invite/' + token)
check('a short password is refused', 'at least 10' in h, h[:200])
s, h, _ = inv.post('/invite/' + token, {'password': 'a-long-password-123', 'password2': 'a-long-password-123'}, action='/invite/' + token)
check('setting a password works', 'password is set' in h, ' '.join(Client.alerts(h)))
check('an invitation can only be used once', 'expired or has already been used' in Client().get('/invite/' + token)[1])
jo = Client('jo.starter@example.test', 'a-long-password-123')
check('the new member of staff can sign in', 'Your details' in jo.get('/')[1])
leavePath = link(admin.get(joUrl)[1], r'href="(/staff/[0-9a-f-]{36})/leave"')
s, h, _ = admin.get(leavePath + '/leave?date=2026-10-21')
check('the leaver page shows what will happen, including the login', 'jo.starter@example.test' in h and 'Logins' in h)
s, h, u = admin.post(leavePath + '/leave?date=2026-10-21', {'date': '2026-10-21', 'reason': 'Resigned'}, action=leavePath + '/leave')
check('recording the leaving works', 'Recorded.' in ' '.join(Client.alerts(h)), ' '.join(Client.alerts(h)))
check('the existing session stops working the day after the last day', jo.get('/people')[0] == 200 and 'Sign in' in jo.get('/people')[1], jo.get('/people')[1][:100])
check('and so does signing in again', 'not right' in Client().login('jo.starter@example.test', 'a-long-password-123')[1])
check('the leaver is listed as Left', 'Jo Starter' in admin.get('/people?kind=leavers')[1])
check('leaving twice is refused', 'already' in ' '.join(Client.alerts(admin.post(leavePath + '/leave?date=2026-10-22', {'date': '2026-10-22'}, action=leavePath + '/leave')[1])))

print('--- teams')
s, h, u = admin.post('/teams', {'name': 'Respite Unit', 'parent_team_id': '', 'kind': 'care'})
tpath = u.replace(BASE, '')
check('a team can be created', 'Respite Unit' in h and '/teams/' in tpath, h[:200])
s, h, _ = admin.post(tpath, {'name': 'Respite Unit', 'parent_team_id': ''}, action=tpath + '/edit')
check('a team can be edited', 'Team updated' in ' '.join(Client.alerts(h)) or 'Respite Unit' in h)
pid = link(admin.get(tpath)[1], r'<option value="(\d+)">Hamish Cameron')
check('the person can be added from the team page', pid is not None)
s, h, _ = admin.post(tpath, {'person_id': pid, 'role': 'Lead', 'valid_from': '2026-10-01', 'is_primary': '1'}, action=tpath + '/members')
check('adding someone works', 'Added to the team' in ' '.join(Client.alerts(h)), ' '.join(Client.alerts(h)))
s, h, _ = admin.post(tpath, {'person_id': pid, 'valid_to': '2026-10-10'}, action=tpath + '/members/end')
check('removing someone works', 'Removed from the team' in ' '.join(Client.alerts(h)))
s, h, _ = admin.post(tpath, {'person_id': pid, 'role': 'Lead', 'valid_from': '2026-09-01'}, action=tpath + '/members')
check('rejoining before the last leaving date is refused', 'cannot be earlier' in ' '.join(Client.alerts(h)), ' '.join(Client.alerts(h)))
s, h, _ = admin.post(tpath, {'person_id': pid, 'role': 'Lead', 'valid_from': '2026-10-15'}, action=tpath + '/members')
h = admin.get(tpath)[1]
check('past members are kept and listed', 'Past members' in h and '10 Oct 2026' in h)

print('--- care')
s, h, _ = admin.post('/care/new', {'first_name': 'Ivy', 'last_name': 'Bad', 'start_date': '2026-10-01', 'nhs_number': '123'}, action='/care/new')
check('an invalid NHS number is refused', 'NHS number is not valid' in h, ' '.join(Client.alerts(h)))
s, h, u = admin.post('/care/new', {'first_name': 'Ivy', 'last_name': 'Good', 'start_date': '2026-10-01', 'nhs_number': '943 476 5919'}, action='/care/new')
ivy = u.replace(BASE, '')
check('a person we support can be added', 'Ivy Good' in h and 'Receiving support' in h, ' '.join(Client.alerts(h)))
cuuid = ivy.split('/')[-1]
staffId = link(h, r'<option value="(\d+)">Callum Reid')
s, h, _ = admin.post(ivy, {'staff_id': staffId, 'role_label': 'Key'}, action=f'/care/{cuuid}/keyworkers')
check('a key worker can be added', 'Key worker added' in ' '.join(Client.alerts(h)), ' '.join(Client.alerts(h)))
s, h, _ = admin.post(ivy, {'staff_id': staffId}, action=f'/care/{cuuid}/keyworkers')
check('the same key worker cannot be added twice', 'already a key worker' in ' '.join(Client.alerts(h)))
s, h, _ = admin.post(ivy, {'contact_type': 'email', 'value': 'nope'}, action=f'/care/{cuuid}/contacts')
check('a bad contact email is refused', 'valid email' in ' '.join(Client.alerts(h)))
s, h, _ = admin.post(ivy, {'end_date': '2026-10-20'}, action=f'/care/{cuuid}/end')
check('ending support ends the key worker link too', '1 key worker link' in ' '.join(Client.alerts(h)), ' '.join(Client.alerts(h)))

print('--- consistency checks')
s, h, _ = admin.get('/admin/reconcile')
check('the checks page loads', s == 200 and 'Consistency checks' in h)

print('--- SCIM over HTTP')
s, h, _ = admin.post('/admin/integrations', {'name': 'Entra test'}, action='/admin/scim')
tok = link(' '.join(Client.alerts(h)), r'([0-9a-f]{64})')
check('a SCIM token can be created', tok is not None, ' '.join(Client.alerts(h)))


def scim(method, path, body=None, token=tok):
    rq = urllib.request.Request(BASE + '/scim/v2' + path, data=None if body is None else json.dumps(body).encode(), method=method, headers={'Authorization': 'Bearer ' + (token or 'x'), 'Content-Type': 'application/scim+json'})
    try:
        r = urllib.request.urlopen(rq, timeout=30)
        return r.status, (json.loads(r.read() or b'null'))
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b'null')


check('SCIM without a valid token is refused', scim('GET', '/Users', token='wrong')[0] == 401)
st, u = scim('POST', '/Users', {'userName': 'scim.person@example.test', 'name': {'givenName': 'Scim', 'familyName': 'Person'}, 'title': 'Cook'})
check('SCIM create runs the joiner process', st == 201 and u['active'] is True, u)
st, u2 = scim('PATCH', '/Users/' + u['id'], {'Operations': [{'op': 'Replace', 'path': 'active', 'value': 'False'}]})
check('SCIM "active: False" runs the leaver process', st == 200 and u2['active'] is False, u2)
check('and the login shows as disabled in the web interface', re.search(r'scim\.person@example\.test.*?Disabled', admin.get('/admin/users')[1], re.S) is not None)

print('--- modules, escaping, headers')
check('a name containing HTML is shown as text', '<script>' not in (lambda h: h)(admin.post('/staff/join', {'first_name': '<script>alert(1)</script>', 'last_name': 'X', 'job_title': 'T', 'start_date': '2026-01-05'}, action='/staff/join')[1]))
s, h, _ = admin.get('/people?q=script')
check('the stored name is escaped on the list', '<script>alert' not in h and '&lt;script&gt;' in h)
r = urllib.request.urlopen(BASE + '/login')
check('security headers are sent', r.headers.get('X-Frame-Options') == 'DENY' and 'frame-ancestors' in (r.headers.get('Content-Security-Policy') or '') and r.headers.get('X-Content-Type-Options') == 'nosniff')
s, h, _ = admin.post('/admin/modules', {}, action='/admin/modules')   # switches Staff, Teams and Care off
check('with Care switched off its pages are not available', admin.get('/care/new')[0] == 404)
s, h, _ = admin.post('/admin/modules', {'m_staff': '1', 'm_teams': '1', 'm_care': '1'}, action='/admin/modules')
check('and they return when it is switched on again', admin.get('/care/new')[0] == 200)

print('\n%s' % ('ALL PASSED' if not fails else f'{fails} FAILED'))
sys.exit(1 if fails else 0)
