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
    match = re.search(r'name="([a-f0-9]{32})"[^>]*value="1"', html) or re.search(r'task=logout[^"<>]*?([a-f0-9]{32})=1', html)
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
partial = call('save', {'message':json.dumps({'type':'class','sender':'Smoke','tags':['group.Youth'],'subject_da':'Incomplete'})})
partial_id = {'id':partial['id'],'revision':partial['revision']}
assert json.loads(partial['content'])['subject_da'] == 'Incomplete' and json.loads(partial['content'])['body_en'] == '', 'Partial content is saved without requiring both languages'
call('preview', partial_id, 422)
assert int(partial['mailing_attempted']) == 0, 'An incomplete draft cannot prepare a test mailing'
_, partial_page = request('/index.php?option=com_intercom&view=composer&id=' + str(partial['id']))
new_link = re.search(r'<a[^>]+id="ic-new-message"[^>]+href="([^"]+)"[^>]*>',partial_page)
assert new_link and not re.search(r'[?&]id=', new_link[1]) and 'hidden' not in new_link[0] and 'new=1' in new_link[1], 'Saved drafts have a visible New communication link without the current ID'
_, new_page = request(new_link[1].replace('&amp;', '&'))
assert 'id="ic-form"' in new_page and json.loads(partial['content'])['subject_da'] == 'Incomplete', 'New communication opens the composer without deleting the saved draft'
local = call('saveaudience', {'message':json.dumps({'type':'class','sender':'Smoke','tags':['group.Youth']})})
assert local['estimate_count'] is None and int(local['mailing_attempted']) == 0, 'Local audience save persists identity before contacting the provider'
call('delete', {'id':local['id'],'revision':local['revision']})
call('delete', partial_id)
audience = call('audience', {'message':json.dumps({'type':'class','sender':'Smoke','tags':['group.Youth']})})
assert int(audience['mailing_id']) == 0 and int(audience['estimate_count']) == 1 and json.loads(audience['content'])['body_en'] == '', 'Native step transition saves an incomplete draft and estimates without mailing'
call('delete', {'id':audience['id'],'revision':audience['revision']})
assert request(api, {'task':'api.audience','message':'{}'})[0] == 403, 'Audience estimate requires native CSRF'
preview = call('render', {'message': json.dumps({**message, 'format':'html', 'body_da':'<p>Dansk <strong>Ægte</strong></p>', 'body_en':'<p>English</p>'})})
assert '<strong>Ægte</strong>' in preview['da'] and 'English</p>' not in preview['da'], 'Server preview uses sanitised single-language branded template'
assert 'Unge, onsdag 17:30' in preview['da'] and 'Youth, Wednesday 17:30' in preview['en'], 'Native composer renderer uses trusted language labels in email footer'
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
assert status == 200 and 'Latest sent emails' in admin, 'Administrator audit dashboard available'
acceptance = '/administrator/index.php?option=com_intercom&view=acceptance'
status, gate = request(acceptance)
assert status == 200 and 'Member sends are blocked' in gate and 'Simulation never verifies' in gate, 'Native acceptance screen is fail-closed in simulation'
for task in ['prepare','release','verify','retire']:
    route = '/administrator/index.php?option=com_intercom&task=acceptance.' + task
    assert request(route, {})[0] == 403 and request(route)[0] == 403, 'Acceptance mutation requires POST and CSRF'
options = '/administrator/index.php?option=com_config&view=component&component=com_intercom'
_, admin = request(options)
assert 'name="jform[release_verified]"' not in admin and 'jform[acceptance_recipient]' in admin, 'Options displays computed approval and editable approved recipient'
assert 'jform[sender_name]' in admin and 'jform[sender_email]' in admin, 'Single sender name and email render in Options'
assert 'id="client_id"' in admin and 'id="access_token"' in admin, 'Secret controls render in native Options'
assert 'jform_category_club' not in admin and 'jform_audience_rules' not in admin, 'Communication settings moved out of Options'
assert '<select id="jform_unsubscribe_form_id"' in admin and 'Existing legacy form (432342)' in admin, 'Native Options preserves a legacy selection in the new form dropdown'
assert request('/administrator/index.php?option=com_intercom&task=connection.forms&format=json&group_id=0')[0] == 422, 'Form listing rejects an invalid list before any provider call'
assert 'jform_filter_ids' not in admin, 'Obsolete manual filter IDs are absent from Options'
class HiddenInputs(HTMLParser):
    def __init__(self): super().__init__(); self.values = {}
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'input' and attrs.get('type') == 'hidden':
            self.values[attrs.get('name','')] = attrs.get('value','')
inputs = HiddenInputs(); inputs.feed(admin)
status, admin = request('/administrator/index.php?option=com_config', {
    **inputs.values, 'task':'component.apply', 'jform[footer_profile_da]':'https://example.org/da/profile', 'jform[footer_profile_en]':'https://example.org/en/profile', 'jform[footer_address]':'HTTP Club address\r\nSecond address line', 'jform[sender_name]':'CI shared sender', 'jform[sender_email]':'sender@example.invalid', 'jform[acceptance_recipient]':'approved@example.invalid', 'jform[mode]':'fake',
    'jform[retention_days]':'45','jform[unsubscribe_form_id]':'432342'})
