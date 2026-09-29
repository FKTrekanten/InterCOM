"""Exercise native session/CSRF/API boundaries against disposable CI only."""
import base64, http.cookiejar, json, os, re, urllib.error, urllib.parse, urllib.request
from html.parser import HTMLParser
base = 'http://127.0.0.1:' + os.environ.get('INTERCOM_PORT', '18088')
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def request(path, data=None):
    encoded = urllib.parse.urlencode(data).encode() if data is not None else None
    try:
        with client.open(base + path, encoded, timeout=20) as r: return r.status, r.read().decode()
    except urllib.error.HTTPError as e: return e.code, e.read().decode()
def token(html):
    match = re.search(r'name="([a-f0-9]{32})"[^>]*value="1"', html)
    assert match, 'Native Joomla CSRF token rendered'
    return match[1]
assert request('/index.php?option=com_intercom')[0] == 403, 'Guests denied'
_, page = request('/index.php?option=com_users&view=login')
status, page = request('/index.php?option=com_users&task=user.login', {
    'username':'intercom', 'password':os.environ['INTERCOM_ADMIN_PASSWORD'], token(page):'1',
    'return':base64.b64encode(b'index.php?option=com_intercom').decode()})
assert status == 200 and 'id="ic-form"' in page, 'Native session login opens component'
csrf = token(page)
api = '/index.php?option=com_intercom&format=json'
assert request(api, {'task':'api.save'})[0] == 403, 'Missing CSRF denied'
message = {'type':'class','sender':'Smoke','subject_da':'Test','subject_en':'Test',
           'body_da':'Simulering','body_en':'Simulation','tags':['group.Youth']}
def call(action, fields, expected=200):
    status, raw = request(api, {'task':'api.'+action, csrf:'1', **fields})
    result = json.loads(raw)
    assert status == expected, (action, status, result)
    return result.get('data')
draft = call('save', {'message':json.dumps(message)})
identity = {'id':draft['id'],'revision':draft['revision']}
call('release', {**identity,'confirm':1}, 409)
call('preview', identity)
call('release', identity, 403)
assert call('release', {**identity,'confirm':1})['state'] == 'submitted'
call('release', {**identity,'confirm':1}, 409)
print('PASS: HTTP login, composer, CSRF, test requirement, explicit confirmation and duplicate-send guard')
# Saving real credentials must remain possible after simulated previews/sends.
_, admin_login = request('/administrator/index.php?option=com_intercom')
status, admin = request('/administrator/index.php', {
    'option':'com_login','task':'login','username':'intercom','passwd':os.environ['INTERCOM_ADMIN_PASSWORD'],
    token(admin_login):'1','return':base64.b64encode(b'index.php?option=com_intercom').decode()})
assert status == 200 and 'id="client_id"' in admin, 'Administrator component available'
status, admin = request('/administrator/index.php?option=com_intercom&task=connection.save', {
    token(admin):'1','mode':'fake','retention_days':'45','audience_rules':'[]',
    'filter_ids':'9001,9002,9003,9004','client_id':os.environ['INTERCOM_TEST_CLIENT_ID'],
    'client_secret':os.environ['INTERCOM_TEST_CLIENT_SECRET']})
assert status == 200 and 'Saved' in admin, 'Credentials saved despite simulated reservations'
assert os.environ['INTERCOM_TEST_CLIENT_ID'] not in admin and os.environ['INTERCOM_TEST_CLIENT_SECRET'] not in admin, 'Credentials never echoed into page'
print('PASS: Administrator credentials save after simulated reservations without exposing secrets')
