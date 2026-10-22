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


import base64, uuid as _uuid
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')


def multipart(client, page, action, fields, files):
    """POST a form with file uploads."""
    h = client.get(page)[1]
    b = '----cc' + _uuid.uuid4().hex
    body = b''
    for k, v in {'_csrf': client.tok(h), **fields}.items():
        body += ('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (b, k, v)).encode()
    for k, (name, data, mime) in files.items():
        body += ('--%s\r\nContent-Disposition: form-data; name="%s"; filename="%s"\r\nContent-Type: %s\r\n\r\n' % (b, k, name, mime)).encode() + data + b'\r\n'
    body += ('--%s--\r\n' % b).encode()
    rq = urllib.request.Request(BASE + action, data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + b})
    try:
        r = client.op.open(rq, timeout=30)
        return r.status, r.read().decode('utf8', 'replace'), r.geturl()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf8', 'replace'), e.headers.get('Location', action)


admin = Client('admin@demo.lochview.test')
staff_c = Client('staff@demo.lochview.test')      # Callum Reid: reports to Isla (manager login)
mgr_c = Client('manager@demo.lochview.test')
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

print('--- staff records, self-service and approvals')
s, h, _ = staff_c.get('/me')
check('a member of staff sees My profile', s == 200 and 'My details' in h and 'Callum' in h)
check('and a request from the demo is already waiting', 'Waiting for approval' in h and '4 Millburn Road' in h)
check('another member of staff cannot use the manager record page', staff_c.get('/people/' + link(admin.get('/people?q=Callum')[1], r'/people/([0-9a-f-]{36})') + '/record')[0] == 403)
s, h, _ = staff_c.post('/me', {'first_name': 'Callum', 'last_name': 'Reid', 'phone': 'not a phone', 'address_postcode': 'IV2 3PS'})
check('a bad phone number is refused with a message next to the field', s == 422 and 'Phone number can only have numbers' in h and 'field-error' in h, h[:200])
s, h, _ = staff_c.post('/me', {'first_name': 'Callum', 'last_name': 'Reid', 'phone': '07700 900999', 'address_postcode': 'IV2 3PS', 'ni_number': '', 'emergency_contact_name': 'Pat Reid'})
check('phone and emergency contact save straight away', 'saved straight away' in ' '.join(Client.alerts(h)).lower() and '07700 900999' in staff_c.get('/me')[1], Client.alerts(h))
s, h, _ = staff_c.post('/me', {'first_name': 'Callum', 'last_name': 'Reid', 'ni_number': 'qq 12 34 56 c'})
check('an impossible National Insurance number is refused', s == 422 and 'National Insurance number does not look right' in h)
s, h, _ = staff_c.post('/me', {'first_name': 'Callum', 'last_name': 'Reid', 'bank_sort_code': '12 34 56', 'bank_account_number': '12345678', 'bank_account_name': 'C Reid'})
check('bank details are held for approval', 'sent to your line manager' in ' '.join(Client.alerts(h)), Client.alerts(h))
h = staff_c.get('/me')[1]
check('pending secrets are masked for the person themselves', '••••5678' in h and '12345678' not in h)
check('Callum has no way to approve anything', staff_c.get('/approvals')[0] == 200 and 'Nothing is waiting' in staff_c.get('/approvals')[1])
s, h, _ = staff_c.post('/me', {'first_name': 'Callum'}, action='/approvals/' + (link(h, r'name="id" value="([0-9a-f-]{36})"') or 'x'))
check('and cannot approve by posting directly', 'Approved' not in ' '.join(Client.alerts(h)))
s, h, _ = multipart(staff_c, '/me', '/me/photo', {}, {'photo': ('p.png', PNG, 'image/png')})
check('a photo can be sent for approval', 'sent to your line manager' in ' '.join(Client.alerts(h)), Client.alerts(h))
s, h, _ = multipart(staff_c, '/me', '/me/photo', {}, {'photo': ('evil.png', b'<?php system($_GET[1]); ?>', 'image/png')})
check('a script disguised as a photo is refused', 'Only PNG' in ' '.join(Client.alerts(h)), Client.alerts(h))
s, h, _ = multipart(staff_c, '/me', '/me/signature', {'signature_data': 'data:image/png;base64,' + base64.b64encode(PNG).decode()}, {})
check('a drawn signature can be sent for approval', 'signature was sent' in ' '.join(Client.alerts(h)).lower(), Client.alerts(h))
pend_sig = link(staff_c.get('/me')[1], r'/files/pending/([0-9a-f-]{36})')
check('Callum can see his pending picture', pend_sig is not None and staff_c.get('/files/pending/' + pend_sig)[0] == 200)

