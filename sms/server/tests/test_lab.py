import concurrent.futures
import json
import multiprocessing
import os
import tempfile
import unittest
from pathlib import Path

from app import Application, password_hash
from lab import Lab, Problem, iso, uid
from segments import PREFIX, count


def race_claim(args):
    path, did, token, now = args
    try:
        return Lab(path, lambda: now).device_call(did, token, uid(), '/queue/claim', {})[0]
    except Problem as e:
        return e.status


class LabTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.now = 1791450000.0
        self.lab = Lab(Path(self.tmp.name) / 'test.sqlite3', lambda: self.now)
        self.lab.control('allowlist', {'number': '+48123456789'})
        self.configure()
        self.credentials = self.lab.pair({'code': self.lab.pairing_code()['code'], 'name': 'Test phone'})
        self.did, self.token = self.credentials['device_id'], self.credentials['token']
        self.lab.control('resume', {'device_id': self.did})
        self.heartbeat()

    def tearDown(self):
        self.tmp.cleanup()

    def configure(self, **values):
        self.lab.control('config', {'enabled': True, 'interval_seconds': 15, 'queue_limit': 10, 'daily_limit': 50, 'retry_enabled': True} | values)

    def call(self, route, payload=None, request=None, token=None):
        return self.lab.device_call(self.did, token or self.token, request or uid(), route, payload or {})

    def heartbeat(self):
        return self.call(f'/devices/{self.did}/heartbeat', {'subscription_id': 3, 'send_sms_granted': True, 'sim_ready': True})

    def data(self, **changes):
        return {'recipient': '+48123456789', 'body': PREFIX+'Test', 'not_before': iso(self.now), 'expires_at': iso(self.now+3600)} | changes

    def create(self, **changes):
        return self.lab.create_message(self.data(**changes), uid())['message_id']

    def claim(self):
        code, job = self.call('/queue/claim')
        self.assertEqual(200, code)
        job['provider'] = uid()
        return job

    def event_data(self, job, kind, **changes):
        return {'event_id': uid(), 'type': kind, 'attempt_no': job['attempt_no'], 'lease_token': job['lease_token'],
                'provider_message_id': job['provider'], 'occurred_at_device': iso(self.now),
                'result_code': 'RESULT_OK', 'segments': 1, 'sim_subscription_id': 3} | changes

    def event(self, job, kind, **changes):
        return self.call(f"/messages/{job['message_id']}/events", self.event_data(job, kind, **changes))

    def state(self, mid):
        with self.lab.connect() as db:
            return dict(db.execute('SELECT * FROM messages WHERE id=?', (mid,)).fetchone())

    def fails(self, status, operation):
        with self.assertRaises(Problem) as failure:
            operation()
        self.assertEqual(status, failure.exception.status)

    def test_full_lifecycle_and_idempotent_callback(self):
        mid=self.create(); job=self.claim()
        self.assertEqual('CLAIMED', self.state(mid)['status'])
        self.assertTrue(self.event(job,'SENDING')[1]['send_authorized'])
        payload=self.event_data(job,'SENT'); request=uid()
        route=f'/messages/{mid}/events'
        self.call(route,payload,request); self.call(route,payload,request); self.call(route,payload)
        self.assertEqual('SENT',self.state(mid)['status'])
        self.event(job,'DELIVERED')
        self.assertEqual('DELIVERED',self.state(mid)['status'])
        self.assertEqual(1,len([e for e in self.lab.history(mid) if e['event_type']=='SENT']))

    def test_server_restart_preserves_claim_and_callback_idempotency(self):
        mid=self.create(); job=self.claim(); self.event(job,'SENDING')
        data=self.event_data(job,'SENT'); request=uid()
        self.call(f'/messages/{mid}/events',data,request)
        self.lab=Lab(self.lab.path,lambda:self.now)
        self.call(f'/messages/{mid}/events',data,request)
        self.assertEqual('SENT',self.state(mid)['status'])
        self.assertEqual(1,len([e for e in self.lab.history(mid) if e['event_type']=='SENT']))

    def test_create_idempotency_and_changed_payload_conflict(self):
        data=self.data(); key=uid()
        one=self.lab.create_message(data,key)
        self.assertEqual(one,self.lab.create_message(data,key))
        self.fails(409,lambda:self.lab.create_message(data | {'body':PREFIX+'other'},key))

    def test_allowlist_prefix_unicode_length_and_dates(self):
        for changes in [{'recipient':'+48999999999'},{'recipient':'123'},{'body':'no prefix'},
                        {'body':PREFIX+'ą'},{'body':PREFIX+'a'*160},{'expires_at':iso(self.now)},
                        {'not_before':'2026-10-08T12:00:00'}]:
            with self.subTest(changes=changes): self.fails(400,lambda:self.create(**changes))

    def test_first_trial_disables_automatic_retries(self):
        self.configure(retry_enabled=False)
        mid=self.create(); job=self.claim(); self.event(job,'SENDING')
        self.event(job,'FAILED_RETRYABLE',result_code='RADIO_OFF')
        self.now+=301; self.heartbeat()
        self.assertEqual(204,self.call('/queue/claim')[0])
        self.assertEqual('FAILED_FINAL',self.state(mid)['status'])

    def test_one_segment_counter_counts_extension_and_utf16(self):
        self.assertEqual(160,count('^'*80)['units'])
        self.assertEqual(2,count('^'*81)['segments'])
        self.assertEqual('Unicode',count('Zażółć')['encoding'])
        self.assertEqual(72,count('😀'*36)['units'])
        self.assertEqual(2,count('😀'*36)['segments'])

    def test_concurrent_claim_has_one_winner_across_processes(self):
        self.create()
        args=(self.lab.path,self.did,self.token,self.now)
        with concurrent.futures.ProcessPoolExecutor(2) as pool:
            results=list(pool.map(race_claim,[args,args]))
        self.assertEqual([200,204],sorted(results))

    def test_lost_claim_response_returns_same_lease(self):
        self.create(); request=uid()
        one=self.call('/queue/claim',request=request)
        self.assertEqual(one,self.call('/queue/claim',request=request))
        self.fails(409,lambda:self.call('/queue/claim',{'changed':True},request))

    def test_expired_claim_can_be_reclaimed_but_old_lease_cannot_send(self):
        mid=self.create(); old=self.claim(); self.now+=121; self.heartbeat(); new=self.claim()
        self.assertNotEqual(old['lease_token'],new['lease_token'])
        self.fails(409,lambda:self.event(old,'SENDING'))
        self.assertTrue(self.event(new,'SENDING')[1]['send_authorized'])
        self.assertEqual(1,self.state(mid)['attempt_count'])

    def test_sending_timeout_is_unknown_and_cannot_be_reclaimed(self):
        mid=self.create(); job=self.claim(); self.event(job,'SENDING'); self.now+=121; self.heartbeat()
        self.assertEqual(204,self.call('/queue/claim')[0])
        self.assertEqual('UNKNOWN',self.state(mid)['status'])
        self.event(job,'SENT')
        self.assertEqual('SENT',self.state(mid)['status'])

    def test_sent_without_delivery_never_retries(self):
        mid=self.create(); job=self.claim(); self.event(job,'SENDING'); self.event(job,'SENT')
        self.now+=7200; self.heartbeat()
        self.assertEqual(204,self.call('/queue/claim')[0])
        self.assertEqual('SENT',self.state(mid)['status'])

    def test_retry_only_known_radio_errors_and_maximum_two_retries(self):
        mid=self.create()
        for attempt,delay in [(1,60),(2,300),(3,0)]:
            self.heartbeat(); job=self.claim(); self.event(job,'SENDING')
            self.fails(400,lambda:self.event(job,'FAILED_RETRYABLE',result_code='GENERIC_FAILURE'))
            self.event(job,'FAILED_RETRYABLE',result_code='RADIO_OFF')
            self.assertEqual(attempt,self.state(mid)['attempt_count'])
            if delay:
                self.assertEqual('FAILED_RETRYABLE',self.state(mid)['status'])
                self.now+=delay-1; self.heartbeat(); self.assertEqual(204,self.call('/queue/claim')[0]); self.now+=1
        self.assertEqual('FAILED_FINAL',self.state(mid)['status'])

    def test_pause_kill_and_revocation_block_dispatch(self):
        self.create(); job=self.claim(); self.configure(enabled=False)
        self.fails(503,lambda:self.event(job,'SENDING'))
        self.configure(); self.lab.control('pause',{'device_id':self.did})
        self.fails(503,lambda:self.event(job,'SENDING'))
        self.lab.control('revoke',{'device_id':self.did})
        self.fails(403,lambda:self.heartbeat())

    def test_offline_and_invalid_token(self):
        self.create(); self.now+=90
        self.fails(409,lambda:self.call('/queue/claim'))
        self.assertEqual('OFFLINE',self.lab.snapshot()['devices'][0]['state'])
        self.fails(401,lambda:self.call('/queue/claim',token='wrong'))

    def test_cancel_queued_but_not_claimed_or_sending(self):
        mid=self.create(); self.lab.control('cancel',{'message_id':mid})
        self.assertEqual(204,self.call('/queue/claim')[0])
        mid=self.create(); job=self.claim()
        self.fails(409,lambda:self.lab.control('cancel',{'message_id':mid}))
        self.event(job,'SENDING')
        self.fails(409,lambda:self.lab.control('cancel',{'message_id':mid}))

    def test_queue_and_daily_limits_and_interval(self):
        self.configure(queue_limit=1,daily_limit=1)
        self.create(); self.fails(429,lambda:self.create())
        job=self.claim(); self.event(job,'SENDING'); self.event(job,'SENT')
        self.create(); self.fails(429,lambda:self.call('/queue/claim'))
        self.now+=16; self.heartbeat(); self.fails(429,lambda:self.call('/queue/claim'))

    def test_series_of_fifty_preserves_order_and_no_duplicate_starts(self):
        ids=[]
        for batch in range(5):
            ids.extend(self.create(body=PREFIX+f'Batch {batch} item {i}') for i in range(10))
            for i in range(10):
                self.heartbeat(); job=self.claim(); self.assertEqual(ids[batch*10+i],job['message_id'])
                self.event(job,'SENDING'); self.event(job,'SENT'); self.event(job,'DELIVERED'); self.now+=15
        snapshot=self.lab.snapshot()
        self.assertEqual(50,snapshot['metrics']['DELIVERED'])
        with self.lab.connect() as db:
            self.assertEqual(50,db.execute("SELECT count(*) FROM events WHERE event_type='SENDING'").fetchone()[0])

    def test_replay_of_old_dispatch_ack_never_authorizes_later_attempt(self):
        self.create(); job=self.claim(); data=self.event_data(job,'SENDING'); request=uid(); route=f"/messages/{job['message_id']}/events"
        self.call(route,data,request); self.event(job,'FAILED_RETRYABLE',result_code='NO_SERVICE')
        self.now+=60; self.heartbeat(); new=self.claim(); self.event(new,'SENDING')
        self.assertFalse(self.call(route,data,request)[1]['send_authorized'])

    def test_delivered_before_sent_does_not_regress(self):
        mid=self.create(); job=self.claim(); self.event(job,'SENDING'); self.event(job,'DELIVERED')
        self.assertIsNone(self.state(mid)['sent_at'])
        self.event(job,'SENT',occurred_at_device=iso(self.now-10))
        self.assertEqual('DELIVERED',self.state(mid)['status'])
        self.assertEqual(self.now-10,self.state(mid)['sent_at'])

    def test_wrong_sim_attempt_event_collision_and_foreign_device(self):
        mid=self.create(); job=self.claim()
        self.fails(409,lambda:self.event(job,'SENDING',sim_subscription_id=99))
        self.event(job,'SENDING'); self.fails(409,lambda:self.event(job,'SENT',attempt_no=5))
        data=self.event_data(job,'SENT'); self.call(f'/messages/{mid}/events',data)
        self.fails(409,lambda:self.call(f'/messages/{mid}/events',data|{'type':'DELIVERED'}))
        self.lab.control('revoke',{'device_id':self.did})
        other=self.lab.pair({'code':self.lab.pairing_code()['code'],'name':'Replacement'})
        self.fails(404,lambda:self.lab.device_call(other['device_id'],other['token'],uid(),f'/messages/{mid}/events',data))

    def test_pairing_code_single_use_and_expiry(self):
        code=self.lab.pairing_code()['code']; self.now+=301
        self.fails(401,lambda:self.lab.pair({'code':code,'name':'Expired'}))
        self.lab.control('revoke',{'device_id':self.did})
        code=self.lab.pairing_code()['code']; self.lab.pair({'code':code,'name':'New'})
        self.fails(401,lambda:self.lab.pair({'code':code,'name':'Again'}))

    def test_retention_masks_export_and_preserves_event_facts(self):
        mid=self.create()
        self.assertNotIn('+48123456789',json.dumps(self.lab.snapshot()))
        self.now+=31*86400; self.lab.maintenance()
        self.assertIsNone(self.state(mid)['body']); self.assertIsNone(self.state(mid)['recipient'])
        self.assertGreater(len(self.lab.history(mid)),0)

    def test_operator_login_and_access_boundaries(self):
        app=Application(self.lab,password_hash('test-password-only'))
        self.fails(401,lambda:app.route('GET','/lab/v1/messages',{},{}))
        self.fails(401,lambda:app.route('POST','/lab/v1/session',{}, {'password':'wrong'}))
        _,login=app.route('POST','/lab/v1/session',{}, {'password':'test-password-only'})
        headers={'Authorization':'Bearer '+login['token']}
        self.assertEqual(200,app.route('GET','/lab/v1/messages',headers,{})[0])
        self.fails(401,lambda:app.route('GET','/lab/v1/messages',{'Authorization':'Bearer '+self.token},{}))
        app.route('POST','/lab/v1/logout',headers,{})
        self.fails(401,lambda:app.route('GET','/lab/v1/messages',headers,{}))

    def test_unknown_resolution_requires_reason_and_never_requeues(self):
        mid=self.create(); job=self.claim(); self.event(job,'SENDING'); self.event(job,'UNKNOWN',result_code='RESULT_UNCERTAIN')
        self.fails(409,lambda:self.lab.control('resolve',{'message_id':mid,'status':'QUEUED','reason':'Manual'}))
        self.fails(400,lambda:self.lab.control('resolve',{'message_id':mid,'status':'SENT','reason':''}))
        self.lab.control('resolve',{'message_id':mid,'status':'SENT','reason':'Potwierdzono na urządzeniu'})
        self.assertEqual('SENT',self.state(mid)['status'])
        self.fails(409,lambda:self.event(job,'DELIVERED'))

    def test_sending_ack_after_lease_expiry_cannot_authorize(self):
        self.create(); job=self.claim(); data=self.event_data(job,'SENDING'); request=uid(); route=f"/messages/{job['message_id']}/events"
        self.call(route,data,request); self.now+=121
        self.assertFalse(self.call(route,data,request)[1]['send_authorized'])

    def test_allowlist_removal_before_dispatch_and_lease_renewal(self):
        self.create(); job=self.claim(); self.now+=30
        self.assertIn('lease_expires_at',self.call(f"/messages/{job['message_id']}/lease",{'lease_token':job['lease_token']})[1])
        self.lab.control('allowlist',{'number':'+48123456789','remove':True})
        self.fails(409,lambda:self.event(job,'SENDING'))


if __name__=='__main__':
    unittest.main()
