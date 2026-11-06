#!/usr/bin/env python3
"""M5 end-to-end smoke test: outcome emails, exports, retention, privacy, Health.

DISPOSABLE SITES ONLY. Needs Mailpit. Run tests/m1-smoke.php setup first.

    BASE=http://localhost:8089 WP="docker exec qctest-cli-1 wp" \\
    MAILPIT=http://localhost:8025 python3 tests/m5-http-smoke.py
"""
import csv
import io
import json
import re
import time
import urllib.parse

from qc_http import *

if not MAILPIT:
    print('MAILPIT is required.')
    sys.exit(2)

BURSARY = int(php("echo Qhta_Competitions_Program_CPT::find('ecc-bursary-2027')->ID;"))
COMP = int(php("echo Qhta_Competitions_Program_CPT::find('renamed-writing-comp')->ID;"))
php(f"wp_update_post(array('ID'=>{BURSARY},'post_status'=>'publish'));")
BKEY, CKEY = 'comp-ecc-bursary-2027', 'comp-writing-competition-2026-demo'
BPAGE = ensure_page('ecc-bursary', '[qhta_program_form program="ecc-bursary-2027"]')
CPAGE = ensure_page('writing-competition', f'[qhta_program_form program="{COMP}"]')
for k in ('competitions', BKEY, CKEY):
    set_mode(k, 'on')
set_window(BURSARY, -3600, 30 * 86400)
set_window(COMP, -3600, 30 * 86400)
php("delete_option('qc_outcome_queue'); wp_clear_scheduled_hook('qc_outcome_batch');")
admin = Client().login('admin', 'admin')
anon = Client()
mailpit_clear()


def move(sid, *statuses):
    for s in statuses:
        php(f"Qhta_Competitions_Submission_CPT::transition({sid}, '{s}', 'test');")
    return sid


def confirm(ref):
    sid = sub_meta(ref, [], BURSARY)['id']
    php(f"Qhta_Competitions_Confirm::complete({sid}, array('method'=>'manual','admin_note'=>'test'), 'test');")
    return sid


def bulk(action, ids):
    _, _, h, _ = admin.get('/wp-admin/edit.php?post_type=qhta_submission')
    n = re.search(r'id="_wpnonce" name="_wpnonce" value="([^"]+)"', h.decode()).group(1)
    q = [('post_type', 'qhta_submission'), ('action', action), ('_wpnonce', n)] + [('post[]', str(i)) for i in ids]
    return admin.get('/wp-admin/edit.php?' + urllib.parse.urlencode(q))


def form_nonce(html, action):
    m = re.search(r'name="action" value="%s">.*?name="_wpnonce" value="([^"]+)"' % re.escape(action), html, re.S)
    return m.group(1) if m else ''


def mails_to(addr):
    return [m for m in mailpit_messages() if addr in [t['Address'] for t in m['To']]]


# ---- Entries -------------------------------------------------------------------
print('\n# Entries')
B = {}
for key, name in (('award', 'Award Winner'), ('commend', 'Commended One'), ('fail', 'Not This Time'), ('forfeit', 'Can Not Attend')):
    res, _ = submit(anon, BPAGE, BURSARY, bursary_fields(name=name, email=f'{key}@example.com', school=f'{key.title()} SS', referee_email=f'ref-{key}@example.com'))
    B[key] = ref_in(res)
set_mode(BKEY, 'preview')
res, _ = submit(admin, BPAGE, BURSARY, bursary_fields(name='Test Person', email='testperson@example.com', school='Test SS'))
B['test'] = ref_in(res, test=True)
set_mode(BKEY, 'on')
check(all(B.values()), f'bursary entries created {B}')
BID = {k: confirm(v) for k, v in B.items()}
move(BID['award'], 'qc_in_review', 'qc_shortlisted', 'qc_awarded')
move(BID['commend'], 'qc_in_review', 'qc_shortlisted', 'qc_commended')
move(BID['fail'], 'qc_in_review', 'qc_unsuccessful')
php(f"Qhta_Competitions_Submission_CPT::transition({BID['test']}, 'qc_in_review', 'x'); Qhta_Competitions_Submission_CPT::transition({BID['test']}, 'qc_unsuccessful', 'x');")