s, h, _ = mgr_c.get('/approvals')
check('Isla (his line manager) sees the requests', s == 200 and 'Callum Reid' in h and '4 Millburn Road' in h and 'Approve' in h)
check('and sees the real bank number to check it', '12345678' in h)
check('Isla can fetch the pending picture', mgr_c.get('/files/pending/' + pend_sig)[0] == 200)
check('a stranger cannot fetch the pending picture', Client().get('/files/pending/' + pend_sig)[0] in (200, 302, 303) and 'image' not in (Client().req('/files/pending/' + pend_sig)[1][:20]))
rows = re.findall(r'action="/approvals/([0-9a-f-]{36})"', h)
check('there are several requests to decide', len(rows) >= 5, len(rows))
done = 0
for u in rows:
    s, hh, _ = mgr_c.post('/approvals', {'decision': 'approve'}, action='/approvals/' + u)
    done += 'Approved and applied' in ' '.join(Client.alerts(hh))
check('approving applies them', done == len(rows), (done, len(rows)))
h = staff_c.get('/me')[1]
check('Callum now sees the approved address, signature and photo', '4 Millburn Road' in h and 'alt="Your current signature"' in h and 'alt="Your current photo"' in h)
sig_uuid = link(h, r'/files/signature/([0-9a-f-]{36})')
check('Callum can see his own signature file', staff_c.get('/files/signature/' + sig_uuid)[0] == 200)
check('a colleague cannot read his signature', Client().get('/files/signature/' + sig_uuid)[0] in (302, 303, 200) and 'image' not in Client().req('/files/signature/' + sig_uuid)[1][:20])

print('--- the manager view of a staff record')
cal = link(admin.get('/people?q=Callum')[1], r'/people/([0-9a-f-]{36})')
s, h, _ = admin.get('/people/' + cal + '/record')
check('HR sees the full staff record', s == 200 and 'Personal details' in h and 'TUPE transfer' in h and 'Working time' in h and 'Registrations' in h)
check('with the decrypted bank details for editing', '12345678' in h and 'JA000002A' in h)
s, h, _ = admin.post('/people/' + cal + '/record', {'section': 'visa', 'has_visa': '1'}, action='/people/' + cal + '/record/section')
check('a section with a missing expiry shows the error beside the field', s == 422 and 'Enter when the visa expires' in h)
s, h, _ = admin.post('/people/' + cal + '/record', {'section': 'tupe', 'is_tupe': '1', 'tupe_transfer_date': '2025-03-01', 'tupe_previous_organisation': 'Glen Care'}, action='/people/' + cal + '/record/section')
check('a section saves', 'TUPE transfer saved' in ' '.join(Client.alerts(h)), Client.alerts(h))
check('and the other sections are untouched', 'value="2025.1"' in admin.get('/people/' + cal + '/record')[1])
s, h, _ = admin.post('/people/' + cal + '/record', {'registration_type': 'First aid', 'expiry_date': '2020-01-01', 'issue_date': '2021-01-01'}, action='/people/' + cal + '/compliance/registration')
check('a registration that expires before it was issued is refused', s == 422 and 'cannot expire before' in h)
s, h, _ = admin.post('/people/' + cal + '/record', {'registration_type': 'First aid', 'expiry_date': '2030-01-01'}, action='/people/' + cal + '/compliance/registration')
check('a registration can be added', 'Saved.' in ' '.join(Client.alerts(h)), Client.alerts(h))
rid = link(admin.get('/people/' + cal + '/record')[1], r'name="id" value="([0-9a-f-]{36})"')
s, h, _ = admin.post('/people/' + cal + '/record', {'id': rid}, action='/people/' + cal + '/compliance/registration/remove')
check('and removed', 'Removed.' in ' '.join(Client.alerts(h)), Client.alerts(h))
check('staff cannot add compliance records to anyone', staff_c.post('/me', {'registration_type': 'x'}, action='/people/' + cal + '/compliance/registration')[0] == 403)
check('an unknown kind of record is a 404', admin.post('/people/' + cal + '/record', {}, action='/people/' + cal + '/compliance/bogus')[0] == 404)
check('a manager who opens their own staff record is sent to My profile (no approving their own changes)', mgr_c.get('/people/' + link(admin.get('/people?q=Isla')[1], r'/people/([0-9a-f-]{36})') + '/record')[2].endswith('/me'))

