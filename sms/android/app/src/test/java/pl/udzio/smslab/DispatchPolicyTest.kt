package pl.udzio.smslab

import org.junit.Assert.*
import org.junit.Test

class DispatchPolicyTest {
    @Test fun onlyFreshAuthorizedPreparedTaskMayReachModem() {
        assertTrue(DispatchPolicy.mayInvoke("PREPARED", true, 200, 100))
        for (stage in listOf("CLAIMED", "DISPATCHED", "SENT", "DELIVERED", "FINAL", "UNKNOWN")) assertFalse(DispatchPolicy.mayInvoke(stage, true, 200, 100))
        assertFalse(DispatchPolicy.mayInvoke("PREPARED", false, 200, 100))
        assertFalse(DispatchPolicy.mayInvoke("PREPARED", true, 100, 100))
    }
    @Test fun recoveryNeverResendsUnknownWork() {
        assertTrue(DispatchPolicy.recoverAsUnknown("PREPARED", 10, 120010))
        assertTrue(DispatchPolicy.recoverAsUnknown("DISPATCHED", 10, 120010))
        assertFalse(DispatchPolicy.recoverAsUnknown("DISPATCHED", 10, 120009))
        assertFalse(DispatchPolicy.recoverAsUnknown("SENT", 10, 999999))
    }
    @Test fun deliveredIsNeverRegressedByLateSentCallback() {
        assertEquals("DELIVERED", DispatchPolicy.callbackStage("DELIVERED", "SENT"))
        assertEquals("SENT", DispatchPolicy.callbackStage("DISPATCHED", "SENT"))
        assertEquals("FINAL", DispatchPolicy.callbackStage("DISPATCHED", "UNKNOWN"))
    }
    @Test fun retryRequiresKnownNoSendResult() {
        assertTrue(DispatchPolicy.retryable("RADIO_OFF"))
        assertTrue(DispatchPolicy.retryable("NO_SERVICE"))
        assertFalse(DispatchPolicy.retryable("GENERIC_FAILURE"))
    }
}