comp_base = [
    ('qc_f[year_level]', '11'), ('qc_f[teacher_name]', 'Terry Teacher'), ('qc_f[teacher_email]', 'terry@example.com'), ('qc_f[teacher_phone]', '+61711111111'),
    ('qc_f[principal_name]', 'Pip Principal'), ('qc_f[school_address][line1]', '3 Rd'), ('qc_f[school_address][suburb]', 'Brisbane'), ('qc_f[school_address][state]', 'QLD'), ('qc_f[school_address][postcode]', '4000'),
    ('qc_decl[original_work]', '1'), ('qc_decl[judges_final]', '1'), ('qc_decl[publication_consent]', '1'), ('qc_decl[permission]', '1'), ('qc_signature', 'Terry Teacher'), ('qc_f[word_count_declared]', '1500'),
]
pdf = ('entry.pdf', b'%PDF-1.4\n%%EOF\n', 'application/pdf')
C = {}
for key, name in (('win', 'Winnie Writer'), ('hc', 'Hannah Commended'), ('lose', '=HYPERLINK("http://evil","x")')):
    res, _ = submit(anon, CPAGE, COMP, comp_base + [('qc_f[student_name]', name), ('qc_f[school_name]', 'Brisbane State High'), ('qc_category', 'y11-modern-essay')], {'qc_file_entry_file': pdf})
    C[key] = ref_in(res)
check(all(C.values()), f'comp entries created {C}')
CID = {k: sub_meta(v, [], COMP)['id'] for k, v in C.items()}
move(CID['win'], 'qc_in_review', 'qc_shortlisted', 'qc_awarded')
move(CID['hc'], 'qc_in_review', 'qc_shortlisted', 'qc_commended')
move(CID['lose'], 'qc_in_review', 'qc_unsuccessful')

# ---- Outcome emails -----------------------------------------------------------------
print('\n# Outcome emails')
ids = [BID['award'], BID['commend'], BID['fail'], BID['test'], BID['forfeit']]
_, url, h, _ = bulk('qc_notify', ids)
h = h.decode()
check('page=qc-notify' in url, 'bulk "Notify outcome" opens the preview page')
check('QHTA will arrange your free conference registration' in h, 'award email shows the manual-registration text')
check('congratulations' in h.lower() and 'commended your application' in h and 'not successful this time' in h, 'previews for awarded, commended and unsuccessful')
check(B['test'] + ' — test entry' in h and B['forfeit'] + ' — no final outcome yet' in h, 'test entry and undecided entry listed as not emailed')
check('disabled="disabled"' in h and '3 will be emailed' in h, 'send button disabled until a test is sent; 3 to send')
st, _, body = admin.post(BASE + '/wp-admin/admin-post.php', [('action', 'qc_notify_queue'), ('_wpnonce', form_nonce(h, 'qc_notify_queue')), ('resend', '0')])
check('Send yourself a test' in body and php("echo count((array) get_option('qc_outcome_queue',array()));") == '0', 'queueing without a test is refused')
st, _, body = admin.post(BASE + '/wp-admin/admin-post.php', [('action', 'qc_notify_test'), ('_wpnonce', form_nonce(h, 'qc_notify_test')), ('resend', '0')])
time.sleep(0.5)
prev = [m for m in mails_to('a@example.com') if m['Subject'].startswith('[PREVIEW]')]
check(len(prev) == 3, f'test-send: 3 preview emails to the admin ({len(prev)})')
check(not mails_to('award@example.com') or not any('congratulations' in m['Subject'].lower() for m in mails_to('award@example.com')), 'nothing sent to applicants yet')
_, _, h, _ = admin.get('/wp-admin/edit.php?post_type=qhta_program&page=qc-notify')
h = h.decode()
admin.post(BASE + '/wp-admin/admin-post.php', [('action', 'qc_notify_queue'), ('_wpnonce', form_nonce(h, 'qc_notify_queue')), ('resend', '0')])
q = json.loads(php("echo wp_json_encode(get_option('qc_outcome_queue'));") or '[]')
check(sorted(q) == sorted([BID['award'], BID['commend'], BID['fail']]), f'3 queued ({q})')
check(php("echo wp_next_scheduled('qc_outcome_batch') ? 'y':'n';") == 'y', 'batch scheduled on WP-Cron')
php('Qhta_Competitions_Outcomes::run_batch();')
time.sleep(0.5)
aw = [m for m in mails_to('award@example.com') if 'congratulations' in m['Subject'].lower()]
check(len(aw) == 1 and 'QHTA will arrange your free conference registration' in mailpit_get(aw[0]['ID'])['Text'], 'awardee gets the award email with manual-registration text')
check(any('outcome' in m['Subject'].lower() for m in mails_to('fail@example.com')), 'unsuccessful applicant notified')
check(not any('outcome' in m['Subject'].lower() for m in mails_to('testperson@example.com')), 'test entry not sent an outcome email')
check(json.loads(php(f"echo get_post_meta({BID['award']}, '_qc_outcome_notified', true);") or '{}').get('status') == 'qc_awarded', 'notified status recorded')
_, _, h, _ = bulk('qc_notify', [BID['award']])
check('already notified of this outcome' in h.decode(), 're-selecting skips entries already notified')