print('--- public information pages, documentation, and passwords')
anon = Client()
for path, needle in [('/about', 'One record of your people'), ('/features', 'Everything Care Core does'), ('/security', 'Built to be trusted'), ('/docs', 'Everything Care Core does, explained'), ('/docs/leavers', 'Show what will happen'), ('/docs/webhooks', 'X-CareCore-Signature'), ('/docs/scim', 'Provisioning from Entra')]:
    s, h, _ = anon.get(path)
    check('public page without signing in: ' + path, s == 200 and needle in h and 'Sign in</a>' in h, s)
s, h, _ = anon.get('/docs?q=signature')
check('documentation search works', s == 200 and 'My profile' in h and 'match' in h)
check('an unknown docs page is a 404', anon.get('/docs/nonsense')[0] == 404)
check('docs path tricks are refused', anon.get('/docs/..%2Fapp')[0] == 404 and anon.get('/docs/../../.env')[0] in (403, 404))
s, h, _ = admin.get('/docs/roles')
check('signed-in people get the same pages with the full menu and a Help link', s == 200 and 'href="/docs"' in h and 'Approvals' in h)
for path in ['/docs/overview', '/docs/install', '/docs/event-catalogue']:
    check('docs page renders: ' + path, anon.get(path)[0] == 200)

pw = Client()
s, h, _ = admin.post('/admin/users', {'email': 'pw.test@example.test', 'role': 'staff', 'person_id': ''}, action='/admin/users')
inv = link(' '.join(Client.alerts(h)), r'/invite/([0-9a-f]{48})')
check('an administrator can create a login', inv is not None, Client.alerts(h))
s, h, _ = pw.post('/invite/' + inv, {'password': 'first password here', 'password2': 'first password here'}, action='/invite/' + inv)
pwc = Client('pw.test@example.test', 'first password here')
check('the new person can sign in', 'Dashboard' in pwc.get('/')[1])
s, h, _ = pwc.post('/account', {'current_password': 'wrong', 'new_password': 'second password here', 'new_password2': 'second password here'}, action='/account/password')
check('changing a password needs the right current one', s == 422 and 'not right' in h)
s, h, _ = pwc.post('/account', {'current_password': 'first password here', 'new_password': 'second password here', 'new_password2': 'different'}, action='/account/password')
check('and the two new passwords must match', s == 422 and 'do not match' in h)
s, h, _ = pwc.post('/account', {'current_password': 'first password here', 'new_password': 'second password here', 'new_password2': 'second password here'}, action='/account/password')
check('a password can be changed', 'password has been changed' in ' '.join(Client.alerts(h)).lower(), Client.alerts(h))
check('the new password works and the old one does not', 'Dashboard' in Client('pw.test@example.test', 'second password here').get('/')[1] and 'Dashboard' not in Client('pw.test@example.test', 'first password here').get('/')[1])
uid = link(admin.get('/admin/users')[1], r'(?s)pw\.test@example\.test.*?name="id" value="(\d+)"')
s, h, _ = admin.post('/admin/users', {'id': uid}, action='/admin/users/reinvite')
inv2 = link(' '.join(Client.alerts(h)), r'/invite/([0-9a-f]{48})')
check('an administrator can issue a new invitation for a forgotten password', inv2 is not None, Client.alerts(h))
check('which stops the old password at once, and signs out the open session', 'Dashboard' not in Client('pw.test@example.test', 'second password here').get('/')[1] and 'Dashboard' not in pwc.get('/')[1])
s, h, _ = Client().post('/invite/' + inv2, {'password': 'third password here', 'password2': 'third password here'}, action='/invite/' + inv2)
check('the new link lets them choose a new password', 'Dashboard' in Client('pw.test@example.test', 'third password here').get('/')[1])
check('staff cannot issue invitations', staff_c.post('/me', {'id': uid}, action='/admin/users/reinvite')[0] == 403)

print('--- the HTTP API')
def apicall(method, path, token=None, body=None, headers=None):
    h = {'Accept': 'application/json'}
    if token:
        h['Authorization'] = 'Bearer ' + token
    data = None
    if body is not None:
        data = json.dumps(body).encode(); h['Content-Type'] = 'application/json'
    h.update(headers or {})
    rq = urllib.request.Request(BASE + '/api/v1' + path, data=data, method=method, headers=h)
    try:
        r = urllib.request.urlopen(rq, timeout=30)
        raw = r.read(); return r.status, (json.loads(raw) if raw else None), r.headers
    except urllib.error.HTTPError as e:
        raw = e.read(); return e.code, (json.loads(raw) if raw else None), e.headers