assert status == 200 and 'Configuration saved' in admin, 'Native Options saves successfully'
inputs = HiddenInputs(); inputs.feed(admin)
status, admin = request('/administrator/index.php?option=com_config', {
    **inputs.values, 'task':'component.apply', 'jform[footer_profile_da]':'https://example.org/da/profile', 'jform[footer_profile_en]':'https://example.org/en/profile', 'jform[footer_address]':'HTTP Club address\r\nSecond address line', 'jform[sender_name]':'CI shared sender', 'jform[mode]':'live',
    'jform[retention_days]':'99','jform[audience_rules]':'[]'})
assert status == 200 and 'Check the recipient list' in admin, ('Invalid native Options rejected', status, re.findall(r'<joomla-alert[^>]*>(.*?)</joomla-alert>', admin, re.S))
assert 'The InterCOM settings could not be saved.' in admin and 'Could not save data. Error: %s' not in admin, 'Rejected native Options renders the specific validation reason and a placeholder-free fallback'
class OptionValues(HTMLParser):
    def __init__(self): super().__init__(); self.values = {}; self.select = None
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'input': self.values[attrs.get('name','')] = attrs.get('value','')
        elif tag == 'select': self.select = attrs.get('name')
        elif tag == 'option' and self.select and 'selected' in attrs: self.values[self.select] = attrs.get('value','')
    def handle_endtag(self, tag):
        if tag == 'select': self.select = None
for rendered in [admin, request(options)[1]]:
    saved = OptionValues(); saved.feed(rendered)
    for field, expected in {'mode':'fake', 'sender_name':'CI shared sender', 'sender_email':'sender@example.invalid', 'acceptance_recipient':'approved@example.invalid', 'retention_days':'45'}.items():
        assert saved.values.get('jform['+field+']') == expected, ('Rejected Options and reload show persisted values', field)
assert request('/administrator/index.php?option=com_intercom&task=connection.importtokens', {'expires_in':'3600'})[0] == 403, 'Token import requires CSRF'
assert request('/administrator/index.php?option=com_intercom&task=connection.verifyaccount', {})[0] == 403, 'Identity pin requires CSRF'
assert request('/administrator/index.php?option=com_intercom&task=connection.verifyaccount')[0] == 403, 'Identity pin requires POST'

status, admin = request('/administrator/index.php?option=com_intercom&task=connection.savecredentials', {
    token(admin):'1','client_id':os.environ['INTERCOM_TEST_CLIENT_ID'],
    'client_secret':os.environ['INTERCOM_TEST_CLIENT_SECRET']})
assert status == 200 and 'Saved' in admin, 'Credentials saved despite simulated reservations'
access = os.environ['INTERCOM_TEST_ACCESS_TOKEN']
for value in (access, os.environ['INTERCOM_TEST_CLIENT_ID'], os.environ['INTERCOM_TEST_CLIENT_SECRET']):
    assert value not in admin, 'Secrets never echoed into page'
status, admin = request('/administrator/index.php?option=com_intercom&task=connection.importtokens', {
    token(admin):'1','access_token':access,'expires_in':'0'})
assert status == 200 and 'Enter an access token and a valid remaining lifetime' in admin, 'Invalid import rejected safely'
_, page1 = request('/administrator/index.php?option=com_intercom&view=audit&limit=10&limitstart=0')
_, page2 = request('/administrator/index.php?option=com_intercom&view=audit&limit=10&limitstart=10')
rows = lambda html: re.findall(r'data-audit-id="(\d+)"', html)
assert len(rows(page1)) == 10 and rows(page2) and not set(rows(page1)) & set(rows(page2)), 'Audit pages are bounded and distinct'
_, history = request('/administrator/index.php?option=com_intercom&view=history')
assert 'history-limit' in history and 'Latest audit entries' not in history, 'Native paginated sent-mail history renders'
_, detail = request('/administrator/index.php?option=com_intercom&view=history&id=1')
assert 'COM_INTERCOM_FILTER' not in detail and 'COM_INTERCOM_MAILING' not in detail, 'History provider references use translated labels'
print('PASS: Native Options, encrypted credentials/token import, invalid import and audit/history pagination')

