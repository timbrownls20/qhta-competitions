#!/usr/bin/env python3
"""M3 end-to-end smoke test: referee token flow, reminders, eligibility, entry limits.

DISPOSABLE SITES ONLY. Needs Mailpit (the referee link is read from the email)
and `pmpro_hasMembershipLevel()` — real PMPro, or the test stub that treats a
user as a member when user meta `stub_pmpro_level` is set. Run
tests/m1-smoke.php setup first.

    BASE=http://localhost:8089 WP="docker exec qctest-cli-1 wp" \\
    MAILPIT=http://localhost:8025 python3 tests/m3-http-smoke.py
"""
import html as htmllib
import json
import re
import time

from qc_http import *

if not MAILPIT:
    print('MAILPIT is required (the referee link is read from the email).')
    sys.exit(2)

BURSARY = int(php("echo Qhta_Competitions_Program_CPT::find('ecc-bursary-2027')->ID;"))
COMP = int(php("echo Qhta_Competitions_Program_CPT::find('renamed-writing-comp')->ID;"))
php(f"wp_update_post(array('ID'=>{BURSARY},'post_status'=>'publish'));")
BKEY, CKEY = 'comp-ecc-bursary-2027', 'comp-writing-competition-2026-demo'
BPAGE = ensure_page('ecc-bursary', '[qhta_program_form program="ecc-bursary-2027"]')
CPAGE = ensure_page('writing-competition', f'[qhta_program_form program="{COMP}"]')
STATUSES = 'array_keys(Qhta_Competitions_Submission_CPT::statuses())'
for login, member in (('member1', True), ('nonmember1', False)):
    if php(f"echo get_user_by('login','{login}') ? 1 : 0;") != '1':
        wp('user', 'create', login, f'{login}@example.com', '--role=subscriber', f'--user_pass={login}pass')
    uid = php(f"echo get_user_by('login','{login}')->ID;")
    php(f"update_user_meta({uid}, 'stub_pmpro_level', '{'1' if member else ''}');")

set_mode('competitions', 'on')
set_mode(BKEY, 'on')
set_mode(CKEY, 'on')
set_window(BURSARY, -3600, 30 * 86400)
set_window(COMP, -3600, 30 * 86400)
admin = Client().login('admin', 'admin')
anon = Client()
mailpit_clear()


def confirm_link(to, after_id=None):
    """Newest confirmation/reminder link emailed to `to`."""
    time.sleep(0.5)
    for m in mailpit_messages():
        if to in [t['Address'] for t in m['To']] and ('confirm' in m['Subject'].lower()):
            body = mailpit_get(m['ID'])['Text']
            u = re.search(r'(https?://\S+\?qc_confirm=[a-f0-9]{64})', body)
            if u:
                return u.group(1)
    return None


def status_of(ref):
    return sub_meta(ref, []).get('status')


def confirm_form(client, url):
    st, _, h, _ = client.get(url)
    h = h.decode()
    nonce = re.search(r'name="_qc_nonce" value="([^"]+)"', h)
    return h, (nonce.group(1) if nonce else '')


# ---- Referee end-to-end ---------------------------------------------------
print('\n# Referee confirmation')
res, _ = submit(anon, BPAGE, BURSARY, bursary_fields(name='Ref Flow', email='refflow@example.com', referee_email='referee1@example.com'))
ref = ref_in(res)
check(bool(ref) and status_of(ref) == 'qc_pending_confirm', 'bursary submission awaits confirmation')
link = confirm_link('referee1@example.com')
check(bool(link), 'referee emailed a /?qc_confirm= link')
link = link.replace('http://localhost:8089', BASE) if link else BASE + '/?qc_confirm=' + 'f' * 64

ref_client = Client()
h, nonce = confirm_form(ref_client, link)
check(ref in h and 'Ref Flow' in h and 'word word' in h, 'confirm page shows ref, applicant and written piece')
check('qc_file' not in h and 'refflow@example.com' not in h and 'referee1@example.com' not in h and '0400000000' not in h, 'summary has no file links, emails or phone numbers')
check('value="Pat Principal"' in h, 'typed-name prefilled with referee name')

