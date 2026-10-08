"""Small HTTP adapter for the private lab; put Caddy HTTPS in front of it."""
import argparse
import base64
import getpass
import hashlib
import hmac
import json
import os
import re
import secrets
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlsplit

from lab import Lab, Problem
from segments import count


def password_hash(password, salt=None):
    salt = salt or secrets.token_hex(16)
    key = hashlib.scrypt(password.encode(), salt=salt.encode(), n=16384, r=8, p=1).hex()
    return salt + ':' + key


class Application:
    def __init__(self, lab, operator_hash):
        self.lab, self.operator_hash = lab, operator_hash

    def route(self, method, path, headers, body, remote='local'):
        parsed = urlsplit(path)
        path = parsed.path
        if method == 'GET' and path == '/health':
            return 200, {'ok': True}
        if not path.startswith('/lab/v1/'):
            raise Problem(404, 'NOT_FOUND')
        route = path[len('/lab/v1'):]
        if not isinstance(body, dict):
            raise Problem(400, 'JSON_OBJECT_REQUIRED')
        token = headers.get('Authorization', '')
        token = token[7:] if token.startswith('Bearer ') else ''
        if route == '/session' and method == 'POST':
            self.lab.auth_limit('operator:' + remote)
            password = body.get('password', '')
            if not isinstance(password, str) or len(password) > 512 or not hmac.compare_digest(password_hash(password, self.operator_hash.split(':')[0]), self.operator_hash):
                raise Problem(401, 'INVALID_LOGIN')
            return 200, {'token': self.lab.session(), 'expires_in': 3600}
        if route == '/devices/pair' and method == 'POST':
            self.lab.auth_limit('pair:' + remote)
            return 201, self.lab.pair(body)
        is_device = route == '/queue/claim' or route.endswith('/heartbeat') or route.endswith('/lease') or (route.endswith('/events') and method == 'POST')
        if is_device and method == 'POST':
            return self.lab.device_call(headers.get('X-Device-ID', ''), token, headers.get('X-Request-ID', ''), route, body)
        self.lab.operator(token)
        if route == '/messages' and method == 'POST':
            return 201, self.lab.create_message(body, headers.get('Idempotency-Key'))
        if route in ('/messages', '/snapshot') and method == 'GET':
            return 200, self.lab.snapshot(parse_qs(parsed.query).get('status', [None])[0])
        if route == '/segments' and method == 'POST':
            text = body.get('body')
            if not isinstance(text, str) or len(text) > 10000:
                raise Problem(400, 'INVALID_BODY')
            return 200, count(text)
        if route == '/pairing-code' and method == 'POST':
            return 201, self.lab.pairing_code()
        if route in ('/config', '/allowlist') and method == 'POST':
            return 200, self.lab.control(route[1:], body)
        match = re.fullmatch('/devices/([^/]+)/(pause|resume|revoke)', route)
        if match and method == 'POST':
            did, action = match.groups()
            return 200, self.lab.control(action, body | {'device_id': did})
        match = re.fullmatch('/messages/([^/]+)/(cancel|resolve|events)', route)
        if match:
            mid, action = match.groups()
            if action == 'events' and method == 'GET':
                return 200, self.lab.history(mid)
            if action != 'events' and method == 'POST':
                return 200, self.lab.control(action, body | {'message_id': mid})
        if route == '/export' and method == 'GET':
            snapshot = self.lab.snapshot()
            snapshot['events'] = {m['id']: self.lab.history(m['id']) for m in snapshot['messages']}
            return 200, snapshot
        if route == '/logout' and method == 'POST':
            from lab import digest
            with self.lab.transaction() as db:
                db.execute('DELETE FROM sessions WHERE hash=?', (digest(token),))
            return 200, {'ok': True}
        raise Problem(404, 'NOT_FOUND')


