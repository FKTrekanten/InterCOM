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
status, guest = request('/index.php?option=com_intercom&view=composer')
assert status == 200 and 'name="username"' in guest and 'id="ic-form"' not in guest, 'Guests sent to native login without exposing composer'
match = re.search(r'name="return"[^>]*value="([^"]+)"', guest)
assert match and 'option=com_intercom' in base64.b64decode(match[1]).decode(), 'Native login preserves return to Intercom'
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
assert status == 200 and 'audit-limit' in admin, 'Administrator audit dashboard available'
options = '/administrator/index.php?option=com_config&view=component&component=com_intercom'
_, admin = request(options)
assert 'id="client_id"' in admin and 'id="access_token"' in admin, 'Secret controls render in native Options'
assert 'jform_filter_ids' not in admin, 'Obsolete manual filter IDs are absent from Options'
class HiddenInputs(HTMLParser):
    def __init__(self): super().__init__(); self.values = {}
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'input' and attrs.get('type') == 'hidden':
            self.values[attrs.get('name','')] = attrs.get('value','')
inputs = HiddenInputs(); inputs.feed(admin)
status, admin = request('/administrator/index.php?option=com_config', {
    **inputs.values, 'task':'component.apply', 'jform[mode]':'fake',
    'jform[retention_days]':'45','jform[audience_rules]':'[]',
    'jform[category_club]':'71'})
assert status == 200 and 'Configuration saved' in admin, 'Native Options saves successfully'
inputs = HiddenInputs(); inputs.feed(admin)
status, admin = request('/administrator/index.php?option=com_config', {
    **inputs.values, 'task':'component.apply', 'jform[mode]':'live',
    'jform[retention_days]':'99','jform[audience_rules]':'[]'})
assert status == 200 and 'Check the recipient list' in admin, ('Invalid native Options rejected', status, re.findall(r'<joomla-alert[^>]*>(.*?)</joomla-alert>', admin, re.S))
assert request('/administrator/index.php?option=com_intercom&task=connection.importtokens', {'expires_in':'3600'})[0] == 403, 'Token import requires CSRF'

status, admin = request('/administrator/index.php?option=com_intercom&task=connection.savecredentials', {
    token(admin):'1','client_id':os.environ['INTERCOM_TEST_CLIENT_ID'],
    'client_secret':os.environ['INTERCOM_TEST_CLIENT_SECRET']})
assert status == 200 and 'Saved' in admin, 'Credentials saved despite simulated reservations'
access = os.environ['INTERCOM_TEST_ACCESS_TOKEN']
status, admin = request('/administrator/index.php?option=com_intercom&task=connection.importtokens', {
    token(admin):'1','access_token':access,'refresh_token':'','expires_in':'3600'})
assert status == 200 and 'Tokens stored securely' in admin, 'Manual token import succeeds'
for value in (access, os.environ['INTERCOM_TEST_CLIENT_ID'], os.environ['INTERCOM_TEST_CLIENT_SECRET']):
    assert value not in admin, 'Secrets never echoed into page'
status, admin = request('/administrator/index.php?option=com_intercom&task=connection.importtokens', {
    token(admin):'1','access_token':access,'expires_in':'0'})
assert status == 200 and 'Enter an access token and a valid remaining lifetime' in admin, 'Invalid import rejected safely'
_, page1 = request('/administrator/index.php?option=com_intercom&limit=10&limitstart=0')
_, page2 = request('/administrator/index.php?option=com_intercom&limit=10&limitstart=10')
rows = lambda html: re.findall(r'data-audit-id="(\d+)"', html)
assert len(rows(page1)) == 10 and rows(page2) and not set(rows(page1)) & set(rows(page2)), 'Audit pages are bounded and distinct'
print('PASS: Native Options, encrypted credentials/token import, invalid import and audit pagination')
