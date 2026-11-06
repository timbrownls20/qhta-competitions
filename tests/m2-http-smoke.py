#!/usr/bin/env python3
"""M2 end-to-end smoke test over HTTP: flag matrix, submissions, uploads, emails.

DISPOSABLE SITES ONLY — it flips flags, rewrites round dates, deactivates
wordpress-feature-flags for one check and creates submissions/pages/users.

Prerequisites: tests/m1-smoke.php setup has been run (rounds exist), the site
uses WPFF_PREVIEW_TOKEN, and mail goes to Mailpit (optional; skipped without).

    BASE=http://localhost:8089 WP="docker exec qctest-cli-1 wp" \\
    MAILPIT=http://localhost:8025 PREVIEW_TOKEN=localtesttoken123 \\
    python3 tests/m2-http-smoke.py
"""
import json
import os
import re
import time

from qc_http import *

# --------------------------------------------------------------------------
BURSARY = int(php("echo Qhta_Competitions_Program_CPT::find('ecc-bursary-2027')->ID;"))
COMP = int(php("echo Qhta_Competitions_Program_CPT::find('renamed-writing-comp')->ID;"))
php(f"wp_update_post(array('ID'=>{BURSARY},'post_status'=>'publish'));")
BKEY, CKEY = 'comp-ecc-bursary-2027', 'comp-writing-competition-2026-demo'
BPAGE = ensure_page('ecc-bursary', '[qhta_program_form program="ecc-bursary-2027"]')
CPAGE = ensure_page('writing-competition', f'[qhta_program_form program="{COMP}"]')
if not php("echo get_user_by('login','subscriber1') ? 1 : 0;") == '1':
    wp('user', 'create', 'subscriber1', 'sub1@example.com', '--role=subscriber', '--user_pass=sub1pass')

admin = Client().login('admin', 'admin')
anon = Client()
set_mode('competitions', 'on')

# ---- Flag matrix ---------------------------------------------------------
print('\n# Flag matrix (bursary page)')
set_window(BURSARY, -3600, 30 * 86400)

wp('plugin', 'deactivate', 'wordpress-feature-flags')
_, _, h, _ = anon.get(BPAGE); h = h.decode()
check('class="qc-form' not in h, 'wpff inactive: anon sees nothing')
_, _, h, _ = admin.get(BPAGE); h = h.decode()
check('wordpress-feature-flags is inactive' in h and '<form method="post"' not in h, 'wpff inactive: admin sees admin-only notice, no form')
wp('plugin', 'activate', 'wordpress-feature-flags')

set_mode('competitions', 'off'); set_mode(BKEY, 'on')
for who, c in (('anon', anon), ('admin', admin)):
    _, _, h, _ = c.get(BPAGE); h = h.decode()
    check('temporarily unavailable' in h and '<form method="post"' not in h, f'competitions off: {who} sees "temporarily unavailable"')
set_mode('competitions', 'on')

set_mode(BKEY, 'off')
for who, c in (('anon', anon), ('admin', admin)):
    _, _, h, _ = c.get(BPAGE); h = h.decode()
    check('class="qc-form' not in h, f'round off: {who} sees nothing')

set_mode(BKEY, 'preview')
_, _, h, _ = anon.get(BPAGE); h = h.decode()
check('class="qc-form' not in h, 'round preview: logged-out sees nothing')
_, _, h, _ = admin.get(BPAGE); h = h.decode()
check('PREVIEW — test entry' in h and '<form method="post"' in h, 'round preview: admin sees form with PREVIEW banner')
secret = Client()
if TOKEN:
    secret.get(f'/?wpff-preview={BKEY}:{TOKEN}')
    _, _, h, _ = secret.get(BPAGE); h = h.decode()
    check('PREVIEW — test entry' in h and '<form method="post"' in h, 'round preview: secret-link visitor sees form with PREVIEW banner')
else:
    print('SKIP secret link (no PREVIEW_TOKEN)')