# Batching: 25 queued → 20 then 5.
fake = []
for i in range(25):
    fake.append(int(php(f"$id=wp_insert_post(array('post_type'=>'qhta_submission','post_status'=>'qc_unsuccessful','post_parent'=>{COMP},'post_title'=>'b{i}')); update_post_meta($id,'_qc_ref','QC-BATCH-{i:02d}'); update_post_meta($id,'_qc_submitter_email','batch{i}@example.com'); echo $id;")))
php(f"Qhta_Competitions_Outcomes::enqueue(array({','.join(map(str, fake))}));")
s1 = php('echo Qhta_Competitions_Outcomes::run_batch();')
left = php("echo count(get_option('qc_outcome_queue'));")
s2 = php('echo Qhta_Competitions_Outcomes::run_batch();')
check(s1 == '20' and left == '5' and s2 == '5', f'batches of 20: sent {s1}, then {s2} (left between: {left})')
for i in fake:
    php(f"wp_delete_post({i}, true);")

# ---- Exports -------------------------------------------------------------------------
print('\n# Exports')
_, _, h, _ = admin.get('/wp-admin/edit.php?post_type=qhta_program&page=qc-exports')
links = [l.replace('&#038;', '&').replace('&amp;', '&') for l in re.findall(r'href="([^"]*action=qc_export[^"]*)"', h.decode())]
link = lambda pidv, typ, pii: next(l for l in links if f'program={pidv}' in l and f'type={typ}' in l and f'pii={pii}' in l)
code, _, body, hdr = admin.get(link(COMP, 'entries', 0))
check(code == 200 and body.startswith(b'\xef\xbb\xbf') and b'\r\n' in body and 'text/csv' in hdr.get('Content-Type', ''), 'entries CSV: UTF-8 BOM, CRLF, text/csv')
rows = list(csv.reader(io.StringIO(body.decode('utf-8-sig'))))
check(len({len(r) for r in rows}) == 1, f'every row has the same number of columns ({len(rows[0])})')
text = body.decode('utf-8-sig')
check(all(C[k] in text for k in C) and 'TEST-' not in text, 'real entries in, test entries out')
check('Winnie' not in text and 'terry@example.com' not in text and 'Brisbane State High' not in text, 'no personal details without the PII option')
check('Rank' in rows[0] and 'Publication consent' in rows[0] and 'Declaration: publication_consent' in rows[0], 'columns include declarations, consent and judging')
code, _, body, _ = admin.get(link(COMP, 'entries', 1))
text = body.decode('utf-8-sig')
check('Winnie Writer' in text and 'terry@example.com' in text and 'Terry Teacher' in text, 'PII export includes names, emails, typed signature')
check('"\'=HYPERLINK(""http://evil"",""x"")"' in text and "'+61711111111" in text, 'formula-like cells neutralised with a leading apostrophe')
code, _, body, _ = admin.get(link(COMP, 'consent', 0))
cr = list(csv.reader(io.StringIO(body.decode('utf-8-sig'))))
refs = [r[0] for r in cr[1:]]
check(sorted(refs) == sorted([C['win'], C['hc']]) and 'Winnie Writer' in body.decode('utf-8-sig'), f'consent register: awarded + commended with consent only ({refs})')
code, _, body, _ = admin.get(link(BURSARY, 'entries', 0))
check(code == 200 and all(B[k] in body.decode('utf-8-sig') for k in ('award', 'commend', 'fail')), 'bursary export works too')
if php("echo get_user_by('login','judge1') ? 1 : 0;") == '1':
    php("$u=get_user_by('login','judge1'); $u->add_role('qhta_judge');")
    j = Client().login('judge1', 'judge1pass')
    code, _, _, _ = j.get(link(COMP, 'entries', 1))
    check(code == 403, f'judge cannot export, even with the link ({code})')
    code, _, _, _ = j.get('/wp-admin/edit.php?post_type=qhta_program&page=qc-exports')
    check(code == 403, f'judge cannot open Exports ({code})')