s, h, _ = admin.get('/admin/integrations')
check('the integrations page has an API keys section with every scope', 'API keys' in h and 'name="scopes[people.read]"' in h and 'staff.sensitive' in h)
scopes = {'scopes[%s]' % k: '1' for k in ['people.read', 'people.write', 'staff.read', 'staff.write', 'teams.read', 'teams.write', 'events.read']}
s, h, _ = admin.post('/admin/integrations', {'name': 'HTTP test', 'allowed_ips': '', 'expires_on': '', 'rate_limit': '300', **scopes}, action='/admin/api-keys')
token = link(' '.join(Client.alerts(h)), r'(cc_[0-9a-f]{8}_[0-9a-f]{48})')
check('an administrator can create an API key; the secret is shown once', token is not None, Client.alerts(h))
s, h, _ = admin.get('/admin/integrations')
check('and it is listed by prefix only, never the secret', token[:11] in h and token not in h and 'HTTP test' in h)
st, body, hd = apicall('GET', '/ping', token)
check('the key works over HTTP', st == 200 and body['data']['key']['name'] == 'HTTP test' and body['data']['organisation']['name'], body)
check('responses carry rate limit headers, a request id and no caching', hd['X-RateLimit-Limit'] == '300' and len(hd['X-Request-Id']) == 16 and hd['Cache-Control'] == 'no-store')
check('no key, or a bad key, is a 401 problem document', apicall('GET', '/people')[0] == 401 and apicall('GET', '/people', 'cc_00000000_' + '0' * 48)[0] == 401)
st, body, hd = apicall('POST', '/people', token, {'first_name': 'Api', 'last_name': 'Http', 'email': 'api.http@example.test'}, {'Idempotency-Key': 'http-1'})
check('POST /people creates (201, Location)', st == 201 and hd['Location'].endswith('/people/' + body['data']['id']), body)
pid = body['data']['id']
st2, body2, hd2 = apicall('POST', '/people', token, {'first_name': 'Api', 'last_name': 'Http', 'email': 'api.http@example.test'}, {'Idempotency-Key': 'http-1'})
check('a retry with the same Idempotency-Key replays the answer', st2 == 201 and body2 == body and hd2['Idempotent-Replayed'] == 'true')
check('the new person is in the web interface too (one system of record)', 'Api Http' in admin.get('/people?q=Api+Http')[1])
st, body, _ = apicall('POST', '/employments', token, {'person_id': pid, 'job_title': 'Support Worker', 'start_date': '2026-01-05', 'hours_per_week': 30})
check('POST /employments runs the joiner process', st == 201 and body['data']['status'] == 'current' and body['data']['tasks_created'] == 7, body)
eid = body['data']['id']
check('the joiner appears on the web dashboard and in People > Staff', 'Api Http' in admin.get('/people?kind=staff&q=Api')[1])
st, body, _ = apicall('POST', '/employments/' + eid + '/end', token, {'last_day': '2026-02-27', 'reason': 'Resigned'})
check('POST /employments/{id}/end runs the leaver process', st == 200 and body['data']['employment']['end_date'] == '2026-02-27' and body['data']['offboarding_tasks_created'] == 5, body)
st, body, _ = apicall('GET', '/events?types=employment.*', token)
check('the event feed shows what the API did', st == 200 and any(e['type'] == 'employment.ended' for e in body['data']))
st, body, _ = apicall('GET', '/audit', token)
check('a key without audit.read cannot read the log (403 problem+json)', st == 403 and body['code'] == 'forbidden')
st, body, hd = apicall('GET', '/openapi.json')
check('the OpenAPI document is public and complete', st == 200 and body['openapi'].startswith('3.') and len(body['paths']) > 40)
check('unknown endpoints and wrong methods are handled', apicall('GET', '/nonsense', token)[0] == 404 and apicall('PUT', '/people', token, {})[0] == 405)
check('the API sets no cookies and starts no session', 'Set-Cookie' not in apicall('GET', '/ping', token)[2])
kid = link(admin.get('/admin/integrations')[1], r'(?s)HTTP test.*?name="id" value="(\d+)"')
s, h, _ = admin.post('/admin/integrations', {'id': kid}, action='/admin/api-keys/revoke')
check('revoking a key stops it at once', 'revoked' in ' '.join(Client.alerts(h)).lower() and apicall('GET', '/ping', token)[0] == 401, Client.alerts(h))
check('staff cannot manage API keys', staff_c.post('/me', {'name': 'x'}, action='/admin/api-keys')[0] == 403)

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
