#!/usr/bin/env python3
"""M4 end-to-end smoke test: judge role, panel, anonymised dashboard, access, scoring, results.

DISPOSABLE SITES ONLY. Run tests/m1-smoke.php setup first. Membership checks
use `pmpro_hasMembershipLevel()` (real PMPro or the user-meta test stub).

    BASE=http://localhost:8089 WP="docker exec qctest-cli-1 wp" python3 tests/m4-http-smoke.py
"""
import json
import os
import re
import urllib.parse

from qc_http import *

COMP = int(php("echo Qhta_Competitions_Program_CPT::find('renamed-writing-comp')->ID;"))
CKEY = 'comp-writing-competition-2026-demo'
CPAGE = ensure_page('writing-competition', f'[qhta_program_form program="{COMP}"]')
CAT, OTHER = 'y11-modern-essay', 'y12-modern-essay'
FIX = json.load(open(os.path.join(HERE, 'fixtures', 'judging-scores.json')))
EXP = json.load(open(os.path.join(HERE, 'fixtures', 'judging-expected.json')))
set_mode('competitions', 'on')
set_mode(CKEY, 'on')
set_window(COMP, -3600, 30 * 86400)
php(f"Qhta_Competitions_Program_CPT::save_config({COMP}, array('judge_spread_margin'=>15));")
php("global $wpdb; $wpdb->query('DELETE FROM '.Qhta_Competitions_Plugin::scores_table());")
admin = Client().login('admin', 'admin')


def user(login, role, member=False):
    if php(f"echo get_user_by('login','{login}') ? 1 : 0;") != '1':
        wp('user', 'create', login, f'{login}@example.com', f'--role={role}', f'--user_pass={login}pass', f'--display_name={login.title()}')
    uid = int(php(f"echo get_user_by('login','{login}')->ID;"))
    php(f"$u=new WP_User({uid}); $u->set_role('{role}'); update_user_meta({uid}, 'stub_pmpro_level', '{'1' if member else ''}'); delete_user_meta({uid}, 'qc_judge_school');")
    return uid


def roles(uid):
    return sorted(json.loads(php(f"echo wp_json_encode(array_values((new WP_User({uid}))->roles));")))


def judges_nonce(field):
    _, _, h, _ = admin.get('/wp-admin/edit.php?post_type=qhta_program&page=qc-judges')
    h = h.decode()
    m = re.search(r'name="action" value="%s"><input type="hidden" name="user_id" value="(\d+)">.*?name="_wpnonce" value="([^"]+)"' % field, h, re.S)
    return h, m


def page_nonce(html, action):
    m = re.search(r'name="action" value="%s">.*?name="_wpnonce" value="([^"]+)"' % re.escape(action), html, re.S)
    return m.group(1) if m else ''


# ---- Judge role ------------------------------------------------------------
print('\n# Judge role')
J1 = user('judge1', 'subscriber', member=True)
J2 = user('judge2', 'subscriber')
J3 = user('judge3', 'editor')
_, _, h, _ = admin.get('/wp-admin/edit.php?post_type=qhta_program&page=qc-judges')
nonce = page_nonce(h.decode(), 'qc_judge_add')
for who in ('judge1', 'judge2@example.com', 'judge3'):
    admin.post(BASE + '/wp-admin/admin-post.php', [('action', 'qc_judge_add'), ('_wpnonce', nonce), ('user', who)])
check(roles(J1) == ['qhta_judge', 'subscriber'] and roles(J3) == ['editor', 'qhta_judge'], f'add judge keeps existing role (judge1 {roles(J1)}, judge3 {roles(J3)})')
check(php(f"echo get_user_meta({J1}, 'stub_pmpro_level', true);") == '1' and php(f"wp_set_current_user({J1}); echo pmpro_hasMembershipLevel() ? 'y':'n';") == 'y', 'membership intact after adding judge role')
_, _, h, _ = admin.get('/wp-admin/edit.php?post_type=qhta_program&page=qc-judges')
h = h.decode()
check('Judge1' in h and 'Subscriber' in h and 'Editor' in h, 'Judges page lists judges with their other roles')
rm = re.search(r'name="action" value="qc_judge_remove"><input type="hidden" name="user_id" value="%d">.*?name="_wpnonce" value="([^"]+)"' % J3, h, re.S)
admin.post(BASE + '/wp-admin/admin-post.php', [('action', 'qc_judge_remove'), ('user_id', str(J3)), ('_wpnonce', rm.group(1))])
check(roles(J3) == ['editor'], f'remove judge leaves only the original role ({roles(J3)})')
sc = re.search(r'name="action" value="qc_judge_school"><input type="hidden" name="user_id" value="%d">.*?name="_wpnonce" value="([^"]+)"' % J2, h, re.S)
admin.post(BASE + '/wp-admin/admin-post.php', [('action', 'qc_judge_school'), ('user_id', str(J2)), ('_wpnonce', sc.group(1)), ('school', 'Toowoomba S.H.S.')])
check(php(f"echo get_user_meta({J2}, 'qc_judge_school', true);") == 'Toowoomba S.H.S.', 'judge school saved')