st, url, body = ref_client.post(link, [('_qc_nonce', nonce), ('qc_signature', 'Pat Principal')])
check('Please tick to confirm' in body and status_of(ref) == 'qc_pending_confirm', 'declaration unticked → refused, still pending')
st, url, body = ref_client.post(link, [('_qc_nonce', 'bad'), ('qc_agree', '1'), ('qc_signature', 'Pat Principal')])
check('session expired' in body and status_of(ref) == 'qc_pending_confirm', 'bad nonce → refused')
h, nonce = confirm_form(ref_client, link)
st, url, body = ref_client.post(link, [('_qc_nonce', nonce), ('qc_agree', '1'), ('qc_signature', 'Pat Principal'), ('qc_comment', 'Strong candidate.')])
check('qc_confirmed=1' in url and 'You have confirmed this application' in body, 'confirm → thank-you page')
m = sub_meta(ref, ['_qc_confirmation', '_qc_log'])
rec = json.loads(m.get('_qc_confirmation') or '{}')
check(m.get('status') == 'qc_submitted', 'status moved to submitted')
check(rec.get('typed_name') == 'Pat Principal' and rec.get('comment') == 'Strong candidate.' and rec.get('confirmed_at') and rec.get('ip') and rec.get('declaration'), 'confirmation record: name, comment, declaration, time, IP')
check(rec.get('token_hash') and len(rec['token_hash']) == 64 and link.split('=')[-1] not in json.dumps(m), 'only the token hash is stored')
check('Confirmed by referee' in m.get('_qc_log', ''), 'transition logged')
time.sleep(0.5)
check(any(ref in x['Subject'] and 'refflow@example.com' in [t['Address'] for t in x['To']] and 'confirmed' in x['Subject'].lower() for x in mailpit_messages()), 'applicant notified of confirmation')

# Reuse
_, _, h, _ = Client().get(link)
check('Already confirmed' in h.decode() and '<form' not in h.decode().split('qc-confirm-page')[1][:3000], 'reused link → "already confirmed", no form')
st, url, body = ref_client.post(link, [('_qc_nonce', nonce), ('qc_agree', '1'), ('qc_signature', 'Someone Else')])
rec2 = json.loads(sub_meta(ref, ['_qc_confirmation'])['_qc_confirmation'])
check(rec2['typed_name'] == 'Pat Principal' and 'Already confirmed' in body, 'reused link POST rejected, record unchanged')

# Invalid
_, _, h, _ = Client().get('/?qc_confirm=' + 'a' * 64)
check('Link not recognised' in h.decode(), 'unknown token → not recognised')
_, _, h, _ = Client().get('/?qc_confirm=nothex')
check('Link not recognised' in h.decode(), 'malformed token → not recognised')

# Expired
res, _ = submit(anon, BPAGE, BURSARY, bursary_fields(name='Expiry Case', email='exp@example.com', referee_email='referee2@example.com'))
ref_e = ref_in(res)
link_e = (confirm_link('referee2@example.com') or '').replace('http://localhost:8089', BASE)
set_window(BURSARY, -30 * 86400, -8 * 86400)  # closed 8 days ago → links expired (close + 7 days)
h, _ = confirm_form(Client(), link_e)
check('Link expired' in h, 'expired token (close + 7 days passed) → expired message')
c = Client()
st, url, body = c.post(link_e, [('_qc_nonce', 'x'), ('qc_agree', '1'), ('qc_signature', 'Pat')])
check(status_of(ref_e) == 'qc_pending_confirm' and 'Link expired' in body, 'expired token POST rejected')
set_window(BURSARY, -3600, 30 * 86400)
h, _ = confirm_form(Client(), link_e)
check('Confirm application' in h, 'moving the close date later re-validates the link')

