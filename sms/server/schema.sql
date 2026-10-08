PRAGMA journal_mode=WAL;
CREATE TABLE IF NOT EXISTS config (id INTEGER PRIMARY KEY CHECK(id=1), enabled INTEGER NOT NULL DEFAULT 0,
 interval_seconds INTEGER NOT NULL DEFAULT 15, queue_limit INTEGER NOT NULL DEFAULT 1,
 daily_limit INTEGER NOT NULL DEFAULT 50, retry_enabled INTEGER NOT NULL DEFAULT 0);
INSERT OR IGNORE INTO config(id) VALUES(1);
CREATE TABLE IF NOT EXISTS allowlist (number TEXT PRIMARY KEY, created REAL NOT NULL);
CREATE TABLE IF NOT EXISTS devices (id TEXT PRIMARY KEY, name TEXT NOT NULL, token_hash TEXT NOT NULL,
 token_expires REAL NOT NULL, blocked INTEGER NOT NULL DEFAULT 0, paused INTEGER NOT NULL DEFAULT 1,
 heartbeat REAL, healthy INTEGER NOT NULL DEFAULT 0, subscription INTEGER, diagnostics TEXT NOT NULL DEFAULT '{}', last_start REAL);
CREATE TABLE IF NOT EXISTS pair_codes (hash TEXT PRIMARY KEY, expires REAL NOT NULL, used INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS sessions (hash TEXT PRIMARY KEY, expires REAL NOT NULL);
CREATE TABLE IF NOT EXISTS auth_limits (key TEXT PRIMARY KEY, window REAL NOT NULL, count INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS messages (id TEXT PRIMARY KEY, idempotency_key TEXT UNIQUE NOT NULL, fingerprint TEXT NOT NULL,
 recipient TEXT, body TEXT, status TEXT NOT NULL, priority INTEGER NOT NULL DEFAULT 100,
 not_before REAL NOT NULL, expires REAL NOT NULL, created REAL NOT NULL, updated REAL NOT NULL,
 device_id TEXT REFERENCES devices(id), lease_hash TEXT, lease_expires REAL,
 attempt_count INTEGER NOT NULL DEFAULT 0, provider_message_id TEXT, sim_subscription_id INTEGER,
 sent_at REAL, delivered_at REAL, last_error_code TEXT, resolved INTEGER NOT NULL DEFAULT 0);
CREATE INDEX IF NOT EXISTS queue_index ON messages(status,not_before,created);
CREATE TABLE IF NOT EXISTS events (event_id TEXT PRIMARY KEY, message_id TEXT NOT NULL REFERENCES messages(id),
 event_type TEXT NOT NULL, occurred_at_device TEXT, received_at_server REAL NOT NULL, device_id TEXT,
 attempt_no INTEGER NOT NULL, error_code TEXT, metadata TEXT NOT NULL, fingerprint TEXT NOT NULL, request_id TEXT NOT NULL);
CREATE TRIGGER IF NOT EXISTS events_no_update BEFORE UPDATE ON events BEGIN SELECT RAISE(ABORT,'append-only events'); END;
CREATE TRIGGER IF NOT EXISTS events_no_delete BEFORE DELETE ON events BEGIN SELECT RAISE(ABORT,'append-only events'); END;
CREATE TABLE IF NOT EXISTS requests (device_id TEXT NOT NULL, request_id TEXT NOT NULL, route TEXT NOT NULL,
 fingerprint TEXT NOT NULL, status INTEGER NOT NULL, response TEXT NOT NULL, created REAL NOT NULL,
 PRIMARY KEY(device_id,request_id));
CREATE TABLE IF NOT EXISTS operator_events (id TEXT PRIMARY KEY, action TEXT NOT NULL, received REAL NOT NULL, metadata TEXT NOT NULL);