# ---- Retention -------------------------------------------------------------------------
print('\n# Retention')
php(f"Qhta_Competitions_Program_CPT::save_config({COMP}, array('opens_at'=>gmdate('Y-m-d H:i:s', strtotime('-14 months')), 'closes_at'=>gmdate('Y-m-d H:i:s', strtotime('-13 months')), 'retention_months'=>12));")
prev = {r['program']: r for r in json.loads(php('echo wp_json_encode(Qhta_Competitions_Retention::preview());'))}
pc, pb = prev[COMP], prev[BURSARY]
all_comp = json.loads(php(f"echo wp_json_encode(get_posts(array('post_type'=>'qhta_submission','post_status'=>array_keys(Qhta_Competitions_Submission_CPT::statuses()),'post_parent'=>{COMP},'posts_per_page'=>-1,'fields'=>'ids')));"))
kept = {CID['win'], CID['hc']}
check(pc['is_due'] and set(pc['ids']) == set(all_comp) - kept and pc['kept'] == 2, f"dry run: due; lists every comp entry except awarded/commended ({len(pc['ids'])} to purge, 2 kept)")
exp_b = set(json.loads(php(f"echo wp_json_encode(get_posts(array('post_type'=>'qhta_submission','post_status'=>array_diff(array_keys(Qhta_Competitions_Submission_CPT::statuses()),array('qc_awarded','qc_commended')),'post_parent'=>{BURSARY},'posts_per_page'=>-1,'fields'=>'ids')));")))
check(not pb['is_due'] and set(pb['ids']) == exp_b and BID['fail'] in exp_b and BID['award'] not in exp_b, f'dry run: bursary not due; would purge every entry except awarded/commended ({len(exp_b)})')
_, _, h, _ = admin.get('/wp-admin/edit.php?post_type=qhta_program&page=qc-retention')
h = h.decode()
check(f"{len(pb['ids'])} entries will be purged on" in h and 'Purge now' in h, 'Retention page: "X entries will be purged on {date}" and a purge button for due rounds')
n = re.search(r'name="program" value="%d">.*?name="_wpnonce" value="([^"]+)"' % COMP, h, re.S).group(1)
admin.post(BASE + '/wp-admin/admin-post.php', [('action', 'qc_retention_run'), ('program', str(COMP)), ('_wpnonce', n)])
check(php(f"echo metadata_exists('post', {CID['lose']}, '_qc_purged') ? 'y':'n';") == 'n', 'purge needs the confirmation tick')
admin.post(BASE + '/wp-admin/admin-post.php', [('action', 'qc_retention_run'), ('program', str(COMP)), ('_wpnonce', n), ('confirm', '1')])
lose = CID['lose']
gone = json.loads(php(f"$id={lose}; echo wp_json_encode(array('name'=>get_post_meta($id,'_qc_f_student_name',true),'school'=>get_post_meta($id,'_qc_f_school_name',true),'email'=>get_post_meta($id,'_qc_submitter_email',true),'files'=>Qhta_Competitions_Files::get($id),'sig'=>json_decode(get_post_meta($id,'_qc_declarations_accepted',true),true)['typed_name'],'ip'=>json_decode(get_post_meta($id,'_qc_declarations_accepted',true),true)['ip'],'ref'=>get_post_meta($id,'_qc_ref',true),'words'=>get_post_meta($id,'_qc_f_word_count_declared',true),'purged'=>get_post_meta($id,'_qc_purged',true),'log'=>get_post_meta($id,'_qc_log',true)));"))
check(gone['name'] == '' and gone['school'] == '' and gone['email'] == '' and gone['files'] == [] and gone['sig'] == '' and gone['ip'] == '', 'purged: names, school, email, files, signature and IP removed')
check(gone['ref'] == C['lose'] and gone['words'] == '1500' and 'purged (retention (manual))' in gone['log'], 'purged: reference and non-personal answers kept; purge logged')
kept_name = php(f"echo get_post_meta({CID['win']}, '_qc_f_student_name', true);")
check(kept_name == 'Winnie Writer' and php(f"echo count(Qhta_Competitions_Files::get({CID['win']}));") == '1', 'awarded entry kept with name and file')
check(php(f"echo count(Qhta_Competitions_Retention::candidates({COMP}));") == '0', 'dry run afterwards: nothing left to purge')
set_window(COMP, -3600, 30 * 86400)

