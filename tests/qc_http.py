"""Shared helpers for the qhta-competitions HTTP smoke tests (see m2/m3 scripts)."""
import http.cookiejar
import json
import os
import re
import shlex
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

BASE = os.environ.get('BASE', 'http://localhost:8089').rstrip('/')
WP = shlex.split(os.environ.get('WP', 'wp'))
MAILPIT = os.environ.get('MAILPIT', '').rstrip('/')
TOKEN = os.environ.get('PREVIEW_TOKEN', '')
HERE = os.path.dirname(os.path.abspath(__file__))
WAIT = 4.3  # > the form's minimum time-on-page

FAILS = []


def check(ok, label):
    print(('PASS ' if ok else 'FAIL ') + label)
    if not ok:
        FAILS.append(label)


def wp(*args):
    return subprocess.run(WP + list(args), capture_output=True, text=True).stdout.strip()


def php(code):
    return wp('eval', code)


def set_mode(key, mode):
    php(f"$m=get_option('wpff_modes',array()); $m['{key}']='{mode}'; update_option('wpff_modes',$m,false);")


def set_window(pid, opens, closes):
    php(f"Qhta_Competitions_Program_CPT::save_config({pid}, array('opens_at'=>gmdate('Y-m-d H:i:s', time()+({opens})), 'closes_at'=>gmdate('Y-m-d H:i:s', time()+({closes}))));")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.raw = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)

    def get(self, url, follow=True):
        try:
            r = (self.op if follow else self.raw).open(url if url.startswith('http') else BASE + url)
            return r.status, r.geturl(), r.read(), dict(r.headers)
        except urllib.error.HTTPError as e:
            return e.code, url, e.read(), dict(e.headers)

    def post(self, url, fields, files=None):
        boundary = uuid.uuid4().hex
        body = b''
        for k, v in fields:
            body += f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
        for k, (fname, data, ctype) in (files or {}).items():
            body += f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"; filename="{fname}"\r\nContent-Type: {ctype}\r\n\r\n'.encode() + data + b'\r\n'
        body += f'--{boundary}--\r\n'.encode()
        req = urllib.request.Request(url, body, {'Content-Type': f'multipart/form-data; boundary={boundary}'})
        try:
            r = self.op.open(req)
            return r.status, r.geturl(), r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, url, e.read().decode()

    def login(self, user, pwd):
        self.get('/wp-login.php')
        data = urllib.parse.urlencode({'log': user, 'pwd': pwd, 'testcookie': '1', 'wp-submit': 'Log In'}).encode()
        self.op.open(BASE + '/wp-login.php', data)
        return self


def hidden_inputs(html, program_id):
    form = html.split(f'id="qc-form-{program_id}"', 1)[1]
    names = ['_qc_nonce', 'qc_action', 'qc_program', 'qc_return', 'qc_ts']
    out = []
    for n in names:
        m = re.search(r'name="%s" value="([^"]*)"' % re.escape(n), form)
        out.append((n, m.group(1).replace('&amp;', '&') if m else ''))
    action = re.search(r'<form method="post" action="([^"]*)"', form).group(1).replace('&#038;', '&').replace('&amp;', '&')
    return out, action


def submit(client, page, program_id, fields, files=None, wait=WAIT):
    status, url, html, _ = client.get(page)
    html = html.decode()
    if f'id="qc-form-{program_id}"' not in html or '<form' not in html:
        return None, html
    hidden, action = hidden_inputs(html, program_id)
    time.sleep(wait)
    return client.post(action, hidden + fields, files), html


def mailpit_messages():
    if not MAILPIT:
        return []
    with urllib.request.urlopen(MAILPIT + '/api/v1/messages?limit=200') as r:
        return json.load(r)['messages']


def mailpit_clear():
    if MAILPIT:
        urllib.request.urlopen(urllib.request.Request(MAILPIT + '/api/v1/messages', method='DELETE'))


def mailpit_get(mid):
    with urllib.request.urlopen(f'{MAILPIT}/api/v1/message/{mid}') as r:
        return json.load(r)


def ensure_page(slug, shortcode):
    pid = php(f"$p=get_page_by_path('{slug}'); echo $p? $p->ID : '';")
    if pid:
        wp('post', 'update', pid, f'--post_content={shortcode}')
    else:
        pid = wp('post', 'create', '--post_type=page', '--post_status=publish', f'--post_title={slug}', f'--post_name={slug}', f'--post_content={shortcode}', '--porcelain')
    return f'/{slug}/'


def sub_meta(ref, keys):
    code = ("$q=get_posts(array('post_type'=>'qhta_submission','post_status'=>array_keys(Qhta_Competitions_Submission_CPT::statuses()),'meta_key'=>'_qc_ref','meta_value'=>'%s','posts_per_page'=>1));"
            "if(!$q){echo '{}';return;} $id=$q[0]->ID; $o=array('id'=>$id,'status'=>$q[0]->post_status);"
            "foreach(%s as $k){ $o[$k]=get_post_meta($id,$k,true);} echo wp_json_encode($o);") % (ref, 'array(' + ','.join(f"'{k}'" for k in keys) + ')')
    return json.loads(php(code) or '{}')




def summary():
    print('\nALL PASSED' if not FAILS else f'\n{len(FAILS)} FAILED')
    sys.exit(1 if FAILS else 0)


def ref_in(res, test=False):
    pat = r'<strong>(TEST-QC-\d{4}-\d{4})</strong>' if test else r'<strong>(QC-\d{4}-\d{4})</strong>'
    m = re.search(pat, res[2]) if res else None
    return m.group(1) if m else None


def bursary_fields(name='Jane Tester', email='jane@example.com', school='Longreach State High', referee_email='pat@example.com', membership='individual', seq=True, words=220):
    piece = ' '.join(['word'] * words)
    f = [
        ('qc_category', 'ecc-bursary'),
        ('qc_f[title]', 'Ms'), ('qc_f[full_name]', name),
        ('qc_f[home_address][line1]', '1 Main St'), ('qc_f[home_address][suburb]', 'Longreach'), ('qc_f[home_address][state]', 'QLD'), ('qc_f[home_address][postcode]', '4730'),
        ('qc_f[email]', email), ('qc_f[phone]', '0400000000'), ('qc_f[years_teaching]', '2 years'),
        ('qc_f[school_name]', school),
        ('qc_f[school_address][line1]', '2 School Rd'), ('qc_f[school_address][suburb]', 'Longreach'), ('qc_f[school_address][state]', 'QLD'), ('qc_f[school_address][postcode]', '4730'),
        ('qc_f[qct_number]', '123456'), ('qc_f[membership_type]', membership),
        ('qc_f[written_piece]', piece),
        ('qc_f[referee_name]', 'Pat Principal'), ('qc_f[referee_position]', 'Principal'), ('qc_f[referee_email]', referee_email),
        ('qc_decl[accept_terms]', '1'), ('qc_decl[attend]', '1'), ('qc_decl[receipts]', '1'), ('qc_decl[return_if_absent]', '1'), ('qc_decl[committee_final]', '1'),
        ('qc_signature', name),
    ]
    if membership == 'institutional':
        f.append(('qc_f[institution_name]', 'Longreach SHS'))
    if seq:
        f.append(('qc_f[outside_seq]', '1'))
    return f
