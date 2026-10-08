package pl.udzio.smslab

import android.app.*
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.IBinder
import android.telephony.SmsManager
import android.telephony.SmsMessage
import androidx.work.*
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit

class LabApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        getSystemService(NotificationManager::class.java).createNotificationChannel(NotificationChannel("gateway", "Test bramki SMS", NotificationManager.IMPORTANCE_LOW))
        RecoveryWorker.schedule(this)
    }
}

class GatewayService : Service() {
    private val executor = Executors.newSingleThreadScheduledExecutor()
    override fun onBind(intent: Intent?): IBinder? = null
    override fun onCreate() {
        super.onCreate()
        val open = PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE)
        val pause = PendingIntent.getService(this, 1, Intent(this, GatewayService::class.java).setAction("PAUSE"), PendingIntent.FLAG_IMMUTABLE)
        startForeground(1, Notification.Builder(this, "gateway").setSmallIcon(android.R.drawable.stat_notify_chat)
            .setContentTitle("UdzioSMS Lab — aktywna sesja").setContentText("Telefon może wysyłać testowe SMS-y z wybranej SIM.")
            .setContentIntent(open).addAction(Notification.Action.Builder(null, "Pauza", pause).build()).setOngoing(true).build())
        val engine = LabEngine(this)
        running = true
        executor.scheduleWithFixedDelay({
            try { engine.tick(true) } catch (error: ApiFailure) { engine.diagnostic("API_${error.status}_${error.code}") }
            catch (_: Exception) { engine.diagnostic("NETWORK_OR_DEVICE_UNAVAILABLE") }
        }, 0, 5, TimeUnit.SECONDS)
    }
    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == "PAUSE") stopSelf()
        return START_NOT_STICKY
    }
    override fun onDestroy() {
        running = false
        executor.shutdownNow()
        RecoveryWorker.once(this)
        super.onDestroy()
    }
    companion object { @Volatile var running = false }
}

class SmsResultReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val key = intent.getStringExtra("task") ?: return
        val kind = intent.getStringExtra("kind") ?: return
        val result = resultCode
        val pending = goAsync()
        Executors.newSingleThreadExecutor().also { executor -> executor.execute {
            try {
                val engine = LabEngine(context)
                if (kind == "SENT") {
                    val code = when (result) {
                        Activity.RESULT_OK -> "RESULT_OK"
                        SmsManager.RESULT_ERROR_RADIO_OFF -> "RADIO_OFF"
                        SmsManager.RESULT_ERROR_NO_SERVICE -> "NO_SERVICE"
                        else -> "ANDROID_ERROR_${result.coerceAtLeast(0)}"
                    }
                    val event = if (result == Activity.RESULT_OK) "SENT" else if (DispatchPolicy.retryable(code)) "FAILED_RETRYABLE" else "UNKNOWN"
                    engine.record(key, event, code)
                } else if (kind == "DELIVERED") {
                    val bytes = intent.getByteArrayExtra("pdu")
                    val format = intent.getStringExtra("format") ?: "3gpp"
                    val status = bytes?.let { SmsMessage.createFromPdu(it, format)?.status }
                    if (result == Activity.RESULT_OK && status != null && status in 0..31) engine.record(key, "DELIVERED", "RESULT_OK")
                    else engine.diagnostic("DELIVERY_REPORT_PENDING_OR_UNREADABLE")
                }
                RecoveryWorker.once(context)
            } finally { pending.finish(); executor.shutdown() }
        } }
    }
}

class RecoveryWorker(context: Context, params: WorkerParameters) : Worker(context, params) {
    override fun doWork(): Result = try {
        LabEngine(applicationContext).tick(false)
        Result.success()
    } catch (_: Exception) { Result.retry() }
    companion object {
        fun schedule(context: Context) {
            val work = PeriodicWorkRequestBuilder<RecoveryWorker>(15, TimeUnit.MINUTES)
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()).build()
            WorkManager.getInstance(context).enqueueUniquePeriodicWork("sms-recovery", ExistingPeriodicWorkPolicy.KEEP, work)
        }
        fun once(context: Context) {
            WorkManager.getInstance(context).enqueueUniqueWork("sms-sync", ExistingWorkPolicy.KEEP,
                OneTimeWorkRequestBuilder<RecoveryWorker>().setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()).build())
        }
    }
}

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED) {
            RecoveryWorker.schedule(context)
            RecoveryWorker.once(context)
            // Android background-start restrictions: resume reporting, not an unrequested sending session.
        }
    }
}