# Kill switch and round flag
set_mode('competitions', 'off')
h, _ = confirm_form(Client(), link_e)
check('temporarily unavailable' in h, 'competitions off → confirmations paused')
set_mode('competitions', 'on')
set_mode(BKEY, 'off')
h, nonce = confirm_form(Client(), link_e)
check('Confirm application' in h, 'round flag off → confirmation still works for real entries')
set_mode(BKEY, 'on')

# Resend (admin) retires the old link
_, _, eh, _ = admin.get(f"/wp-admin/post.php?post={sub_meta(ref_e, [])['id']}&action=edit")
eh = eh.decode()
rn = re.search(r'name="action" value="qc_confirm_resend">.*?name="_wpnonce" value="([^"]+)"', eh, re.S)
check(bool(rn) and 'Referee confirmation' in eh, 'admin screen shows referee box with re-send')
mailpit_clear()
admin.post(BASE + '/wp-admin/admin-post.php', [('action', 'qc_confirm_resend'), ('id', str(sub_meta(ref_e, [])['id'])), ('_wpnonce', rn.group(1) if rn else '')])
new_link = (confirm_link('referee2@example.com') or '').replace('http://localhost:8089', BASE)
check(bool(new_link) and new_link != link_e, 're-send emails a new link')
h, _ = confirm_form(Client(), link_e)
check('Link replaced' in h, 'old link → "replaced"')
c = Client()
h, nonce = confirm_form(c, new_link)
st, url, body = c.post(new_link, [('_qc_nonce', nonce), ('qc_agree', '1'), ('qc_signature', 'Pat Principal')])
check(status_of(ref_e) == 'qc_submitted', 'new link confirms')

# Manual confirmation
res, _ = submit(anon, BPAGE, BURSARY, bursary_fields(name='Manual Case', email='man@example.com', referee_email='referee3@example.com'))
ref_m = ref_in(res)
sid_m = sub_meta(ref_m, [])['id']
_, _, eh, _ = admin.get(f'/wp-admin/post.php?post={sid_m}&action=edit')
mn = re.search(r'name="action" value="qc_confirm_manual">.*?name="_wpnonce" value="([^"]+)"', eh.decode(), re.S)
st, _, body = admin.post(BASE + '/wp-admin/admin-post.php', [('action', 'qc_confirm_manual'), ('id', str(sid_m)), ('_wpnonce', mn.group(1)), ('note', '')])
check(status_of(ref_m) == 'qc_pending_confirm', 'manual confirm without a note refused')
st, _, body = admin.post(BASE + '/wp-admin/admin-post.php', [('action', 'qc_confirm_manual'), ('id', str(sid_m)), ('_wpnonce', mn.group(1)), ('note', 'Referee confirmed by phone')])
m = sub_meta(ref_m, ['_qc_confirmation', '_qc_log'])
check(m['status'] == 'qc_submitted' and 'Referee confirmed by phone' in m['_qc_log'] and json.loads(m['_qc_confirmation']).get('method') == 'manual', 'manual confirm with note → submitted, logged')

# ---- Reminders -------------------------------------------------------------
print('\n# Reminders')
res, _ = submit(anon, BPAGE, BURSARY, bursary_fields(name='Remind Me', email='rem@example.com', referee_email='referee4@example.com'))
ref_r = ref_in(res)
first = confirm_link('referee4@example.com')
php(f"Qhta_Competitions_Program_CPT::save_config({BURSARY}, array('closes_at'=>gmdate('Y-m-d H:i:s', time()+86400)));")
mailpit_clear()
n1 = php('echo Qhta_Competitions_Confirm::send_reminders();')
time.sleep(0.5)
rem = [x for x in mailpit_messages() if 'referee4@example.com' in [t['Address'] for t in x['To']] and x['Subject'].startswith('Reminder')]
check(int(n1 or 0) >= 1 and bool(rem), f'round closing within 48h → reminder sent ({n1})')
n2 = php('echo Qhta_Competitions_Confirm::send_reminders();')
check(n2 == '0', 'reminders are sent once')
rlink = confirm_link('referee4@example.com')
check(bool(rlink) and rlink != first, 'reminder carries a fresh link')
set_window(BURSARY, -3600, 30 * 86400)
check(php("echo wp_next_scheduled('qc_cron') ? 'yes' : 'no';") == 'yes', 'hourly qc_cron scheduled')

