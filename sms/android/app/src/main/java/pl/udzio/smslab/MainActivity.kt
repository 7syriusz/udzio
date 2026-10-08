package pl.udzio.smslab

import android.Manifest
import android.app.Activity
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import android.net.ConnectivityManager
import java.time.Instant
import android.telephony.SubscriptionManager
import android.view.View
import android.widget.*
import org.json.JSONObject
import java.util.UUID
import java.util.concurrent.Executors

class MainActivity : Activity() {
    private lateinit var content: LinearLayout
    private lateinit var status: TextView
    private val io = Executors.newSingleThreadExecutor()
    private val vault by lazy { Vault(this) }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        content = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(28, 32, 28, 32) }
        setContentView(ScrollView(this).apply { addView(content) })
        label("UdzioSMS Lab", 26f)
        label("Prywatny test jednej bramki. Start zezwala na wysyłkę SMS do numerów testowych. Mogą obowiązywać opłaty operatora.")
        status = label("Gotowy do konfiguracji")
        val base = field("Adres HTTPS serwera")
        val code = field("Jednorazowy kod parowania")
        val name = field("Nazwa telefonu").apply { setText("Samsung Lab") }
        action("Sparuj") {
            val url = Api.base(base.text.toString())
            val pairCode = code.text.toString().trim()
            val deviceName = name.text.toString()
            background {
                synchronized(LabEngine.lock) {
                    check(vault.load() == null) { "Najpierw wyloguj poprzednie urządzenie." }
                    val result = Api.request(url, "/devices/pair", JSONObject().put("code", pairCode).put("name", deviceName), UUID.randomUUID().toString())
                    vault.save(result.put("base", url))
                }
                runOnUiThread { code.setText("") }
                "Sparowano. Wybierz SIM, potem uruchom telefon i kolejkę w panelu."
            }
        }
        action("Nadaj uprawnienia SMS, SIM i powiadomień") {
            requestPermissions(arrayOf(Manifest.permission.SEND_SMS, Manifest.permission.READ_PHONE_STATE, Manifest.permission.POST_NOTIFICATIONS), 1)
        }
        val sims = Spinner(this)
        content.addView(sims)
        var subscriptions = emptyList<Int>()
        action("Pokaż aktywne karty SIM") {
            if (checkSelfPermission(Manifest.permission.READ_PHONE_STATE) != PackageManager.PERMISSION_GRANTED) {
                status.text = "Najpierw nadaj uprawnienie do odczytu stanu telefonu (lista SIM)."
            } else {
                val list = getSystemService(SubscriptionManager::class.java).activeSubscriptionInfoList.orEmpty()
                subscriptions = list.map { it.subscriptionId }
                sims.adapter = ArrayAdapter(this, android.R.layout.simple_spinner_dropdown_item, list.map { "Slot ${it.simSlotIndex+1}: ${it.displayName} (ID ${it.subscriptionId})" })
                val selected = subscriptions.indexOf(vault.subscription)
                if (selected >= 0) sims.setSelection(selected)
            }
        }
        action("Zapisz wybraną SIM") {
            if (GatewayService.running) { status.text = "Zatrzymaj sesję przed zmianą SIM."; return@action }
            subscriptions.getOrNull(sims.selectedItemPosition)?.let { vault.subscription = it; status.text = "Zapisano SIM $it" }
        }
        action("START sesji testowej") {
            if (checkSelfPermission(Manifest.permission.SEND_SMS) != PackageManager.PERMISSION_GRANTED || vault.subscription < 0) {
                status.text = "Nadaj uprawnienia i wybierz SIM."
            } else {
                startForegroundService(Intent(this, GatewayService::class.java))
                status.text = "Sesja uruchomiona. Panel musi także zezwolić na kolejkę i telefon."
            }
        }
        action("PAUZA telefonu") { stopService(Intent(this, GatewayService::class.java)); status.text = "Zatrzymano pobieranie. Synchronizacja callbacków pozostaje aktywna." }
        action("Synchronizuj teraz") { background { LabEngine(this).tick(false); "Synchronizacja zakończona" } }
        action("Stan i ostatnie zdarzenia") { background {
            val dao = Journal.get(this).dao()
            val recent = dao.recent().joinToString("\n") { "${it.messageId.take(8)} próba ${it.attempt} ${it.stage} SIM ${it.subscription} ${if (it.recipient.isEmpty()) "—" else "***" + it.recipient.takeLast(3)}" }
            "Ostatni heartbeat: ${if (vault.lastHeartbeat == 0L) "brak" else Instant.ofEpochMilli(vault.lastHeartbeat)}; kolejka wg ostatniego heartbeat: ${vault.queueEnabled}; internet: ${getSystemService(ConnectivityManager::class.java).activeNetwork != null}\nSesja: ${GatewayService.running}; SIM: ${vault.subscription}; gotowość: ${LabEngine(this).ready()}; zdarzenia do synchronizacji: ${dao.pendingCount()}\n$recent\n" + dao.diagnostics().joinToString("\n") { it.code }
        } }
        action("Bezpiecznie wyloguj") { background {
            check(!GatewayService.running) { "Najpierw zatrzymaj sesję." }
            synchronized(LabEngine.lock) {
                val dao = Journal.get(this).dao()
                check(dao.active() == null && dao.pendingCount() == 0 && dao.recent().none { it.stage == "SENT" }) { "Najpierw zakończ zadania, odbierz raporty dostarczenia i zsynchronizuj wyniki. Brak raportu: uzgodnij archiwizację testu z operatorem." }
                vault.clear()
            }
            "Poświadczenia usunięte. Unieważnij token w panelu przed kolejnym parowaniem."
        } }
        label("Po restarcie urządzenia synchronizacja jest wznawiana przez WorkManager. Aktywną sesję wysyłania uruchom przyciskiem START. UNKNOWN nigdy nie jest ponawiane automatycznie.")
    }
    private fun label(text: String, size: Float = 15f): TextView = TextView(this).apply { this.text = text; textSize = size; setPadding(0, 12, 0, 12); content.addView(this) }
    private fun field(hint: String): EditText = EditText(this).apply { this.hint = hint; isSingleLine = true; content.addView(this) }
    private fun action(text: String, block: () -> Unit) { content.addView(Button(this).apply { this.text = text; setOnClickListener { try { block() } catch (_: Exception) { status.text = "Sprawdź konfigurację i uprawnienia." } } }) }
    private fun background(block: () -> String) { io.execute { val result = try { block() } catch (e: ApiFailure) { "API ${e.status}: ${e.code}" } catch (e: IllegalStateException) { e.message ?: "Błąd stanu" } catch (_: Exception) { "Brak połączenia lub niepoprawna konfiguracja. Sprawdź HTTPS i uprawnienia." }; runOnUiThread { status.text = result } } }
    override fun onDestroy() { io.shutdown(); super.onDestroy() }
}