set_mode(BKEY, 'on')
_, _, h, hdr = anon.get(BPAGE); h = h.decode()
check('<form method="post"' in h and 'PREVIEW' not in h, 'round on + open: anon sees form, no banner')
check('no-cache' in hdr.get('X-LiteSpeed-Cache-Control', '') and 'no-cache' in hdr.get('Cache-Control', ''), 'form page sends no-cache + X-LiteSpeed-Cache-Control')
check('assets/js/form.js' in h and 'assets/css/form.css' in h, 'assets enqueued on form page')
_, _, h2, _ = anon.get('/?p=1'); h2 = h2.decode()
check('qhta-competitions/assets' not in h2, 'assets not enqueued on other pages')
set_window(BURSARY, 3600, 30 * 86400)
_, _, h, _ = anon.get(BPAGE); h = h.decode()
check('Submissions open' in h and '<form method="post"' not in h, 'round on + before open: "opens {date}"')
set_window(BURSARY, -30 * 86400, -60)
_, _, h, _ = anon.get(BPAGE); h = h.decode()
check('have closed' in h and '<form method="post"' not in h, 'round on + after close: closed message')

# ---- Bursary submissions ---------------------------------------------------
print('\n# Bursary submissions')
mailpit_clear()
piece = ' '.join(['Teaching history in the bush brings its own rewards and challenges.'] * 20)  # 220 words
bursary_fields = [
    ('qc_category', 'ecc-bursary'),
    ('qc_f[title]', 'Ms'), ('qc_f[full_name]', 'Jane Tester'),
    ('qc_f[home_address][line1]', '1 Main St'), ('qc_f[home_address][suburb]', 'Longreach'), ('qc_f[home_address][state]', 'QLD'), ('qc_f[home_address][postcode]', '4730'),
    ('qc_f[email]', 'jane@example.com'), ('qc_f[phone]', '0400000000'), ('qc_f[years_teaching]', '2 years'),
    ('qc_f[school_name]', 'Longreach State High'),
    ('qc_f[school_address][line1]', '2 School Rd'), ('qc_f[school_address][suburb]', 'Longreach'), ('qc_f[school_address][state]', 'QLD'), ('qc_f[school_address][postcode]', '4730'),
    ('qc_f[qct_number]', '123456'), ('qc_f[membership_type]', 'individual'), ('qc_f[outside_seq]', '1'),
    ('qc_f[written_piece]', piece),
    ('qc_f[referee_name]', 'Pat Principal'), ('qc_f[referee_position]', 'Principal'), ('qc_f[referee_email]', 'pat@example.com'),
    ('qc_decl[accept_terms]', '1'), ('qc_decl[attend]', '1'), ('qc_decl[receipts]', '1'), ('qc_decl[return_if_absent]', '1'), ('qc_decl[committee_final]', '1'),
    ('qc_signature', 'Jane Tester'),
]

# Preview submission BEFORE opens_at, via secret link → TEST- entry.
set_mode(BKEY, 'preview')
set_window(BURSARY, 7 * 86400, 30 * 86400)
if TOKEN:
    res, _ = submit(secret, BPAGE, BURSARY, bursary_fields)
    test_ref = re.search(r'<strong>(TEST-QC-\d{4}-\d{4})</strong>', res[2]) if res else None
    check(bool(test_ref), 'preview submission before opens_at → thank-you with TEST- ref')
    if test_ref:
        m = sub_meta(test_ref.group(1), ['_qc_is_test', '_qc_f_full_name', '_qc_word_count_auto'])
        check(m.get('_qc_is_test') == '1' and m.get('status') == 'qc_pending_confirm', 'test entry saved: _qc_is_test=1, status awaiting confirmation (referee round)')
        check(m.get('_qc_word_count_auto') == '220', 'inline piece counted server-side (220)')
        real = php("echo count(get_posts(array('post_type'=>'qhta_submission','post_status'=>array_keys(Qhta_Competitions_Submission_CPT::statuses()),'posts_per_page'=>-1,'fields'=>'ids','meta_query'=>array(Qhta_Competitions_Submission_CPT::real_entries_clause()))));")
        tests = php("echo count(get_posts(array('post_type'=>'qhta_submission','post_status'=>array_keys(Qhta_Competitions_Submission_CPT::statuses()),'posts_per_page'=>-1,'fields'=>'ids','meta_key'=>'_qc_is_test','meta_value'=>'1')));")
        ids_real = php("echo wp_json_encode(wp_list_pluck(get_posts(array('post_type'=>'qhta_submission','post_status'=>array_keys(Qhta_Competitions_Submission_CPT::statuses()),'posts_per_page'=>-1,'meta_query'=>array(Qhta_Competitions_Submission_CPT::real_entries_clause()))),'ID'));")
        check(str(m['id']) not in json.loads(ids_real or '[]'), f'test entry excluded by the real-entries query used for limits/judging/exports (tests={tests}, real={real})')