# ---- Eligibility -----------------------------------------------------------
print('\n# Eligibility')
res, _ = submit(anon, BPAGE, BURSARY, bursary_fields(name='No Seq', email='noseq@example.com', seq=False))
check(res and 'open only to teachers who live and work outside South-East Queensland' in res[2] and 'qc_done' not in res[1], 'unticked "outside SEQ" blocks submit with the round\'s message')

member = Client().login('member1', 'member1pass')
res, _ = submit(member, BPAGE, BURSARY, bursary_fields(name='Mem Ber', email='member1@example.com'))
r = ref_in(res)
mc = json.loads(sub_meta(r, ['_qc_member_check'])['_qc_member_check'] or '{}') if r else {}
fl = json.loads(sub_meta(r, ['_qc_flags'])['_qc_flags'] or '[]') if r else []
check(mc.get('status') == 'yes' and not any(f['code'].startswith('membership_') for f in fl), 'PMPro member → ✓, no membership flag')

nonmember = Client().login('nonmember1', 'nonmember1pass')
res, _ = submit(nonmember, BPAGE, BURSARY, bursary_fields(name='Non Mem', email='nonmember1@example.com'))
r = ref_in(res)
mc = json.loads(sub_meta(r, ['_qc_member_check'])['_qc_member_check'] or '{}') if r else {}
check(mc.get('status') == 'no' and mc.get('source') == 'pmpro', 'logged-in non-member → ✗ (flagged, not blocked)')

res, _ = submit(anon, BPAGE, BURSARY, bursary_fields(name='Anon Ymous', email='anon@example.com'))
r = ref_in(res)
mc = json.loads(sub_meta(r, ['_qc_member_check'])['_qc_member_check'] or '{}') if r else {}
check(mc.get('status') == 'unknown' and mc.get('source') == 'logged_out', 'logged out → ? (manual check)')

res, _ = submit(member, BPAGE, BURSARY, bursary_fields(name='Inst Itution', email='inst@example.com', membership='institutional'))
r = ref_in(res)
mc = json.loads(sub_meta(r, ['_qc_member_check'])['_qc_member_check'] or '{}') if r else {}
check(mc.get('status') == 'unknown' and mc.get('source') == 'institutional', 'institutional → ? even for a logged-in member')
sid_i = sub_meta(r, [])['id'] if r else 0
_, _, lst, _ = admin.get('/wp-admin/edit.php?post_type=qhta_submission')
check('Member?' in lst.decode() and 'qc-member' in lst.decode(), 'list has Member? column')

res, _ = submit(anon, BPAGE, BURSARY, bursary_fields(name='Same Email', email='same@example.com', referee_email='SAME@example.com'))
r = ref_in(res)
fl = json.loads(sub_meta(r, ['_qc_flags'])['_qc_flags'] or '[]') if r else []
check(bool(r) and any(f['code'] == 'confirmer_same_email' for f in fl), 'referee email = applicant email → accepted, flagged')

# Admin checks: QCT verified + membership override
_, _, eh, _ = admin.get(f'/wp-admin/post.php?post={sid_i}&action=edit')
eh = eh.decode()
ck = re.search(r'name="action" value="qc_checks">.*?name="_wpnonce" value="([^"]+)"', eh, re.S)
check(bool(ck) and 'QCT registration number verified' in eh, 'admin checks box with "QCT registration number verified"')
admin.post(BASE + '/wp-admin/admin-post.php', [('action', 'qc_checks'), ('id', str(sid_i)), ('_wpnonce', ck.group(1)), ('member', 'yes'), ('verified[qct_number]', '1')])
mm = json.loads(php(f"echo get_post_meta({sid_i}, '_qc_member_check', true);") or '{}')
check(mm.get('status') == 'yes' and mm.get('source') == 'admin' and php(f"echo get_post_meta({sid_i}, '_qc_verified_qct_number', true);") == '1', 'admin can verify membership and QCT (logged)')
check('membership_' not in php(f"echo get_post_meta({sid_i}, '_qc_flags', true);"), 'membership flag cleared once verified')

