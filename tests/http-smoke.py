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
assert '<joomla-field-fancy-select' in page and 'name="tags[]" multiple' in page and 'name="memberships[]" multiple' in page, 'Exactly two native multi-select recipient fields render'
assert 'joomla-editor-tinymce' in page, 'Native Joomla editor renders'
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
preview = call('render', {'message': json.dumps({**message, 'format':'html', 'body_da':'<p>Dansk <strong>Ægte</strong></p>', 'body_en':'<p>English</p>'})})
assert '<strong>Ægte</strong>' in preview['da'] and 'English</p>' not in preview['da'], 'Server preview uses sanitised single-language branded template'
draft = call('save', {'message':json.dumps(message)})
identity = {'id':draft['id'],'revision':draft['revision']}
call('release', {**identity,'confirm':1}, 409)
call('preview', identity)
call('release', identity, 403)
assert call('release', {**identity,'confirm':1})['state'] == 'submitted'
call('release', {**identity,'confirm':1}, 409)
call('delete', identity, 409)
trash = call('save', {'message':json.dumps(message)})
trash_id = {'id':trash['id'],'revision':trash['revision']}
call('delete', {**trash_id,'revision':0}, 409)
assert request(api, {'task':'api.delete', **trash_id})[0] == 403, 'Deletion requires CSRF'
call('delete', trash_id)
call('save', {**trash_id,'message':json.dumps(message)}, 404)
call('restore', trash_id, 409)
restored = call('restore', {**trash_id,'revision':trash['revision']+1})
assert restored['state'] == 'draft' and restored['tested_revision'] is None, 'Restore requires a new test'
call('delete', {'id':restored['id'],'revision':restored['revision']})
_, deleted_page = request('/index.php?option=com_intercom&id=' + str(trash['id']))
assert 'Restore draft' in deleted_page and 'Deleted drafts' in deleted_page, 'Deleted drafts provide native restore navigation'
print('PASS: HTTP session/CSRF/send guards and draft deletion/restore boundaries')
# Saving real credentials must remain possible after simulated previews/sends.
_, admin_login = request('/administrator/index.php?option=com_intercom')
status, admin = request('/administrator/index.php', {
    'option':'com_login','task':'login','username':'intercom','passwd':os.environ['INTERCOM_ADMIN_PASSWORD'],
    token(admin_login):'1','return':base64.b64encode(b'index.php?option=com_intercom').decode()})
assert status == 200 and 'Latest audit entries' in admin, 'Administrator audit dashboard available'
options = '/administrator/index.php?option=com_config&view=component&component=com_intercom'
_, admin = request(options)
assert 'jform[sender_name]' in admin and 'jform[sender_email]' in admin, 'Single sender name and email render in Options'
assert 'id="client_id"' in admin and 'id="access_token"' in admin, 'Secret controls render in native Options'
assert 'jform_category_club' not in admin and 'jform_audience_rules' not in admin, 'Communication settings moved out of Options'
assert 'jform_filter_ids' not in admin, 'Obsolete manual filter IDs are absent from Options'
class HiddenInputs(HTMLParser):
    def __init__(self): super().__init__(); self.values = {}
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'input' and attrs.get('type') == 'hidden':
            self.values[attrs.get('name','')] = attrs.get('value','')
inputs = HiddenInputs(); inputs.feed(admin)
status, admin = request('/administrator/index.php?option=com_config', {
    **inputs.values, 'task':'component.apply', 'jform[footer_profile_da]':'https://example.org/da/profile', 'jform[footer_profile_en]':'https://example.org/en/profile', 'jform[footer_address]':'HTTP Club address', 'jform[sender_name]':'CI shared sender', 'jform[mode]':'fake',
    'jform[retention_days]':'45'})
