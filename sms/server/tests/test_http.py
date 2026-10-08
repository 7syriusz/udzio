import json
import tempfile
import threading
import unittest
from http.server import ThreadingHTTPServer
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen

from app import Application, handler, password_hash
from lab import Lab


class HttpTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.app = Application(Lab(Path(self.tmp.name)/'http.sqlite3'), password_hash('test-password-only'))
        self.server = ThreadingHTTPServer(('127.0.0.1', 0), handler(self.app))
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.base = f'http://127.0.0.1:{self.server.server_port}'

    def tearDown(self):
        self.server.shutdown(); self.server.server_close(); self.thread.join()
        self.tmp.cleanup()

    def test_panel_health_login_and_logout_over_real_http(self):
        with urlopen(self.base+'/') as response:
            self.assertIn(b'UdzioSMS Lab', response.read())
            self.assertIn("frame-ancestors 'none'", response.headers['Content-Security-Policy'])
        request = Request(self.base+'/lab/v1/session', data=json.dumps({'password':'test-password-only'}).encode(), headers={'Content-Type':'application/json'})
        with urlopen(request) as response:
            token = json.load(response)['token']
        with urlopen(Request(self.base+'/lab/v1/snapshot', headers={'Authorization':'Bearer '+token})) as response:
            self.assertEqual(0,json.load(response)['config']['enabled'])
        with urlopen(Request(self.base+'/lab/v1/logout',data=b'{}',headers={'Authorization':'Bearer '+token})) as response:
            self.assertEqual(200,response.status)
        with self.assertRaises(HTTPError) as failure:
            urlopen(Request(self.base+'/lab/v1/snapshot',headers={'Authorization':'Bearer '+token}))
        self.assertEqual(401,failure.exception.code)

    def test_invalid_json_traversal_and_secret_file_are_rejected(self):
        for path, data, expected in [('/lab/v1/session',b'{',400),('/.env',None,404),('/../lab.py',None,404)]:
            with self.subTest(path=path):
                with self.assertRaises(HTTPError) as failure:
                    urlopen(Request(self.base+path,data=data))
                self.assertEqual(expected,failure.exception.code)