# ---- Entries -----------------------------------------------------------------
print('\n# Entries')
base = [
    ('qc_f[year_level]', '11'), ('qc_f[teacher_name]', 'Terry Teacher'), ('qc_f[teacher_email]', 'terry@example.com'), ('qc_f[teacher_phone]', '0711111111'),
    ('qc_f[principal_name]', 'Pip Principal'), ('qc_f[school_address][line1]', '3 Rd'), ('qc_f[school_address][suburb]', 'Brisbane'), ('qc_f[school_address][state]', 'QLD'), ('qc_f[school_address][postcode]', '4000'),
    ('qc_decl[original_work]', '1'), ('qc_decl[judges_final]', '1'), ('qc_decl[publication_consent]', '1'), ('qc_decl[permission]', '1'), ('qc_signature', 'Terry Teacher'), ('qc_f[word_count_declared]', '1500'),
]
pdf = ('entry.pdf', b'%PDF-1.4\n%%EOF\n', 'application/pdf')
REF = {}
anon = Client()
for e in sorted(FIX['scores']):
    school = 'Toowoomba SHS' if e == 'E6' else 'Brisbane State High'
    res, _ = submit(anon, CPAGE, COMP, base + [('qc_f[student_name]', f'Student {e}'), ('qc_f[school_name]', school), ('qc_category', CAT)], {'qc_file_entry_file': pdf})
    REF[e] = ref_in(res)
res, _ = submit(anon, CPAGE, COMP, [(k, '12' if k == 'qc_f[year_level]' else v) for k, v in base] + [('qc_f[student_name]', 'Other Cat'), ('qc_f[school_name]', 'Cairns SHS'), ('qc_category', OTHER)], {'qc_file_entry_file': pdf})
REF['OTHER'] = ref_in(res)
set_mode(CKEY, 'preview')
res, _ = submit(admin, CPAGE, COMP, base + [('qc_f[student_name]', 'Test Entry'), ('qc_f[school_name]', 'Test SHS'), ('qc_category', CAT)], {'qc_file_entry_file': pdf})
REF['TEST'] = ref_in(res, test=True)
set_mode(CKEY, 'on')
check(all(REF.values()), f'entries created ({len(REF)})')
SID = {k: sub_meta(v, [])['id'] for k, v in REF.items()}

# Panel: judge1 + judge2 on CAT only.
php(f"Qhta_Competitions_Judging::save_panel({COMP}, array('{CAT}'=>array({J1},{J2}), '{OTHER}'=>array()));")
_, _, h, _ = admin.get(f'/wp-admin/post.php?post={COMP}&action=edit')
check('Judging panel' in h.decode() and f'name="qc_panel[{CAT}][]" value="{J1}"' in h.decode(), 'program screen has the judging panel box')

# Send to judging via the Submissions bulk action.
_, _, h, _ = admin.get('/wp-admin/edit.php?post_type=qhta_submission')
bn = re.search(r'id="_wpnonce" name="_wpnonce" value="([^"]+)"', h.decode())
q = [('post_type', 'qhta_submission'), ('action', 'qc_to_review'), ('_wpnonce', bn.group(1))] + [('post[]', str(i)) for i in SID.values()]
admin.get('/wp-admin/edit.php?' + urllib.parse.urlencode(q))
st = {k: sub_meta(v, [])['status'] for k, v in REF.items()}
check(all(st[k] == 'qc_in_review' for k in st if k != 'TEST') and st['TEST'] == 'qc_submitted', f'bulk "Send to judging": real → In review, test entry skipped ({st})')

