#!/usr/bin/env python3
"""Verify SMTP + registration against an in-process capture server, never a real recipient.
Only uses the isolated visionsy-split-test project; restores previous mail settings afterwards.
"""
from email import policy
from email.parser import BytesParser
import http.cookiejar
import json
from pathlib import Path
import queue
import re
import secrets
import socketserver
import subprocess
import threading
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
CONTAINER = 'visionsy-split-test-daemon-1'
messages = queue.Queue()

class SMTP(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b'220 visionsy-test ESMTP\r\n')
        while True:
            line = self.rfile.readline()
            if not line:
                break
            command = line.split(b' ', 1)[0].strip().upper()
            if command in (b'EHLO', b'HELO'):
                self.wfile.write(b'250-visionsy-test\r\n250-8BITMIME\r\n250 SIZE 10485760\r\n')
            elif command == b'DATA':
                self.wfile.write(b'354 End data with <CRLF>.<CRLF>\r\n')
                chunks = []
                while True:
                    chunk = self.rfile.readline()
                    if chunk in (b'.\r\n', b''):
                        break
                    chunks.append(chunk[1:] if chunk.startswith(b'..') else chunk)
                messages.put(BytesParser(policy=policy.default).parsebytes(b''.join(chunks)))
                self.wfile.write(b'250 Message accepted\r\n')
            elif command == b'QUIT':
                self.wfile.write(b'221 Bye\r\n')
                break
            else:
                self.wfile.write(b'250 OK\r\n')

class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


def php(code, *args, data=None):
    boot = r'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); '
    result = subprocess.run(['docker', 'exec', '-i', CONTAINER, 'php', '-r', boot+code, *args],
                            input=data, text=True, capture_output=True, check=True)
    return result.stdout


def main():
    credentials = json.loads((ROOT/'deploy/docker/.split-local/credentials.json').read_text())
    daemon_url, dashboard_url = credentials['daemon_url'], credentials['dashboard_url']
    host = urllib.parse.urlparse(daemon_url).hostname
    original = php(r'echo json_encode(Illuminate\Support\Facades\DB::table("service_settings")->where("key","mail")->first());')
    smtp = Server(('0.0.0.0', 0), SMTP)
    port = smtp.server_address[1]
    threading.Thread(target=smtp.serve_forever, daemon=True).start()
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(jar))
    csrf = ''
    email = 'registration-'+secrets.token_hex(5)+'@example.test'
    def request(path, method='GET', body=None, form=False, public=False):
        nonlocal csrf
        base = daemon_url if public else dashboard_url
        encoded = urllib.parse.urlencode(body).encode() if form else json.dumps(body).encode() if body is not None else None
        headers = {'Origin': base, 'Content-Type': 'application/x-www-form-urlencoded' if form else 'application/json', 'X-CSRF-Token': csrf}
        response = opener.open(urllib.request.Request(base+path, data=encoded, method=method, headers=headers), timeout=30)
        content = response.read()
        if public:
            return content.decode()
        result = json.loads(content)
        if result.get('csrf'):
            csrf = result['csrf']
        return result
    def token(html):
        return re.search(r'name="_token"[^>]*value="([^"]+)"', html)[1]
    try:
        request('/api/login', 'POST', {'username': credentials['dashboard_username'], 'password': credentials['dashboard_password']})
        connection = request('/api/connections')['active_id']
        prefix = '/api/daemon/'+connection+'/'
        config = {'enabled': True, 'host': host, 'port': port, 'security': 'none', 'username': '', 'password': '',
                  'clear_password': True, 'from_address': 'sender@example.test', 'from_name': 'VisionSY SMTP Test'}
        saved = request(prefix+'mail', 'PUT', config)
        assert 'password' not in saved['data']
        request(prefix+'mail/test', 'POST', {'recipient': 'recipient@example.test'})
        assert 'VisionSY' in str(messages.get(timeout=10)['Subject'])
        print('PASS: dashboard saves SMTP settings and sends a message through the real SMTP transport')
        html = request('/register', public=True)
        password = secrets.token_urlsafe(20)
        html = request('/register', 'POST', {'_token': token(html), 'display_name': '邮件验证-'+secrets.token_hex(4), 'email': email,
                       'password': password, 'password_confirmation': password}, form=True, public=True)
        assert '输入邮箱验证码' in html
        message = messages.get(timeout=10)
        code = re.search(r'邮箱验证码[：:]\s*(\d{6})', str(message['Subject']))[1]
        request('/email/verify-code', 'POST', {'_token': token(html), 'code': code}, form=True, public=True)
        state = json.loads(php(r'$u=App\Models\User::where("email",$argv[1])->firstOrFail();echo json_encode(["verified"=>$u->hasVerifiedEmail(),"status"=>$u->status->value]);', email))
        assert state == {'verified': True, 'status': 'pending_activation'}
        print('PASS: browser-style registration sends the actual verification email and the code verifies successfully')
    finally:
        # Only remove the exact synthetic test account; preserve every real user.
        php(r'App\Models\User::where("email",$argv[1])->delete();', email)
        restore = r'''
$current=App\Models\ServiceSetting::find('mail');
if ($current && ($current->value['port'] ?? null)==(int)$argv[1] && ($current->value['host'] ?? null)===$argv[2]) {
    $old=json_decode(stream_get_contents(STDIN),true);
    if ($old) { Illuminate\Support\Facades\DB::table('service_settings')->updateOrInsert(['key'=>'mail'],$old); }
    else { $current->delete(); }
}
'''
        php(restore, str(port), host, data=original)
        smtp.shutdown()
        smtp.server_close()
        print('Previous mail settings restored; no real email recipient or credential was used.')

if __name__ == '__main__':
    main()