assert status == 200 and 'Configuration saved' in admin, 'Native Options saves successfully'
inputs = HiddenInputs(); inputs.feed(admin)
status, admin = request('/administrator/index.php?option=com_config', {
    **inputs.values, 'task':'component.apply', 'jform[footer_profile_da]':'https://example.org/da/profile', 'jform[footer_profile_en]':'https://example.org/en/profile', 'jform[footer_address]':'HTTP Club address', 'jform[sender_name]':'CI shared sender', 'jform[mode]':'live',
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
_, page1 = request('/administrator/index.php?option=com_intercom&view=audit&limit=10&limitstart=0')
_, page2 = request('/administrator/index.php?option=com_intercom&view=audit&limit=10&limitstart=10')
rows = lambda html: re.findall(r'data-audit-id="(\d+)"', html)
assert len(rows(page1)) == 10 and rows(page2) and not set(rows(page1)) & set(rows(page2)), 'Audit pages are bounded and distinct'
print('PASS: Native Options, encrypted credentials/token import, invalid import and audit pagination')

management = '/administrator/index.php?option=com_intercom'
settings = management + '&view=settings'
status, groups = request(settings)
assert status == 200 and 'Communication groups' in groups, 'Component communication catalogue is available'
assert request(management + '&task=management.savetype', {'jform[type_key]':'forged'})[0] == 403, 'Management requires CSRF'
status, editor = request(settings + '&section=types&edit=1')
assert status == 200 and 'jform[translations][da-DK][name]' in editor and 'jform[rules][intercom.type.compose]' in editor, 'Language tabs and native record permissions render'
status, groups = request(management + '&task=management.savetype', {
    token(editor):'1', 'jform[type_key]':'http_group', 'jform[suppression]':'http-optout', 'jform[state]':'1',
    'jform[translations][en-GB][name]':'HTTP group', 'jform[translations][da-DK][name]':'HTTP gruppe'})
assert status == 200 and 'HTTP group' in groups and 'http-optout' in groups, 'Native management form saves translated communication'
_, tagpage = request(settings + '&section=tags')
assert 'group.Youth' in tagpage and 'Refresh tags' in tagpage, 'Recipient visibility catalogue renders separately'
assert '/media/com_intercom/js/tags.js?' in tagpage, 'Joomla resolves the registered bulk-selection script'
assert tagpage.count('data-toggle-all') == 2 and 'jform[labels][group.Youth][da-DK]' in tagpage and 'Youth, Wednesday 17:30' in tagpage, 'Both tag sections support bulk selection and multilingual display names'
_, scopepage = request(settings + '&section=access')
assert 'jform[scopes]' in scopepage and 'Audience access' in scopepage, 'Structured Joomla group audience grants render'
_, frontend = request('/index.php?option=com_intercom&view=composer')
assert 'value="group.Youth"' in frontend and 'Youth, Wednesday 17:30' in frontend, 'Composer displays friendly names while retaining raw filter values'
assert 'value="CI shared sender"' in frontend, 'New draft uses the single sender default from Options'
assert 'allow-custom=' not in frontend, 'Recipient selects never allow arbitrary tags'
assert 'data-preview-theme="dark"' in frontend, 'Dark theme preview control renders'
assert 'HTTP group' in frontend, 'Published custom communication appears in frontend'
print('PASS: Native communication CRUD form, language tabs, asset permissions, tag catalogue and audience grant editor')

_, design = request(settings + '&section=design')
assert 'HTTP Club address' in design and 'https://example.org/da/profile' in design, 'Design preview uses configurable footer and profile links'
assert 'jform[sender_en]' not in design and 'jform[sender_da]' not in design, 'Sender options removed from email design'
assert 'jform[dark_surface]' in design and 'ic-design-preview' in design, 'Design form provides paired colour settings and preview'
inputs = HiddenInputs(); inputs.feed(design)
status, saved = request(management + '&task=management.savedesign', {**inputs.values, 'jform[brand_en]':'Trekanten CI'})
assert status == 200 and 'Saved' in saved and 'Trekanten CI' in saved, 'Design saves and redirects to its page'
status, stale = request(management + '&task=management.savedesign', {**inputs.values, 'jform[brand_en]':'Stale'})
assert status == 200 and 'Reload before continuing' in stale and 'value="Stale"' not in stale, 'Concurrent stale design edits rejected'
assert request(management + '&task=management.renderdesign', {})[0] == 403, 'Design preview requires CSRF'
status, preview = request(management + '&task=management.renderdesign', {token(saved):'1', 'jform[brand_en]':'Preview only'})
preview = json.loads(preview)
assert status == 200 and 'Preview only' in preview['data']['en_dark'] and 'preview-dark' in preview['data']['en_dark'], 'Backend preview uses the shared bilingual dark-mode renderer'
_, unchanged = request(settings + '&section=design')
assert 'Trekanten CI' in unchanged and 'value="Preview only"' not in unchanged, 'Preview does not persist design settings'
status, filters = request(management + '&view=filters')
assert status == 200 and 'filters-limit' in filters, 'Full filter pool has a separate paginated page'
call('save', {'message':json.dumps({**message, 'tags':[]})}, 422)
print('PASS: Design CRUD/concurrency, theme preview, sender defaults, filter page and team-policy guard')

# Native Manager role can open the component but cannot inspect audits or settings.
admin_client = client
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
_, login = request(management)
status, overview = request('/administrator/index.php', {'option':'com_login','task':'login','username':'ci-manager',
    'passwd':os.environ['INTERCOM_ADMIN_PASSWORD'],token(login):'1',
    'return':base64.b64encode(b'index.php?option=com_intercom').decode()})
assert status == 200 and 'Intercom dashboard' in overview and 'Latest audit entries' not in overview and 'ic-admin-stats' not in overview, 'Dashboard hides audit-derived data without permission'
assert request(management + '&view=audit')[0] == 403, 'Audit deep link requires audit permission'
assert request(management + '&view=filters')[0] == 403, 'Filter deep link requires audit permission'
assert request(settings + '&section=design')[0] == 403, 'Email design deep link requires admin permission'
client = admin_client
print('PASS: Native backend permission boundaries for dashboard, audit, filters and email design')