# Real submission, round on and open.
set_mode(BKEY, 'on')
set_window(BURSARY, -3600, 30 * 86400)
res, _ = submit(anon, BPAGE, BURSARY, bursary_fields)
real_ref = re.search(r'<strong>(QC-\d{4}-\d{4})</strong>', res[2]) if res else None
check(bool(real_ref) and 'qc_done=' in res[1], 'live submission → 303 to thank-you with QC- ref')
check(bool(res) and 'not complete until they confirm' in res[2], 'thank-you mentions referee confirmation')
if real_ref:
    m = sub_meta(real_ref.group(1), ['_qc_is_test', '_qc_declarations_accepted', '_qc_f_home_address', '_qc_submitter_email', '_qc_f_institution_name'])
    snap = json.loads(m.get('_qc_declarations_accepted') or '{}')
    check(m.get('_qc_is_test') == '' and m.get('status') == 'qc_pending_confirm', 'real entry: no test marker, awaiting confirmation')
    check(snap.get('typed_name') == 'Jane Tester' and len(snap.get('items', [])) == 5 and all(i['accepted'] for i in snap['items']) and snap.get('ip') and 'Teaching' not in json.dumps(snap), 'declarations snapshot: 5 texts, typed name, ts, IP')
    check(json.loads(m.get('_qc_f_home_address') or '{}').get('postcode') == '4730', 'address stored structured')
    check(m.get('_qc_submitter_email') == 'jane@example.com', 'submitter email resolved from role')
    check('_qc_f_institution_name' in m and m['_qc_f_institution_name'] == '', 'show_if field not stored when hidden')

# Validation: over 300 words blocks; unticked SEQ blocks (required checkbox); missing declaration; sticky values.
long_piece = ' '.join(['word'] * 301)
bad = [(k, v) for k, v in bursary_fields if k not in ('qc_f[written_piece]', 'qc_decl[attend]', 'qc_f[outside_seq]')] + [('qc_f[written_piece]', long_piece)]
res, _ = submit(anon, BPAGE, BURSARY, bad)
h = res[2] if res else ''
check('301 words; the limit is 300' in h, 'inline piece over 300 words blocked with count')
check(h.count('Please tick to agree') == 1, 'unticked declaration blocked')
check('qc_outside_seq_error' in h, 'unticked "outside SEQ" self-declaration blocked')
check('value="Jane Tester"' in h and 'qc_done' not in res[1], 'values sticky after error, no redirect')

res, _ = submit(anon, BPAGE, BURSARY, bursary_fields, wait=0)
check(res and 'very quick' in res[2], 'submitting faster than the minimum time is refused')
res, _ = submit(anon, BPAGE, BURSARY, bursary_fields + [('qc_website', 'http://spam')], wait=0.5)
check(res and 'could not be accepted' in res[2] and 'qc_done' not in res[1], 'honeypot filled → refused')

# ---- Writing comp: uploads + word counts -----------------------------------
print('\n# Writing competition uploads')
set_mode(CKEY, 'on')
set_window(COMP, -3600, 30 * 86400)
fix = lambda n: open(os.path.join(HERE, 'fixtures', n), 'rb').read()
DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
comp_base = [
    ('qc_f[student_name]', 'Sam Student'), ('qc_f[year_level]', '11'), ('qc_f[school_name]', 'Brisbane SHS'),
    ('qc_f[teacher_name]', 'Terry Teacher'), ('qc_f[teacher_email]', 'terry@example.com'), ('qc_f[teacher_phone]', '0711111111'),
    ('qc_f[principal_name]', 'Pip Principal'),
    ('qc_f[school_address][line1]', '3 Rd'), ('qc_f[school_address][suburb]', 'Brisbane'), ('qc_f[school_address][state]', 'QLD'), ('qc_f[school_address][postcode]', '4000'),
    ('qc_decl[original_work]', '1'), ('qc_decl[judges_final]', '1'), ('qc_decl[publication_consent]', '1'), ('qc_decl[permission]', '1'),
    ('qc_signature', 'Terry Teacher'),
]
exp = json.load(open(os.path.join(HERE, 'fixtures', 'expected.json')))

