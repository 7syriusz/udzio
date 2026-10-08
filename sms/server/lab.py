"""Independent lab queue. SQLite BEGIN IMMEDIATE serializes claims across threads/processes."""
import contextlib
import hashlib
import hmac
import json
import re
import secrets
import sqlite3
import time
import uuid
from datetime import datetime, timezone
from pathlib import Path

from segments import PREFIX, count


class Problem(Exception):
    def __init__(self, status, code):
        self.status, self.code = status, code
        super().__init__(code)


def digest(value):
    return hashlib.sha256(value.encode()).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False)


def uid():
    return str(uuid.uuid4())


def iso(value):
    return datetime.fromtimestamp(value, timezone.utc).isoformat() if value is not None else None


def timestamp(value):
    try:
        parsed = datetime.fromisoformat(value.replace('Z', '+00:00'))
        if parsed.tzinfo is None:
            raise ValueError()
        return parsed.timestamp()
    except (ValueError, TypeError, AttributeError):
        raise Problem(400, 'INVALID_TIMESTAMP')


def identifier(value):
    try:
        return str(uuid.UUID(value))
    except (ValueError, TypeError, AttributeError):
        raise Problem(400, 'INVALID_UUID')


def number(value):
    if not isinstance(value, str) or not re.fullmatch(r'\+[1-9][0-9]{7,14}', value):
        raise Problem(400, 'INVALID_E164')
    return value


def mask(value):
    return '+' + '*' * max(0, len(value) - 4) + value[-3:] if value else '[usunięto]'


class DatabaseConnection(sqlite3.Connection):
    def __exit__(self, *args):
        try:
            return super().__exit__(*args)
        finally:
            self.close()


