import os
import base64, io, json, pathlib, subprocess, urllib.request, urllib.error, zipfile, uuid

BASE = pathlib.Path(os.environ['SHIELDPRESS_LAB_DIR']).resolve()
DRIVER = pathlib.Path(__file__).resolve().parent
URL = 'http://127.0.0.1:18773'
results = []
run_id = uuid.uuid4().hex[:8]

def control(*args, stdin=None):
    return subprocess.run(['php', str(DRIVER/'control.php'), *args], input=stdin, text=True, capture_output=True, check=True).stdout

def settings(**values):
    control('settings', json.dumps(values))

def message(slug):
    archive = io.BytesIO()
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as z:
        z.writestr(f'{slug}/{slug}.php', '<?php\n/* Plugin Name: ShieldPress Lab Marker */\n'
                    'defined("ABSPATH") || exit;\n'
                    'register_activation_hook(__FILE__, function () { '
                    '$v = get_option("shieldpress_lab_activated", []); '
                    f'$v[] = "{slug}"; update_option("shieldpress_lab_activated", $v); '
                    '});\n')
    data = dict(filename=f'{slug}.zip', data=base64.b64encode(archive.getvalue()).decode(), activate=True)
    return json.loads(control('message', 'plugin.upload_plugin', json.dumps(data)))

def request(payload, path='/', cookie=None, raw_json=False):
    from urllib.parse import urlencode
    headers = {'User-Agent':'WordPress/6.4; class-udrpc.php',
               'Content-Type':'application/json' if raw_json else 'application/x-www-form-urlencoded'}
    if cookie: headers['Cookie'] = cookie
    data = json.dumps(payload).encode() if raw_json else urlencode(payload, doseq=True).encode()
    req = urllib.request.Request(URL+path, data=data, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=30) as r: return r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()

def state(): return json.loads(control('state'))
def installed(slug): return any(x['file']==f'{slug}/{slug}.php' for x in state()['plugins'])
def record(name, condition, **details):
    results.append(dict(name=name, passed=bool(condition), **details))
    print(json.dumps(results[-1]), flush=True)

phase = __import__('sys').argv[1] if len(__import__('sys').argv)>1 else 'before'
control('keys', 'authorized')
settings(waf_enabled=0, disable_software_installation=0, block_udrpc=0)
slug = f'shieldpress-audit-{run_id}-{phase}-baseline'
p = message(slug)
code, body = request(p)
decoded = json.loads(control('decode', stdin=body)) if body.startswith('{') else {}
s = state()
record('signed upload and activation baseline', code==200 and installed(slug) and slug in s['activation_markers'], http=code, rpc=decoded.get('response'), installed=installed(slug), activated=slug in s['activation_markers'])

settings(waf_enabled=1)
slug = f'shieldpress-audit-{run_id}-{phase}-waf'
code, body = request(message(slug))
s = state()
record('WAF alone allows signed RPC installation', code==200 and installed(slug) and slug in s['activation_markers'], http=code, installed=installed(slug), activated=slug in s['activation_markers'])

settings(disable_software_installation=1)
slug = f'shieldpress-audit-{run_id}-{phase}-locked'
code, body = request(message(slug))
decoded = json.loads(control('decode', stdin=body)) if body.startswith('{') else {}
record('software lock blocks signed RPC upload', not installed(slug) and decoded.get('data',{}).get('code')=='plugin_insufficient_permission', http=code, rpc_error=decoded.get('data',{}).get('code'), installed=installed(slug))

settings(disable_software_installation=0)
slug = f'shieldpress-audit-{run_id}-{phase}-bad-signature'
p = message(slug); p['signature'] = base64.b64encode(b'invalid-lab-signature').decode()
code, body = request(p)
record('Updraft rejects invalid signature with existing key', not installed(slug) and not body.startswith('{"format"'), http=code, response_bytes=len(body), installed=installed(slug))

slug = f'shieldpress-audit-{run_id}-{phase}-no-key'
p = message(slug)
control('keys', 'none')
code, body = request(p)
record('Free 1.26.11 no-key negative control', not installed(slug) and not state()['has_keys'] and 'udrpc_message' not in body[:300], http=code, installed=installed(slug), attribution='Updraft has no central listener; not ShieldPress blocking')

if phase == 'after':
    control('keys', 'authorized')
    settings(waf_enabled=0, block_udrpc=1)
    slug = f'shieldpress-audit-{run_id}-after-guard'
    code, body = request(message(slug))
    record('UDRPC guard blocks valid signature before installation with WAF off', code==403 and not installed(slug), http=code, installed=installed(slug))
    cookie = control('cookie')
    for name, payload, path in [
        ('admin cookie', {'udrpc_message':'audit'}, '/'),
        ('admin AJAX', {'udrpc_message':'audit','action':'audit'}, '/wp-admin/admin-ajax.php'),
        ('array value', {'udrpc_message[]':'audit'}, '/'),
        ('empty value', {'udrpc_message':''}, '/'),
        ('GET parameter', {}, '/?udrpc_message=audit')]:
        code, body = request(payload, path, cookie if name=='admin cookie' else None)
        record('guard: '+name, code==403, http=code)
    control('keys', 'none')
    code, body = request({'udrpc_message':'audit'})
    record('guard blocks with no configured key', code==403, http=code)
    code, body = request({'message':'ordinary form'})
    record('ordinary frontend form unaffected', code==200, http=code)
    code, body = request({'udrpc_message':'audit'}, raw_json=True)
    record('raw JSON is not parsed by Updraft POST listener', code==200, http=code, note='Not claimed as inspected or blocked; PHP does not populate POST for JSON')
    settings(block_udrpc=0)
    control('keys', 'authorized')
    slug = f'shieldpress-audit-{run_id}-after-optout'
    code, body = request(message(slug))
    record('guard opt-out restores valid RPC upload', code==200 and installed(slug), http=code, installed=installed(slug))

out = dict(phase=phase, wordpress='7.1.3', updraftplus='Free 1.26.11', database='SQLite', failures=sum(not r['passed'] for r in results), results=results)
(BASE/f'http-{phase}.json').write_text(json.dumps(out, indent=2))
raise SystemExit(bool(out['failures']))