# ---- Privacy tools --------------------------------------------------------------------------
print('\n# Privacy exporter / eraser')
exp = json.loads(php("echo wp_json_encode(Qhta_Competitions_Retention::export('AWARD@example.com'));"))
check(len(exp['data']) == 1 and any(d['value'] == 'Award Winner' for d in exp['data'][0]['data']), 'exporter finds the applicant by email (case-insensitive)')
ref_exp = json.loads(php("echo wp_json_encode(Qhta_Competitions_Retention::export('ref-commend@example.com'));"))
check(any(i['group_id'] == 'qhta-competitions-referee' for i in ref_exp['data']), 'exporter finds referees too')
er = json.loads(php("echo wp_json_encode(Qhta_Competitions_Retention::erase('award@example.com'));"))
check(er['items_retained'] is True and not er['items_removed'] and er['messages'], 'eraser keeps awarded entries and says so')
er = json.loads(php("echo wp_json_encode(Qhta_Competitions_Retention::erase('fail@example.com'));"))
check(er['items_removed'] is True and php(f"echo get_post_meta({BID['fail']}, '_qc_f_full_name', true);") == '', 'eraser purges an unsuccessful applicant')
check('privacy' in php("echo wp_json_encode(array_keys(apply_filters('wp_privacy_personal_data_erasers', array())));") or 'qhta-competitions' in php("echo wp_json_encode(array_keys(apply_filters('wp_privacy_personal_data_erasers', array())));"), 'registered with WordPress privacy tools')

# ---- Health ----------------------------------------------------------------------------------
print('\n# Health')
php("do_action('qc_cron');")
_, _, h, _ = admin.get('/wp-admin/edit.php?post_type=qhta_program&page=qc-health')
h = h.decode()
row = lambda label: re.search(r'<tr class="qc-health--(\w+)">.*?<th>%s</th><td>(.*?)</td>' % re.escape(label), h, re.S)
up = row('Uploads protected')
check(bool(up) and up.group(1) == 'ok' and '403' in up.group(2), f"uploads self-test: {up.group(1) if up else '?'} — {up.group(2) if up else ''}")
cr_ = row('Scheduled tasks')
check(bool(cr_) and cr_.group(1) == 'ok', f"cron check: {cr_.group(1) if cr_ else '?'}")
ff = row('Feature flags')
check(bool(ff) and ff.group(1) == 'ok', 'feature flags check ok')
mailpit_clear()
admin.post(BASE + '/wp-admin/admin-post.php', [('action', 'qc_mail_test'), ('_wpnonce', form_nonce(h, 'qc_mail_test'))])
time.sleep(0.5)
check(any(m['Subject'] == 'QHTA Competitions mail test' for m in mails_to('a@example.com')), 'mail test arrives')
php("update_option('wpff_modes', array_merge(get_option('wpff_modes',array()), array('competitions'=>'off')), false);")
_, _, h, _ = admin.get('/wp-admin/edit.php?post_type=qhta_program&page=qc-health')
check('qc-health--warn' in h.decode(), 'competitions flag off shows as a warning')
set_mode('competitions', 'on')

summary()
