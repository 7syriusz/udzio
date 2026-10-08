package pl.udzio.smslab

import org.json.JSONObject
import java.net.URI
import javax.net.ssl.HttpsURLConnection

class ApiFailure(val status: Int, val code: String) : Exception(code)
class Api(private val credentials: JSONObject) {
    fun post(path: String, data: JSONObject, requestId: String): JSONObject {
        return request(credentials.getString("base"), path, data, requestId, credentials.optString("token"), credentials.optString("device_id"))
    }
    companion object {
        fun base(raw: String): String {
            val uri = URI(raw.trim())
            require(uri.scheme == "https" && !uri.host.isNullOrBlank() && uri.userInfo == null && uri.query == null && uri.fragment == null && uri.path in listOf("", "/")) { "Wymagany adres HTTPS bez ścieżki." }
            return uri.toString().trimEnd('/')
        }
        fun request(base: String, path: String, data: JSONObject, requestId: String, token: String = "", device: String = ""): JSONObject {
            val connection = URI(base(base) + "/lab/v1" + path).toURL().openConnection() as HttpsURLConnection
            try {
                connection.instanceFollowRedirects = false
                connection.requestMethod = "POST"
                connection.connectTimeout = 10000
                connection.readTimeout = 10000
                connection.doOutput = true
                connection.setRequestProperty("Content-Type", "application/json")
                connection.setRequestProperty("X-Request-ID", requestId)
                if (token.isNotEmpty()) connection.setRequestProperty("Authorization", "Bearer $token")
                if (device.isNotEmpty()) connection.setRequestProperty("X-Device-ID", device)
                connection.outputStream.use { it.write(data.toString().toByteArray()) }
                val status = connection.responseCode
                if (status == 204) return JSONObject()
                val stream = if (status in 200..299) connection.inputStream else connection.errorStream
                val text = stream?.bufferedReader()?.use { it.readText() } ?: "{}"
                val result = JSONObject(text)
                if (status !in 200..299) throw ApiFailure(status, result.optString("error", "HTTP_ERROR"))
                return result
            } finally { connection.disconnect() }
        }
    }
}