# ---- Judge access -------------------------------------------------------------
print('\n# Judge access')
j1 = Client()
j1.get('/wp-login.php')
r = j1.op.open(BASE + '/wp-login.php', urllib.parse.urlencode({'log': 'judge1', 'pwd': 'judge1pass', 'testcookie': '1', 'wp-submit': 'Log In'}).encode())
check('page=qc-judging' in r.geturl(), f'judge lands on the Judging dashboard after login ({r.geturl()})')
_, _, h, _ = j1.get('/wp-admin/admin.php?page=qc-judging')
h = h.decode()
mine = [REF[e] for e in FIX['scores'] if e != 'E6'] + [REF['E6']]
check(all(REF[e] in h for e in FIX['scores']), 'judge1 dashboard lists every entry in the assigned category')
check(REF['OTHER'] not in h and REF['TEST'] not in h, 'other category and test entries not listed')
check('Student E' not in h and 'Brisbane State High' not in h and 'terry@example.com' not in h, 'dashboard shows reference codes only (no PII)')
check('0 of 6 scored' in h, 'progress shows 0 of 6')
_, _, h, _ = j1.get(f"/wp-admin/admin.php?page=qc-judging&submission={SID['E1']}")
h = h.decode()
check(REF['E1'] in h and 'Download' in h and 'Student E1' not in h and 'Brisbane State High' not in h and 'Terry' not in h and 'Pip Principal' not in h and 'terry@example.com' not in h, 'scoring screen: ref + file, no PII')
dl = re.search(r'href="([^"]*admin-post\.php\?action=qc_file[^"]*)"', h).group(1).replace('&#038;', '&').replace('&amp;', '&')
code, _, body, hdr = j1.get(dl)
check(code == 200 and REF['E1'] in hdr.get('Content-Disposition', ''), 'judge downloads an assigned entry')
for label, sid in (('other category', SID['OTHER']), ('test entry', SID['TEST']), ('E2', SID['E2'])):
    tampered = re.sub(r'id=\d+', f'id={sid}', dl)
    code, _, _, _ = j1.get(tampered)
    check(code == 403, f'ID-tampered download ({label}) → 403 (got {code})')
check(php(f"wp_set_current_user({J1}); echo Qhta_Competitions_Files::user_can_download({SID['OTHER']}) ? 'y':'n';") == 'n', 'even with a valid nonce, judge1 cannot download the other category (capability check)')
check(php(f"wp_set_current_user({J1}); echo Qhta_Competitions_Files::user_can_download({SID['E2']}) ? 'y':'n';") == 'y', 'capability check allows assigned entries')
code, _, _, _ = j1.get(f"/wp-admin/admin.php?page=qc-judging&submission={SID['OTHER']}")
check(code == 403, f'scoring screen for other category → 403 (got {code})')
code, _, body, _ = j1.get(f"/wp-admin/post.php?post={SID['E1']}&action=edit")
# Core's post.php refuses with wp_die() and no status code (→ 500); what matters is the refusal.
check(code in (403, 500) and b'not allowed to edit this item' in body and b'Student E1' not in body, f'judge cannot open the admin submission screen ({code}, refused by core)')
code, _, h2, _ = j1.get('/wp-admin/edit.php?post_type=qhta_submission')
check(code == 403, f'judge cannot open the submissions list ({code})')
code, _, _, _ = j1.get('/wp-admin/edit.php?post_type=qhta_program&page=qc-results')
check(code == 403, f'judge cannot open results ({code})')

j2 = Client().login('judge2', 'judge2pass')
_, _, h, _ = j2.get('/wp-admin/admin.php?page=qc-judging')
check(REF['E6'] not in h.decode() and REF['E1'] in h.decode(), 'conflict of interest: judge2 does not see the Toowoomba entry')
code, _, _, _ = j2.get(f"/wp-admin/admin.php?page=qc-judging&submission={SID['E6']}")
check(code == 403, 'conflict of interest: direct URL → 403')

# ---- Scoring -----------------------------------------------------------------
print('\n# Scoring')
judges = {'J1': j1, 'J2': j2}
crit = FIX['criteria']
_, _, h, _ = j1.get(f"/wp-admin/admin.php?page=qc-judging&submission={SID['E1']}")
n = page_nonce(h.decode(), 'qc_save_scores')
_, url, body = j1.post(BASE + '/wp-admin/admin-post.php', [('action', 'qc_save_scores'), ('submission', str(SID['E1'])), ('_wpnonce', n)] + [(f'score[{c}]', '11') for c in crit])
check('must be a number from 0 to 10' in body, 'score above the max rejected')
for e, by_judge in FIX['scores'].items():
    for j, scores in by_judge.items():
        c = judges[j]
        _, _, h, _ = c.get(f"/wp-admin/admin.php?page=qc-judging&submission={SID[e]}")
        n = page_nonce(h.decode(), 'qc_save_scores')
        fields = [('action', 'qc_save_scores'), ('submission', str(SID[e])), ('_wpnonce', n), ('overall', f'{j} on {e}')]
        fields += [(f'score[{k}]', '' if v is None else str(v)) for k, v in zip(crit, scores)]
        _, url, body = c.post(BASE + '/wp-admin/admin-post.php', fields)
        check('Scores saved' in body, f'{j} saves scores for {e}')