management = '/administrator/index.php?option=com_intercom'
settings = management + '&view=settings'
status, groups = request(settings)
assert status == 200 and 'Communication groups' in groups, 'Component communication catalogue is available'
assert request(management + '&task=management.savetype', {'jform[type_key]':'forged'})[0] == 403, 'Management requires CSRF'
status, editor = request(settings + '&section=types&edit=1')
assert status == 200 and 'jform[translations][da-DK][name]' in editor and 'jform[rules][intercom.type.compose]' in editor, 'Language tabs and native record permissions render'
assert '/media/com_intercom/js/permissions.js?' in editor and 'ic-permissions-status' in editor, 'Communication editor loads its read-only permission calculator'
rule_fields = {name:'' for name in re.findall(r'name="(jform\[rules\]\[[^\]]+\]\[\d+\])"',editor)}
rule_fields['jform[rules][intercom.type.compose][2]'] = '1'
permission_preview = management + '&task=management.previewpermissions&format=json'
assert request(permission_preview, {})[0] == 403 and request(permission_preview)[0] == 403, 'Permission preview requires POST and CSRF'
status, raw = request(permission_preview, {token(editor):'1', **rule_fields})
calculation = json.loads(raw)
assert status == 200 and calculation['data']['jform_rules_intercom.type.compose_2']['text'] == 'Allowed', 'New-record preview calculates the staged allow'
assert calculation['data']['jform_rules_intercom.type.compose_3']['text'] == 'Allowed (Inherited)', 'Permission preview recalculates descendants'
status, groups = request(management + '&task=management.savetype', {
    token(editor):'1', **rule_fields, 'jform[type_key]':'http_group', 'jform[suppression]':'http-optout', 'jform[state]':'1',
    'jform[translations][en-GB][name]':'HTTP group', 'jform[translations][da-DK][name]':'HTTP gruppe'})
assert status == 200 and 'HTTP group' in groups and 'http-optout' in groups, 'Native management form saves translated communication'
edit_link = re.search(r'href="([^"]+)">HTTP group</a>',groups)
assert edit_link, 'Saved communication provides an edit link'
_, editor = request('/administrator/' + edit_link[1].replace('&amp;', '&'))
inputs = HiddenInputs(); inputs.feed(editor)
registered_row = re.search(r'<select[^>]+id="jform_rules_intercom.type.compose_2"[^>]*>.*?</tr>',editor,re.S).group()
assert re.search(r'<option value="1"\s+selected="selected"',registered_row) and '>Allowed</span>' in registered_row, 'Full browser form preserves Inherited ancestors and saved Registered allowance'
status, raw = request(permission_preview, {**inputs.values, **rule_fields, 'jform[rules][intercom.type.compose][1]':'0'})
assert status == 200 and json.loads(raw)['data']['jform_rules_intercom.type.compose_2']['text'] == 'Not Allowed (Locked)', 'Existing-record preview shows staged parent denial without saving'
_, unchanged = request('/administrator/' + edit_link[1].replace('&amp;', '&'))
registered_row = re.search(r'<select[^>]+id="jform_rules_intercom.type.compose_2"[^>]*>.*?</tr>',unchanged,re.S).group()
assert '>Allowed</span>' in registered_row and 'Not Allowed (Locked)' not in registered_row, 'Reopening after a preview retains the saved permission'
assert request(permission_preview, {**inputs.values, **rule_fields, 'jform[revision]':'0'})[0] == 409, 'Permission preview rejects stale revisions'
assert request(permission_preview, {**inputs.values, 'jform[rules][core.admin][2]':'1'})[0] == 422, 'Permission preview rejects arbitrary ACL actions'
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
assert 'Reconcile with CleverReach' in filters and 'Unresolved filter creations' in filters, 'Administrator can see reconciliation and unresolved slots'
assert request(management + '&task=reconciliation.run', {})[0] == 403, 'Reconciliation requires CSRF'
assert request(management + '&task=reconciliation.run')[0] == 403, 'Reconciliation cannot run through GET'
call('save', {'message':json.dumps({**message, 'tags':[]})}, 422)
print('PASS: Design CRUD/concurrency, theme preview, sender defaults, filter page and team-policy guard')

# Native Manager role can open the component but cannot inspect audits or settings.
admin_client = client
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
_, login = request(management)
status, overview = request('/administrator/index.php', {'option':'com_login','task':'login','username':'ci-manager',
    'passwd':os.environ['INTERCOM_ADMIN_PASSWORD'],token(login):'1',
    'return':base64.b64encode(b'index.php?option=com_intercom').decode()})
assert status == 200 and 'InterCOM dashboard' in overview and 'Latest sent emails' not in overview and 'ic-admin-stats' not in overview, 'Dashboard hides audit-derived data without permission'
assert request(management + '&view=history')[0] == 403, 'Sent-mail history requires its dedicated permission'
assert request(management + '&view=history&id=1')[0] == 403, 'Message detail deep link requires content permission'
assert request(management + '&view=audit')[0] == 403, 'Audit deep link requires audit permission'
assert request(management + '&view=filters')[0] == 403, 'Filter deep link requires audit permission'
assert request(management + '&task=reconciliation.run', {})[0] == 403, 'Manager cannot run reconciliation'
assert request(management + '&view=acceptance')[0] == 403, 'Manager cannot inspect delivery acceptance'
assert request(management + '&task=acceptance.prepare', {})[0] == 403, 'Manager cannot initiate acceptance'
assert request(settings + '&section=design')[0] == 403, 'Email design deep link requires admin permission'
assert request(management + '&task=connection.forms&format=json&group_id=0')[0] == 403, 'Form catalogue requires component admin permission'
assert request(permission_preview, {token(overview):'1', **rule_fields})[0] == 403, 'Manager with a valid CSRF token cannot calculate record permissions'
client = admin_client
print('PASS: Native backend permission boundaries for dashboard, audit, filters and email design')