res, _ = submit(anon, CPAGE, COMP, comp_base + [('qc_category', 'y11-modern-essay'), ('qc_f[word_count_declared]', str(exp['footnotes-bibliography.docx']['total']))],
                {'qc_file_entry_file': ('Sam Student essay.docx', fix('footnotes-bibliography.docx'), DOCX)})
ref1 = re.search(r'<strong>(QC-\d{4}-\d{4})</strong>', res[2]) if res else None
check(bool(ref1), 'comp .docx entry accepted')
if ref1:
    m = sub_meta(ref1.group(1), ['_qc_word_count_auto', '_qc_word_count_breakdown', '_qc_flags', '_qc_files', '_qc_publication_consent'])
    check(m['status'] == 'qc_submitted', 'teacher-only round → status submitted (no confirmer)')
    check(m['_qc_word_count_auto'] == str(exp['footnotes-bibliography.docx']['total']), f"docx auto count {m['_qc_word_count_auto']} == expected {exp['footnotes-bibliography.docx']['total']}")
    codes1 = [f['code'] for f in json.loads(m['_qc_flags'])]
    check('word_count_mismatch' not in codes1 and 'word_count_under' in codes1, f'declared == auto → no mismatch flag; under the 1,000 minimum → flagged, not blocked ({codes1})')
    check(json.loads(m['_qc_publication_consent']).get('given') is True, 'publication consent recorded')
    files = json.loads(m['_qc_files'])
    rel = files[0]['path']
    check(re.match(r'^\d+/[a-f0-9]{32}\.docx$', rel) is not None, 'file stored under random name in program dir')
    st, _, _, _ = anon.get(f'/wp-content/uploads/qhta-competitions/{rel}')
    check(st == 403, f'direct file URL → 403 (got {st})')
    # Download via admin-post.
    sid = m['id']
    _, _, edit, _ = admin.get(f'/wp-admin/post.php?post={sid}&action=edit')
    edit = edit.decode()
    mlink = re.search(r'href="([^"]*admin-post\.php\?action=qc_file[^"]*)"', edit)
    check(bool(mlink), 'admin submission screen shows a download link')
    url = mlink.group(1).replace('&#038;', '&').replace('&amp;', '&') if mlink else BASE
    st, _, body, hdr = admin.get(url)
    check(st == 200 and body[:2] == b'PK' and f'filename="{ref1.group(1)}.docx"' in hdr.get('Content-Disposition', ''), 'admin download: 200, filename {ref}.docx (no entrant name)')
    st, _, _, _ = anon.get(url, follow=False)
    check(st in (302, 400, 403), f'anon download refused ({st})')
    sub = Client().login('subscriber1', 'sub1pass')
    st, _, _, _ = sub.get(url)
    check(st == 403, f'subscriber with copied link refused ({st})')
    can = php(f"wp_set_current_user(get_user_by('login','subscriber1')->ID); echo Qhta_Competitions_Files::user_can_download({sid}) ? 'yes' : 'no';")
    check(can == 'no', 'capability check: subscriber cannot download')
    can = php(f"wp_set_current_user(1); echo Qhta_Competitions_Files::user_can_download({sid}) ? 'yes' : 'no';")
    check(can == 'yes', 'capability check: admin can download')

second = [(k, 'Sasha Second' if k == 'qc_f[student_name]' else v) for k, v in comp_base]  # 1 entry per student per category
res, _ = submit(anon, CPAGE, COMP, second + [('qc_category', 'y11-modern-essay'), ('qc_f[word_count_declared]', '150')],
                {'qc_file_entry_file': ('e.docx', fix('endnotes-references.docx'), DOCX)})
