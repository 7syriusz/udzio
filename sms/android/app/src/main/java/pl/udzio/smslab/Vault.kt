package pl.udzio.smslab

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import org.json.JSONObject
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/** Credentials encrypted with a non-exportable Android Keystore key. No backups. */
class Vault(context: Context) {
    private val prefs = context.getSharedPreferences("lab", Context.MODE_PRIVATE)
    private fun key(): SecretKey {
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (store.getKey("smslab", null) as? SecretKey)?.let { return it }
        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore").apply {
            init(KeyGenParameterSpec.Builder("smslab", KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).build())
        }.generateKey()
    }
    fun save(config: JSONObject) {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding").apply { init(Cipher.ENCRYPT_MODE, key()) }
        val bytes = cipher.doFinal(config.toString().toByteArray())
        check(prefs.edit().putString("credentials", Base64.encodeToString(cipher.iv + bytes, Base64.NO_WRAP)).commit())
    }
    fun load(): JSONObject? {
        val encoded = prefs.getString("credentials", null) ?: return null
        val bytes = Base64.decode(encoded, Base64.NO_WRAP)
        val cipher = Cipher.getInstance("AES/GCM/NoPadding").apply { init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, bytes.copyOfRange(0, 12))) }
        return JSONObject(String(cipher.doFinal(bytes.copyOfRange(12, bytes.size))))
    }
    fun clear() { check(prefs.edit().clear().commit()) }
    var lastDispatch: Long
        get() = prefs.getLong("lastDispatch", 0)
        set(value) { check(prefs.edit().putLong("lastDispatch", value).commit()) }
    var minInterval: Int
        get() = prefs.getInt("minInterval", 15)
        set(value) { check(prefs.edit().putInt("minInterval", value.coerceIn(15, 3600)).commit()) }
    var lastHeartbeat: Long
        get() = prefs.getLong("heartbeat", 0)
        set(value) { check(prefs.edit().putLong("heartbeat", value).commit()) }
    var queueEnabled: Boolean
        get() = prefs.getBoolean("queueEnabled", false)
        set(value) { check(prefs.edit().putBoolean("queueEnabled", value).commit()) }
    var subscription: Int
        get() = prefs.getInt("subscription", -1)
        set(value) { check(prefs.edit().putInt("subscription", value).commit()) }
    var claimRequest: String?
        get() = prefs.getString("claimRequest", null)
        set(value) { check(prefs.edit().putString("claimRequest", value).commit()) }
}