_, _, h, _ = j1.get('/wp-admin/admin.php?page=qc-judging')
check('6 of 6 scored' in h.decode(), 'judge1 progress 6 of 6')
_, _, h, _ = j2.get('/wp-admin/admin.php?page=qc-judging')
check('3 of 5 scored' in h.decode() and 'Part scored' in h.decode(), 'judge2 progress 3 of 5 with a part-scored entry')
_, _, h, _ = j2.get(f"/wp-admin/admin.php?page=qc-judging&submission={SID['E1']}")
check('value="8"' in h.decode() and 'J2 on E1' in h.decode() and 'J1 on E1' not in h.decode(), 'judge sees own saved scores/comments only')

# ---- Results vs hand calculation ----------------------------------------------
print('\n# Results')
rows = json.loads(php(f"echo wp_json_encode(Qhta_Competitions_Judging::results({COMP}, '{CAT}'));"))
by_ref = {r['ref']: r for r in rows}
J = {'J1': J1, 'J2': J2}
for e, x in EXP.items():
    r = by_ref.get(REF[e], {})
    ok_mean = (x['mean'] is None and r.get('mean') is None) or (r.get('mean') is not None and abs(r['mean'] - x['mean']) < 0.0001)
    ok_judges = all((v is None and r['judges'].get(str(J[j]), {}).get('pct') is None) or (v is not None and abs(r['judges'][str(J[j])]['pct'] - v) < 0.0001) for j, v in x['judges'].items())
    ok_coi = e != 'E6' or r['judges'][str(J2)]['conflict'] is True
    check(ok_mean and ok_judges and ok_coi and r.get('rank') == x['rank'] and r.get('warn') == x['warn'] and r.get('complete') == x['complete'],
          f"{e}: mean {r.get('mean')} rank {r.get('rank')} warn {r.get('warn')} == hand calc {x['mean']} / {x['rank']} / {x['warn']}")
_, _, h, _ = admin.get(f'/wp-admin/edit.php?post_type=qhta_program&page=qc-results&program={COMP}&category={CAT}')
h = h.decode()
check('⚠' in h and 'COI' in h and 'Student E1' in h and '85.83' in h, 'results page: warning, COI, entrant names for admins, means')
_, _, sb, _ = admin.get(f"/wp-admin/post.php?post={SID['E1']}&action=edit")
check("Judges' scores" in sb.decode() or 'Judges&#039; scores' in sb.decode(), 'submission screen shows judges\' scores box')

n = page_nonce(h, 'qc_results_status')
_, url, body = admin.post(BASE + '/wp-admin/admin-post.php', [('action', 'qc_results_status'), ('_wpnonce', n), ('program', str(COMP)), ('category', CAT), ('to', 'qc_awarded'), ('ids[]', str(SID['E6']))])
check('Not allowed from their current status' in body and sub_meta(REF['E6'], [])['status'] == 'qc_in_review', 'In review → Awarded refused (must shortlist first)')
_, url, body = admin.post(BASE + '/wp-admin/admin-post.php', [('action', 'qc_results_status'), ('_wpnonce', n), ('program', str(COMP)), ('category', CAT), ('to', 'qc_shortlisted'), ('ids[]', str(SID['E6'])), ('ids[]', str(SID['E1']))])
_, url, body = admin.post(BASE + '/wp-admin/admin-post.php', [('action', 'qc_results_status'), ('_wpnonce', n), ('program', str(COMP)), ('category', CAT), ('to', 'qc_awarded'), ('ids[]', str(SID['E6']))])
check(sub_meta(REF['E6'], [])['status'] == 'qc_awarded' and sub_meta(REF['E1'], [])['status'] == 'qc_shortlisted', 'bulk shortlist then award from results view')
_, _, h, _ = j1.get('/wp-admin/admin.php?page=qc-judging')
check(REF['E6'] not in h.decode() and REF['E1'] in h.decode(), 'awarded entries leave the judge queue; shortlisted stay')

# Removing a judge keeps their scores but takes them off panels.
php(f"Qhta_Competitions_Judging::remove_judge({J2});")
check(roles(J2) == ['subscriber'] and str(J2) not in php(f"echo wp_json_encode(Qhta_Competitions_Judging::panel({COMP}));"), 'removed judge: role gone, off the panel, subscriber role kept')
code, _, _, _ = j2.get('/wp-admin/admin.php?page=qc-judging')
check(code == 403, f'removed judge loses dashboard access ({code})')

summary()