ref2 = re.search(r'<strong>(QC-\d{4}-\d{4})</strong>', res[2]) if res else None
check(bool(ref2), 'second comp entry accepted (upload word count never blocks)')
if ref2:
    m = sub_meta(ref2.group(1), ['_qc_flags', '_qc_word_count_auto'])
    codes = [f['code'] for f in json.loads(m['_qc_flags'])]
    check(m['_qc_word_count_auto'] == str(exp['endnotes-references.docx']['total']), f"endnotes/References docx counted {m['_qc_word_count_auto']}")
    check('word_count_mismatch' in codes and 'word_count_under' in codes, f'declared 150 vs auto → mismatch + under-minimum flags ({codes})')

pdf = b'%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n'
res, _ = submit(anon, CPAGE, COMP, comp_base + [('qc_category', 'year-7'), ('qc_f[word_count_declared]', '900')],
                {'qc_file_entry_file': ('e.pdf', pdf, 'application/pdf')})
check(res and 'not open to Year 11' in res[2], 'category not matching year level is blocked')
fields_y7 = [f for f in comp_base if f[0] != 'qc_f[year_level]'] + [('qc_f[year_level]', '7'), ('qc_category', 'year-7'), ('qc_f[word_count_declared]', '900')]
res, _ = submit(anon, CPAGE, COMP, fields_y7, {'qc_file_entry_file': ('e.pdf', pdf, 'application/pdf')})
ref3 = re.search(r'<strong>(QC-\d{4}-\d{4})</strong>', res[2]) if res else None
check(bool(ref3), '.pdf entry accepted (declared count only)')
if ref3:
    m = sub_meta(ref3.group(1), ['_qc_word_count_auto', '_qc_flags'])
    check(m['_qc_word_count_auto'] == '' and json.loads(m['_qc_flags']) == [], 'pdf: no auto count, declared within limits → no flags')

res, _ = submit(anon, CPAGE, COMP, fields_y7, {'qc_file_entry_file': ('fake.pdf', b'just text, not a pdf', 'application/pdf')})
check(res and 'not a valid .docx or .pdf' in res[2], 'fake .pdf rejected by MIME/magic check')
res, _ = submit(anon, CPAGE, COMP, fields_y7, {'qc_file_entry_file': ('old.doc', b'\xd0\xcf\x11\xe0junk', 'application/msword')})
check(res and 'Only .docx and .pdf' in res[2], '.doc rejected')
res, _ = submit(anon, CPAGE, COMP, fields_y7)
check(res and 'Please attach your entry' in res[2], 'missing file blocked')

# ---- Emails -----------------------------------------------------------------
print('\n# Emails')
if MAILPIT:
    time.sleep(1)
    msgs = mailpit_messages()
    subjects = [(m['Subject'], [t['Address'] for t in m['To']]) for m in msgs]
    check(any(s.startswith('[TEST] ') and 'jane@example.com' in to for s, to in subjects), 'test entry: applicant email has [TEST] prefix')
    check(any(s.startswith('[TEST] New ') for s, to in subjects), 'test entry: admin email has [TEST] prefix')
    if real_ref:
        conf = [m for m in msgs if real_ref.group(1) in m['Subject'] and 'jane@example.com' in [t['Address'] for t in m['To']]]
        check(bool(conf) and not conf[0]['Subject'].startswith('[TEST]'), 'real entry: applicant confirmation with ref, no [TEST]')
        if conf:
            full = mailpit_get(conf[0]['ID'])
            check('#1d3461' in full['HTML'] and real_ref.group(1) in full['HTML'], 'HTML part uses QHTA template + ref')
            check(full['Text'].strip().startswith('Dear Ms Jane Tester') and '<' not in full['Text'], 'plain-text alternative present')
        adm = [m for m in msgs if m['Subject'].startswith('New ') and real_ref.group(1) in m['Subject']]
        check(bool(adm), 'admin notification sent for real entry')
    if ref1:
        check(any(ref1.group(1) in s and 'terry@example.com' in to for s, to in subjects), 'comp: teacher gets confirmation')
else:
    print('SKIP emails (no MAILPIT)')

summary()
