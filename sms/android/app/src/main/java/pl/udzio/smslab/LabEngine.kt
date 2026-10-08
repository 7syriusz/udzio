package pl.udzio.smslab

import android.Manifest
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.ConnectivityManager
import android.os.Build
import android.telephony.SmsManager
import android.telephony.SubscriptionManager
import org.json.JSONObject
import java.time.Instant
import java.util.UUID

class LabEngine(private val context: Context) {
    private val journal = Journal.get(context)
    private val dao = journal.dao()
    private val vault = Vault(context)
    private var heartbeatAt = 0L
    private var queueEnabled = false

    fun diagnostic(code: String) {
        dao.diagnostic(Diagnostic(code = code.take(100)))
        dao.trimDiagnostics()
    }
    fun ready(): Boolean = context.checkSelfPermission(Manifest.permission.SEND_SMS) == PackageManager.PERMISSION_GRANTED &&
        context.checkSelfPermission(Manifest.permission.READ_PHONE_STATE) == PackageManager.PERMISSION_GRANTED &&
        context.getSystemService(SubscriptionManager::class.java).activeSubscriptionInfoList?.any { it.subscriptionId == vault.subscription } == true

    fun tick(send: Boolean) = synchronized(lock) {
        val credentials = vault.load() ?: return@synchronized
        val api = Api(credentials)
        dao.retention(System.currentTimeMillis() - 30L * 86400000)
        if (!flush(api)) return@synchronized
        val active = dao.active()
        if (active != null && DispatchPolicy.recoverAsUnknown(active.stage, active.started, System.currentTimeMillis())) {
            record(active.key, "UNKNOWN", "RESULT_UNCERTAIN")
            flush(api)
            return@synchronized
        }
        if (!send) return@synchronized
        if (System.currentTimeMillis() - heartbeatAt >= 30000) {
            val heartbeat = JSONObject().put("subscription_id", vault.subscription.takeIf { it >= 0 } ?: JSONObject.NULL)
                .put("send_sms_granted", context.checkSelfPermission(Manifest.permission.SEND_SMS) == PackageManager.PERMISSION_GRANTED)
                .put("sim_ready", ready()).put("app_version", BuildConfig.VERSION_NAME)
                .put("android_version", Build.VERSION.RELEASE).put("manufacturer", Build.MANUFACTURER).put("model", Build.MODEL)
            val response = api.post("/devices/${credentials.getString("device_id")}/heartbeat", heartbeat, UUID.randomUUID().toString())
            queueEnabled = response.getBoolean("queue_enabled")
            heartbeatAt = System.currentTimeMillis()
            vault.lastHeartbeat = heartbeatAt
            vault.queueEnabled = queueEnabled
            vault.minInterval = response.optInt("min_interval_seconds", 15)
        }
        if (!GatewayService.running || !queueEnabled || !ready()) return@synchronized
        if (active != null) {
            if (active.stage == "CLAIMED") dispatch(active, api)
            return@synchronized
        }
        val request = vault.claimRequest ?: UUID.randomUUID().toString().also { vault.claimRequest = it }
        val claim = try { api.post("/queue/claim", JSONObject(), request) } catch (error: ApiFailure) {
            if (error.status in listOf(409, 429, 503)) vault.claimRequest = null
            throw error
        }
        if (claim.has("message_id")) {
            val mid = claim.getString("message_id")
            val attempt = claim.getInt("attempt_no")
            dao.insert(Task(key = "$mid:$attempt:${UUID.nameUUIDFromBytes(claim.getString("lease_token").toByteArray())}", messageId = mid, attempt = attempt, lease = claim.getString("lease_token"),
                leaseUntil = Instant.parse(claim.getString("lease_expires_at")).toEpochMilli(), provider = UUID.randomUUID().toString(),
                recipient = claim.getString("recipient"), body = claim.getString("body")))
        }
        vault.claimRequest = null
        dao.active()?.takeIf { it.stage == "CLAIMED" }?.let { dispatch(it, api) }
    }