def handler(application):
    class Handler(BaseHTTPRequestHandler):
        server_version = 'UdzioSMSLab'

        def setup(self):
            super().setup()
            self.connection.settimeout(15)

        def log_message(self, format, *args):
            # No tokens, URLs, phone numbers, payloads, or untrusted text in diagnostics.
            pass

        def do_GET(self):
            self.handle_request()

        def do_POST(self):
            self.handle_request()

        def handle_request(self):
            try:
                if self.command == 'GET' and self.path in ('/', '/panel.js', '/panel.css'):
                    filename = {'/': 'index.html', '/panel.js': 'panel.js', '/panel.css': 'panel.css'}[self.path]
                    content = (Path(__file__).parent / 'static' / filename).read_bytes()
                    mime = {'/': 'text/html', '/panel.js': 'text/javascript', '/panel.css': 'text/css'}[self.path]
                    return self.respond(200, content, mime)
                length = int(self.headers.get('Content-Length', 0))
                if not 0 <= length <= 16384:
                    raise Problem(413, 'BODY_TOO_LARGE')
                data = json.loads(self.rfile.read(length)) if length else {}
                code, result = application.route(self.command, self.path, self.headers, data, self.client_address[0])
                self.respond(code, b'' if code == 204 else json.dumps(result, ensure_ascii=False).encode(), 'application/json')
            except Problem as error:
                self.respond(error.status, json.dumps({'error': error.code}).encode(), 'application/json')
            except (ValueError, UnicodeError):
                self.respond(400, b'{"error":"INVALID_JSON_OR_LENGTH"}', 'application/json')
            except Exception:
                self.respond(500, b'{"error":"INTERNAL_ERROR"}', 'application/json')

        def respond(self, code, content, mime):
            self.send_response(code)
            self.send_header('Content-Type', mime + '; charset=utf-8')
            self.send_header('Content-Length', str(len(content)))
            self.send_header('Cache-Control', 'no-store')
            self.send_header('X-Content-Type-Options', 'nosniff')
            self.send_header('Referrer-Policy', 'no-referrer')
            self.send_header('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; frame-ancestors 'none'; base-uri 'none'")
            self.end_headers()
            self.wfile.write(content)
    return Handler


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--init', action='store_true', help='Read operator password and write a local hash-only env file')
    parser.add_argument('--env', default='.env')
    parser.add_argument('--host', default='127.0.0.1')
    parser.add_argument('--port', type=int, default=8787)
    args = parser.parse_args()
    if args.init:
        password = getpass.getpass('Hasło operatora (min. 12 znaków): ')
        if len(password) < 12:
            raise SystemExit('Hasło za krótkie.')
        fd = os.open(args.env, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, 'w') as file:
            file.write('LAB_OPERATOR_HASH=' + password_hash(password) + '\nLAB_DB=runtime/lab.sqlite3\n')
        print('Zapisano hash hasła. Nie ma domyślnego hasła ani danych demo.')
        return
    if Path(args.env).exists():
        for line in Path(args.env).read_text().splitlines():
            if '=' in line and not line.startswith('#'):
                key, value = line.split('=', 1)
                os.environ.setdefault(key, value)
    stored = os.environ.get('LAB_OPERATOR_HASH', '')
    if not re.fullmatch(r'[a-f0-9]{32}:[a-f0-9]{128}', stored):
        raise SystemExit('Najpierw uruchom --init, aby ustawić hasło operatora.')
    os.umask(0o077)
    lab = Lab(os.environ.get('LAB_DB', 'runtime/lab.sqlite3'))
    app = Application(lab, stored)
    def maintenance():
        while True:
            try:
                lab.maintenance()
            except Exception:
                print('MAINTENANCE_FAILED', flush=True)
            time.sleep(15)
    threading.Thread(target=maintenance, daemon=True).start()
    print(f'UdzioSMS Lab listening on {args.host}:{args.port}; queue paused by default', flush=True)
    ThreadingHTTPServer((args.host, args.port), handler(app)).serve_forever()


if __name__ == '__main__':
    main()