class Lab:
    def __init__(self, path, clock=time.time):
        self.path, self.clock = str(path), clock
        Path(path).parent.mkdir(parents=True, exist_ok=True)
        with self.connect() as db:
            db.executescript(Path(__file__).with_name('schema.sql').read_text())

    def connect(self):
        db = sqlite3.connect(self.path, timeout=15, factory=DatabaseConnection)
        db.row_factory = sqlite3.Row
        db.execute('PRAGMA foreign_keys=ON')
        db.execute('PRAGMA busy_timeout=15000')
        return db

    @contextlib.contextmanager
    def transaction(self):
        db = self.connect()
        try:
            db.execute('BEGIN IMMEDIATE')
            yield db
            db.commit()
        except Exception:
            db.rollback()
            raise
        finally:
            db.close()

    def audit(self, db, action, metadata):
        db.execute('INSERT INTO operator_events VALUES(?,?,?,?)', (uid(), action, self.clock(), canonical(metadata)))

    def event(self, db, m, kind, error=None):
        db.execute('INSERT INTO events VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                   (uid(), m['id'], kind, None, self.clock(), m['device_id'], m['attempt_count'], error, '{}', '', uid()))

    def sweep(self, db):
        now = self.clock()
        for m in db.execute("SELECT * FROM messages WHERE status IN ('QUEUED','CLAIMED','SENDING','FAILED_RETRYABLE')").fetchall():
            state, error = None, None
            if m['status'] == 'SENDING' and m['lease_expires'] <= now:
                state, error = 'UNKNOWN', 'CALLBACK_TIMEOUT'
            elif m['status'] == 'CLAIMED' and m['lease_expires'] <= now:
                state, error = 'QUEUED', 'LEASE_EXPIRED_BEFORE_SENDING'
            elif m['status'] == 'FAILED_RETRYABLE' and m['not_before'] <= now:
                state = 'QUEUED'
            if m['status'] != 'SENDING' and m['expires'] <= now:
                state, error = 'FAILED_FINAL', 'TASK_EXPIRED'
            if state:
                db.execute('UPDATE messages SET status=?,last_error_code=?,updated=? WHERE id=?', (state, error, now, m['id']))
                self.event(db, m, state, error)
        # Content and recipients expire even for unresolved records. Keep non-sensitive event facts.
        db.execute('UPDATE messages SET recipient=NULL,body=NULL WHERE created<?', (now - 30 * 86400,))
        db.execute('DELETE FROM requests WHERE created<?', (now - 86400,))
        db.execute('DELETE FROM allowlist WHERE created<?', (now - 30 * 86400,))
        db.execute('DELETE FROM auth_limits WHERE window<?', (now - 86400,))
        db.execute('DELETE FROM sessions WHERE expires<?', (now,))
        db.execute('DELETE FROM pair_codes WHERE expires<?', (now,))

    def maintenance(self):
        with self.transaction() as db:
            self.sweep(db)

    def create_message(self, data, key):
        identifier(key)
        recipient = number(data.get('recipient'))
        body = data.get('body')
        if not isinstance(body, str) or not body.startswith(PREFIX) or not count(body)['supported']:
            raise Problem(400, 'ONLY_ONE_GSM7_SEGMENT_WITH_TEST_PREFIX')
        start, end = timestamp(data.get('not_before')), timestamp(data.get('expires_at'))
        fingerprint = digest(canonical(data))
        with self.transaction() as db:
            previous = db.execute('SELECT * FROM messages WHERE idempotency_key=?', (key,)).fetchone()
            if previous:
                if previous['fingerprint'] != fingerprint:
                    raise Problem(409, 'IDEMPOTENCY_CONFLICT')
                return {'message_id': previous['id']}
            now = self.clock()
            if end <= max(start, now) or end > now + 86400:
                raise Problem(400, 'INVALID_VALIDITY_MAX_24_HOURS')
            if not db.execute('SELECT 1 FROM allowlist WHERE number=?', (recipient,)).fetchone():
                raise Problem(400, 'RECIPIENT_NOT_ALLOWED')
            self.sweep(db)
            config = db.execute('SELECT * FROM config').fetchone()
            pending = db.execute("SELECT count(*) FROM messages WHERE status IN ('QUEUED','CLAIMED','SENDING','FAILED_RETRYABLE')").fetchone()[0]
            if pending >= config['queue_limit']:
                raise Problem(429, 'QUEUE_LIMIT')
            mid = uid()
            db.execute('INSERT INTO messages(id,idempotency_key,fingerprint,recipient,body,status,not_before,expires,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?)',
                       (mid, key, fingerprint, recipient, body, 'QUEUED', start, end, now, now))
            self.event(db, db.execute('SELECT * FROM messages WHERE id=?', (mid,)).fetchone(), 'QUEUED')
            return {'message_id': mid}

    def auth_limit(self, key):
        with self.transaction() as db:
            now = self.clock()
            row = db.execute('SELECT * FROM auth_limits WHERE key=?', (key,)).fetchone()
            if row and row['window'] > now - 60 and row['count'] >= 10:
                raise Problem(429, 'AUTH_RATE_LIMIT')
            attempts = row['count'] + 1 if row and row['window'] > now - 60 else 1
            start = row['window'] if attempts > 1 else now
            db.execute('INSERT OR REPLACE INTO auth_limits VALUES(?,?,?)', (key, start, attempts))

    def session(self):
        token = secrets.token_urlsafe(32)
        with self.transaction() as db:
            db.execute('INSERT INTO sessions VALUES(?,?)', (digest(token), self.clock() + 3600))
        return token

    def operator(self, token):
        with self.connect() as db:
            if not db.execute('SELECT 1 FROM sessions WHERE hash=? AND expires>?', (digest(token), self.clock())).fetchone():
                raise Problem(401, 'OPERATOR_LOGIN_REQUIRED')

    def pairing_code(self):
        code = secrets.token_urlsafe(18)
        with self.transaction() as db:
            db.execute('INSERT INTO pair_codes VALUES(?,?,0)', (digest(code), self.clock() + 300))
            self.audit(db, 'PAIR_CODE_CREATED', {})
        return {'code': code, 'expires_in': 300}

    def pair(self, data):
        code, name = data.get('code', ''), data.get('name', '')
        if not isinstance(code, str) or not isinstance(name, str) or not 1 <= len(name.strip()) <= 80:
            raise Problem(400, 'INVALID_PAIRING')
        with self.transaction() as db:
            row = db.execute('SELECT * FROM pair_codes WHERE hash=?', (digest(code),)).fetchone()
            if not row or row['used'] or row['expires'] <= self.clock():
                raise Problem(401, 'PAIR_CODE_INVALID')
            if db.execute('SELECT 1 FROM devices WHERE blocked=0').fetchone():
                raise Problem(409, 'ONE_DEVICE_ONLY_REVOKE_PREVIOUS')
            db.execute('UPDATE pair_codes SET used=1 WHERE hash=?', (digest(code),))
            token, did = secrets.token_urlsafe(32), uid()
            expires = self.clock() + 86400
            db.execute('INSERT INTO devices(id,name,token_hash,token_expires) VALUES(?,?,?,?)', (did, name.strip(), digest(token), expires))
            self.audit(db, 'PAIRED', {'device_id': did})
            return {'device_id': did, 'token': token, 'token_expires_at': iso(expires)}

    def device(self, db, did, token):
        d = db.execute('SELECT * FROM devices WHERE id=?', (did,)).fetchone()
        if not d or not hmac.compare_digest(d['token_hash'], digest(token)):
            raise Problem(401, 'DEVICE_TOKEN_INVALID')
        if d['blocked'] or d['token_expires'] <= self.clock():
            raise Problem(403, 'DEVICE_BLOCKED_OR_TOKEN_EXPIRED')
        return d

    def device_call(self, did, token, request_id, route, payload):
        identifier(request_id)
        self.maintenance()
        with self.transaction() as db:
            device = self.device(db, did, token)
            fingerprint = digest(canonical(payload))
            old = db.execute('SELECT * FROM requests WHERE device_id=? AND request_id=?', (did, request_id)).fetchone()
            if old:
                if old['route'] != route or old['fingerprint'] != fingerprint:
                    raise Problem(409, 'REQUEST_ID_CONFLICT')
                result = json.loads(old['response'])
                # Replayed dispatch acknowledgement never grants stale permission to send.
                if route.endswith('/events') and payload.get('type') == 'SENDING':
                    result = self.send_response(db, route.split('/')[2], device, payload)
                return old['status'], result
            code, result = self.dispatch(db, device, route, payload, request_id)
            # Claims contain recipient/body/lease: short-lived request cache, protected DB volume.
            db.execute('INSERT INTO requests VALUES(?,?,?,?,?,?,?)', (did, request_id, route, fingerprint, code, canonical(result), self.clock()))
            return code, result

    def send_response(self, db, mid, device, data):
        m = db.execute('SELECT * FROM messages WHERE id=?', (mid,)).fetchone()
        enabled = db.execute('SELECT enabled FROM config').fetchone()[0]
        return {'status': m['status'], 'send_authorized': bool(m['status'] == 'SENDING' and m['lease_expires'] > self.clock() and enabled and not device['paused'] and m['attempt_count']==data.get('attempt_no') and m['provider_message_id']==data.get('provider_message_id') and hmac.compare_digest(m['lease_hash'] or '', digest(data.get('lease_token',''))))}

    def dispatch(self, db, device, route, data, request_id):
        now, did = self.clock(), device['id']
        config = db.execute('SELECT * FROM config').fetchone()
        if route == f'/devices/{did}/heartbeat':
            subscription = data.get('subscription_id')
            if subscription is not None and (type(subscription) is not int or subscription < 0):
                raise Problem(400, 'INVALID_SIM')
            healthy = data.get('send_sms_granted') is True and data.get('sim_ready') is True and subscription is not None
            safe = {k: str(data.get(k, ''))[:80] for k in ('app_version', 'android_version', 'manufacturer', 'model')}
            db.execute('UPDATE devices SET heartbeat=?,healthy=?,subscription=?,diagnostics=? WHERE id=?', (now, healthy, subscription, canonical(safe), did))
            return 200, {'queue_enabled': bool(config['enabled'] and not device['paused']), 'min_interval_seconds': config['interval_seconds'], 'lease_seconds': 120}
        if route == '/queue/claim':
            if not config['enabled'] or device['paused']:
                raise Problem(503, 'QUEUE_PAUSED')
            if not device['heartbeat'] or device['heartbeat'] <= now - 90 or not device['healthy']:
                raise Problem(409, 'DEVICE_OFFLINE_OR_DEGRADED')
            if db.execute("SELECT 1 FROM messages WHERE device_id=? AND status IN ('CLAIMED','SENDING')", (did,)).fetchone():
                return 204, {}
            if device['last_start'] is not None and now < device['last_start'] + config['interval_seconds']:
                raise Problem(429, 'SEND_INTERVAL')
            day = now - now % 86400
            if db.execute("SELECT count(*) FROM events WHERE event_type='SENDING' AND received_at_server>=?", (day,)).fetchone()[0] >= config['daily_limit']:
                raise Problem(429, 'DAILY_LIMIT')
            m = db.execute("SELECT * FROM messages WHERE status='QUEUED' AND not_before<=? ORDER BY priority,created,rowid LIMIT 1", (now,)).fetchone()
            if not m:
                return 204, {}
            if not m['recipient'] or not db.execute('SELECT 1 FROM allowlist WHERE number=?', (m['recipient'],)).fetchone():
                db.execute("UPDATE messages SET status='FAILED_FINAL',last_error_code='ALLOWLIST_REMOVED',updated=? WHERE id=?", (now,m['id']))
                self.event(db,m,'FAILED_FINAL','ALLOWLIST_REMOVED')
                return 204, {}
            lease = secrets.token_urlsafe(32)
            db.execute("UPDATE messages SET status='CLAIMED',device_id=?,lease_hash=?,lease_expires=?,updated=? WHERE id=?", (did,digest(lease),now+120,now,m['id']))
            self.event(db,dict(m) | {'device_id': did},'CLAIMED')
            return 200, {'message_id': m['id'], 'recipient': m['recipient'], 'body': m['body'], 'lease_token': lease,
                         'lease_expires_at': iso(now+120), 'attempt_no': m['attempt_count']+1, 'segments': 1}
        match = re.fullmatch(r'/messages/([^/]+)/(events|lease)', route)
        if not match:
            raise Problem(404, 'NOT_FOUND')
        mid, action = match.groups()
        m = db.execute('SELECT * FROM messages WHERE id=?', (mid,)).fetchone()
        if not m or m['device_id'] != did:
            raise Problem(404, 'NOT_FOUND')
        if not isinstance(data.get('lease_token'), str) or not hmac.compare_digest(m['lease_hash'] or '', digest(data['lease_token'])):
            raise Problem(409, 'INVALID_LEASE')
        if action == 'lease':
            if m['status'] not in ('CLAIMED','SENDING') or m['lease_expires'] <= now:
                raise Problem(409, 'LEASE_EXPIRED')
            # A missing callback cannot keep SENDING alive indefinitely.
            until = now+120 if m['status']=='CLAIMED' else m['lease_expires']
            db.execute('UPDATE messages SET lease_expires=? WHERE id=?',(until,mid))
            return 200, {'lease_expires_at': iso(until)}
        eid = identifier(data.get('event_id'))
        old = db.execute('SELECT * FROM events WHERE event_id=?',(eid,)).fetchone()
        fingerprint = digest(canonical(data))
        if old:
            if old['fingerprint'] != fingerprint or old['message_id'] != mid:
                raise Problem(409,'EVENT_ID_CONFLICT')
            return 200, self.send_response(db,mid,device,data)
        kind, attempt = data.get('type'), data.get('attempt_no')
        if type(attempt) is not int:
            raise Problem(400,'INVALID_ATTEMPT')
        provider = identifier(data.get('provider_message_id'))
        device_time = data.get('occurred_at_device')
        timestamp(device_time)
        error = data.get('result_code')
        if error is not None and (not isinstance(error,str) or not re.fullmatch('[A-Z0-9_]{1,64}',error)):
            raise Problem(400,'INVALID_ERROR_CODE')
        state = m['status']
        if kind == 'SENDING':
            if state != 'CLAIMED' or m['lease_expires'] <= now or m['expires'] <= now:
                raise Problem(409,'SENDING_REQUIRES_CURRENT_CLAIM')
            if not config['enabled'] or device['paused']:
                raise Problem(503,'QUEUE_PAUSED')
            if not device['healthy'] or device['heartbeat'] <= now-90:
                raise Problem(409,'DEVICE_OFFLINE_OR_DEGRADED')
            if not db.execute('SELECT 1 FROM allowlist WHERE number=?',(m['recipient'],)).fetchone():
                raise Problem(409,'RECIPIENT_NOT_ALLOWED')
            if attempt != m['attempt_count']+1 or data.get('sim_subscription_id') != device['subscription'] or data.get('segments') != 1:
                raise Problem(409,'ATTEMPT_SIM_OR_SEGMENTS_MISMATCH')
            if device['last_start'] is not None and now < device['last_start'] + config['interval_seconds']:
                raise Problem(429,'SEND_INTERVAL')
            day = now-now%86400
            if db.execute("SELECT count(*) FROM events WHERE event_type='SENDING' AND received_at_server>=?",(day,)).fetchone()[0] >= config['daily_limit']:
                raise Problem(429,'DAILY_LIMIT')
            db.execute('UPDATE devices SET last_start=? WHERE id=?',(now,did))
            db.execute('UPDATE messages SET attempt_count=?,provider_message_id=?,sim_subscription_id=?,lease_expires=? WHERE id=?',(attempt,provider,device['subscription'],now+120,mid))
        else:
            if attempt != m['attempt_count'] or provider != m['provider_message_id']:
                raise Problem(409,'ATTEMPT_MISMATCH')
            if m['resolved']:
                raise Problem(409,'MANUALLY_RESOLVED')
            allowed = {'SENT': {'SENDING','UNKNOWN','DELIVERED'}, 'DELIVERED': {'SENDING','SENT','UNKNOWN'},
                       'FAILED_RETRYABLE': {'SENDING'}, 'FAILED_FINAL': {'SENDING'}, 'UNKNOWN': {'SENDING'}}
            if kind not in allowed or state not in allowed[kind]:
                raise Problem(409,'INVALID_TRANSITION')
            if kind in ('SENT','DELIVERED') and error != 'RESULT_OK':
                raise Problem(400,'SUCCESS_REQUIRES_RESULT_OK')
            if kind == 'FAILED_RETRYABLE':
                if error not in ('RADIO_OFF','NO_SERVICE'):
                    raise Problem(400,'UNSAFE_RETRY')
                if attempt >= 3 or not config['retry_enabled']:
                    kind = 'FAILED_FINAL'
                else:
                    db.execute('UPDATE messages SET not_before=? WHERE id=?',(now+(60 if attempt==1 else 300),mid))
        new_state = 'DELIVERED' if state=='DELIVERED' and kind=='SENT' else kind
        db.execute('UPDATE messages SET status=?,updated=?,last_error_code=? WHERE id=?',(new_state,now,error if kind.startswith('FAILED') or kind=='UNKNOWN' else None,mid))
        if kind == 'SENT':
            db.execute('UPDATE messages SET sent_at=COALESCE(sent_at,?) WHERE id=?',(timestamp(device_time),mid))
        if kind=='DELIVERED':
            db.execute('UPDATE messages SET delivered_at=? WHERE id=?',(timestamp(device_time),mid))
        metadata = {'provider_message_id':provider,'segments':1}
        db.execute('INSERT INTO events VALUES(?,?,?,?,?,?,?,?,?,?,?)',(eid,mid,kind,device_time,now,did,attempt,error,canonical(metadata),fingerprint,request_id))
        return 200, self.send_response(db,mid,device,data)

    def control(self, action, data):
        with self.transaction() as db:
            if action=='config':
                for key, low, high in [('queue_limit',1,10),('daily_limit',1,200),('interval_seconds',15,3600)]:
                    value=data.get(key)
                    if type(value) is not int or not low <= value <= high:
                        raise Problem(400,'INVALID_CONFIG')
                if type(data.get('enabled')) is not bool or type(data.get('retry_enabled', False)) is not bool:
                    raise Problem(400,'INVALID_CONFIG')
                db.execute('UPDATE config SET enabled=?,queue_limit=?,daily_limit=?,interval_seconds=?,retry_enabled=?', (data['enabled'],data['queue_limit'],data['daily_limit'],data['interval_seconds'],data.get('retry_enabled',False)))
                self.audit(db,'CONFIG_CHANGED',data)
            elif action=='allowlist':
                value=number(data.get('number'))
                if data.get('remove') is True:
                    db.execute('DELETE FROM allowlist WHERE number=?',(value,))
                else:
                    db.execute('INSERT INTO allowlist VALUES(?,?) ON CONFLICT(number) DO UPDATE SET created=excluded.created',(value,self.clock()))
                self.audit(db,'ALLOWLIST_CHANGED',{'number':mask(value),'removed':data.get('remove') is True})
            elif action in ('pause','resume','revoke'):
                did=data.get('device_id')
                if not db.execute('SELECT 1 FROM devices WHERE id=?',(did,)).fetchone():
                    raise Problem(404,'NOT_FOUND')
                if action=='revoke':
                    db.execute('UPDATE devices SET blocked=1,paused=1 WHERE id=?',(did,))
                else:
                    db.execute('UPDATE devices SET paused=? WHERE id=?',(action=='pause',did))
                self.audit(db,action.upper(),{'device_id':did})
            elif action in ('cancel','resolve'):
                m=db.execute('SELECT * FROM messages WHERE id=?',(data.get('message_id'),)).fetchone()
                if not m:
                    raise Problem(404,'NOT_FOUND')
                if action=='cancel':
                    if m['status'] not in ('QUEUED','FAILED_RETRYABLE'):
                        raise Problem(409,'CANNOT_CANCEL_AFTER_CLAIM')
                    state='CANCELLED'
                else:
                    if m['status']!='UNKNOWN' or data.get('status') not in ('SENT','DELIVERED','FAILED_FINAL'):
                        raise Problem(409,'INVALID_RESOLUTION')
                    reason=data.get('reason','')
                    if not isinstance(reason,str) or not 3<=len(reason.strip())<=200:
                        raise Problem(400,'REASON_REQUIRED_NO_PERSONAL_DATA')
                    state=data['status']
                    db.execute('UPDATE messages SET resolved=1 WHERE id=?',(m['id'],))
                    self.audit(db,'UNKNOWN_RESOLVED',{'message_id':m['id'],'status':state,'reason':reason})
                db.execute('UPDATE messages SET status=?,updated=? WHERE id=?',(state,self.clock(),m['id']))
                self.event(db,m,'OPERATOR_'+state)
            else:
                raise Problem(404,'NOT_FOUND')
        return {'ok':True}

    def snapshot(self, status=None):
        self.maintenance()
        with self.connect() as db:
            config=dict(db.execute('SELECT * FROM config').fetchone())
            rows=db.execute('SELECT * FROM messages WHERE (? IS NULL OR status=?) ORDER BY created DESC,rowid DESC LIMIT 500',(status,status)).fetchall()
            messages=[]
            for row in rows:
                m={k:row[k] for k in ('id','status','attempt_count','provider_message_id','sim_subscription_id','last_error_code')}
                m.update({'recipient':mask(row['recipient']), 'created_at':iso(row['created']),'sent_at':iso(row['sent_at']),'delivered_at':iso(row['delivered_at'])})
                messages.append(m)
            devices=[]
            for row in db.execute('SELECT * FROM devices'):
                state='BLOCKED' if row['blocked'] or row['token_expires']<=self.clock() else 'PAUSED' if row['paused'] else 'OFFLINE' if not row['heartbeat'] or row['heartbeat']<=self.clock()-90 else 'DEGRADED' if not row['healthy'] else 'ONLINE'
                devices.append({'id':row['id'],'name':row['name'],'state':state,'heartbeat':iso(row['heartbeat']),'subscription':row['subscription'],'diagnostics':json.loads(row['diagnostics'])})
            metrics={r[0]:r[1] for r in db.execute('SELECT status,count(*) FROM messages GROUP BY status')}
            metrics['average_seconds_to_sent']=db.execute('SELECT avg(sent_at-created) FROM messages WHERE sent_at IS NOT NULL').fetchone()[0]
            metrics['average_seconds_to_delivered']=db.execute('SELECT avg(delivered_at-created) FROM messages WHERE delivered_at IS NOT NULL').fetchone()[0]
            metrics['oldest_queued_seconds']=db.execute("SELECT ?-min(created) FROM messages WHERE status='QUEUED'",(self.clock(),)).fetchone()[0]
            alerts=[]
            if metrics.get('UNKNOWN',0): alerts.append('Wiadomości UNKNOWN wymagają analizy; nie ponawiaj ich automatycznie.')
            if any(not r['heartbeat'] or r['heartbeat']<self.clock()-120 for r in db.execute('SELECT heartbeat FROM devices WHERE blocked=0')): alerts.append('Brak heartbeat urządzenia ponad 2 minuty.')
            if db.execute("SELECT count(*) FROM events WHERE event_type LIKE 'FAILED%' AND received_at_server>?",(self.clock()-600,)).fetchone()[0]>=3: alerts.append('Powtarzające się błędy wysyłki.')
            return {'config':config,'devices':devices,'messages':messages,'allowlist':[mask(r[0]) for r in db.execute('SELECT number FROM allowlist')],'metrics':metrics,'alerts':alerts}

    def history(self, mid):
        with self.connect() as db:
            if not db.execute('SELECT 1 FROM messages WHERE id=?',(mid,)).fetchone():
                raise Problem(404,'NOT_FOUND')
            return [dict(r) for r in db.execute('SELECT event_id,event_type,occurred_at_device,received_at_server,device_id,attempt_no,error_code,metadata,request_id FROM events WHERE message_id=? ORDER BY received_at_server,rowid',(mid,))]