# ---- Entry limits ------------------------------------------------------------
print('\n# Entry limits')
res, _ = submit(anon, BPAGE, BURSARY, bursary_fields(name='Limit Person', email='lim@example.com', school='Barcaldine SS'))
first_ref = ref_in(res)
check(bool(first_ref), 'bursary: first application accepted (limit 1 per entrant in total)')
res, _ = submit(anon, BPAGE, BURSARY, bursary_fields(name='  limit   PERSON ', email='lim2@example.com', school='Barcaldine S.S.'))
check(res and 'already received an application from you' in res[2] and 'qc_done' not in res[1], '2nd application (same name/school, different case/punctuation) blocked')
sid_f = sub_meta(first_ref, [])['id']
php(f"Qhta_Competitions_Submission_CPT::transition({sid_f}, 'qc_withdrawn', 'test');")
res, _ = submit(anon, BPAGE, BURSARY, bursary_fields(name='Limit Person', email='lim@example.com', school='Barcaldine SS'))
check(bool(ref_in(res)), 'withdrawn entries do not count')

# Writing comp: 1 per student per category.
comp_base = [
    ('qc_f[student_name]', 'Lim Student'), ('qc_f[year_level]', '11'), ('qc_f[school_name]', 'Toowoomba SHS'),
    ('qc_f[teacher_name]', 'T'), ('qc_f[teacher_email]', 't@example.com'), ('qc_f[teacher_phone]', '07'), ('qc_f[principal_name]', 'P'),
    ('qc_f[school_address][line1]', '3 Rd'), ('qc_f[school_address][suburb]', 'Toowoomba'), ('qc_f[school_address][state]', 'QLD'), ('qc_f[school_address][postcode]', '4350'),
    ('qc_decl[original_work]', '1'), ('qc_decl[judges_final]', '1'), ('qc_decl[publication_consent]', '1'), ('qc_decl[permission]', '1'),
    ('qc_signature', 'T'), ('qc_f[word_count_declared]', '1500'),
]
pdf = ('e.pdf', b'%PDF-1.4\n%%EOF\n', 'application/pdf')
res, _ = submit(anon, CPAGE, COMP, comp_base + [('qc_category', 'y11-modern-essay')], {'qc_file_entry_file': pdf})
check(bool(ref_in(res)), 'comp: first entry in category accepted')
res, _ = submit(anon, CPAGE, COMP, comp_base + [('qc_category', 'y11-modern-essay')], {'qc_file_entry_file': pdf})
check(res and 'already has an entry in Year 11 Modern History – Essay' in htmllib.unescape(res[2]), 'comp: (limit+1)th entry in the same category blocked')
res, _ = submit(anon, CPAGE, COMP, comp_base + [('qc_category', 'y11-ancient-essay')], {'qc_file_entry_file': pdf})
check(bool(ref_in(res)), 'comp: same student, different category accepted')

# Test entries are counted separately from real ones.
set_mode(CKEY, 'preview')
res, _ = submit(admin, CPAGE, COMP, comp_base + [('qc_category', 'y11-modern-essay')], {'qc_file_entry_file': pdf})
check(bool(ref_in(res, test=True)), 'preview: test entry not blocked by real entries')
res, _ = submit(admin, CPAGE, COMP, comp_base + [('qc_category', 'y11-modern-essay')], {'qc_file_entry_file': pdf})
check(res and 'already has an entry' in res[2], 'preview: limit applies among test entries (so it can be tried before launch)')
set_mode(CKEY, 'on')

summary()
