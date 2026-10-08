package pl.udzio.smslab

/** Pure decisions shared by the engine and unit tests. UNKNOWN never authorizes a resend. */
object DispatchPolicy {
    fun mayInvoke(stage: String, authorization: Boolean, leaseUntil: Long, now: Long): Boolean =
        stage == "PREPARED" && authorization && now < leaseUntil
    fun retryable(result: String): Boolean = result == "RADIO_OFF" || result == "NO_SERVICE"
    fun callbackStage(current: String, event: String): String = when {
        current == "DELIVERED" -> "DELIVERED"
        event == "DELIVERED" -> "DELIVERED"
        event == "SENT" -> "SENT"
        else -> "FINAL"
    }
    fun recoverAsUnknown(stage: String, started: Long, now: Long): Boolean =
        stage in listOf("PREPARED", "DISPATCHED") && now - started >= 120000
}