    private fun dispatch(task: Task, api: Api) {
        if (System.currentTimeMillis() - vault.lastDispatch < vault.minInterval * 1000L) return
        if (task.leaseUntil <= System.currentTimeMillis()) {
            dao.stage(task.key, "FINAL"); dao.redact(task.key); diagnostic("CLAIM_EXPIRED_NO_SEND"); return
        }
        val sim = vault.subscription
        val manager = context.getSystemService(SmsManager::class.java).createForSubscriptionId(sim)
        val prepared = task.copy(stage = "PREPARED", started = System.currentTimeMillis(), subscription = sim)
        val gate = event(prepared, "SENDING", null).put("sim_subscription_id", sim).put("segments", 1)
        journal.runInTransaction {
            check(dao.advance(task.key, "CLAIMED", "PREPARED", prepared.started, sim) == 1)
            dao.event(Outbox(eventId = gate.getString("event_id"), messageId = task.messageId, json = gate.toString()))
        }
        // No network ambiguity can reach SmsManager. Recovery only reports UNKNOWN; it never dispatches PREPARED again.
        val permission = api.post("/messages/${task.messageId}/events", gate, gate.getString("event_id"))
        dao.pending().firstOrNull { it.eventId == gate.getString("event_id") }?.let { dao.eventState(it.sequence, "SYNCED") }
        if (!DispatchPolicy.mayInvoke(prepared.stage, permission.optBoolean("send_authorized"), task.leaseUntil, System.currentTimeMillis())) {
            record(task.key, "UNKNOWN", "DISPATCH_NOT_AUTHORIZED"); return
        }
        if (manager.divideMessage(task.body).size != 1) {
            record(task.key, "FAILED_FINAL", "SEGMENT_MISMATCH"); return
        }
        if (!GatewayService.running || !ready() || vault.subscription != sim) {
            record(task.key, "FAILED_FINAL", "SIM_OR_PERMISSION_CHANGED"); return
        }
        // Persist the irreversible boundary BEFORE the modem call, including when the process dies in this gap.
        if (dao.advance(task.key, "PREPARED", "DISPATCHED", prepared.started, sim) != 1) return
        vault.lastDispatch = System.currentTimeMillis()
        try {
            manager.sendTextMessage(task.recipient, null, task.body, callback(task, "SENT"), callback(task, "DELIVERED"))
            dao.redact(task.key)
        } catch (_: Exception) {
            record(task.key, "UNKNOWN", "SMS_MANAGER_EXCEPTION")
        }
    }

    private fun callback(task: Task, kind: String): PendingIntent {
        val intent = Intent(context, SmsResultReceiver::class.java).setAction("pl.udzio.smslab.${task.provider}.$kind")
            .putExtra("task", task.key).putExtra("kind", kind)
        val mutability = if (kind == "DELIVERED") PendingIntent.FLAG_MUTABLE else PendingIntent.FLAG_IMMUTABLE
        return PendingIntent.getBroadcast(context, 0, intent, PendingIntent.FLAG_UPDATE_CURRENT or mutability)
    }

    private fun event(task: Task, kind: String, code: String?): JSONObject = JSONObject()
        .put("event_id", UUID.nameUUIDFromBytes("${task.provider}:$kind".toByteArray()).toString())
        .put("type", kind).put("attempt_no", task.attempt).put("lease_token", task.lease)
        .put("provider_message_id", task.provider).put("occurred_at_device", Instant.now().toString())
        .apply { if (code != null) put("result_code", code) }

    fun record(key: String, kind: String, code: String) {
        journal.runInTransaction {
            val task = dao.task(key) ?: return@runInTransaction
            val json = event(task, kind, code)
            if (dao.event(Outbox(eventId = json.getString("event_id"), messageId = task.messageId, json = json.toString())) != -1L) {
                dao.stage(key, DispatchPolicy.callbackStage(task.stage, kind))
                dao.redact(key)
                diagnostic("$kind:$code:${task.messageId.take(8)}")
            }
        }
    }

    private fun flush(api: Api): Boolean {
        for (event in dao.pending()) {
            try {
                api.post("/messages/${event.messageId}/events", JSONObject(event.json), event.eventId)
                dao.eventState(event.sequence, "SYNCED")
            } catch (error: ApiFailure) {
                if (error.status in listOf(400, 404, 409)) {
                    dao.eventState(event.sequence, "REJECTED")
                    diagnostic("EVENT_REJECTED_${error.status}")
                } else throw error
            }
        }
        return dao.pendingCount() == 0
    }

    companion object { val lock = Any() }
}
