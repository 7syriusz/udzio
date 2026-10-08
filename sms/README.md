# UdzioSMS Lab

Niezależny prototyp z dokumentu „UdzioSMS Lab”, wersja 1.0 z 24.09.2026.
Kod i dane mieszczą się w `sms/`. Nie importuje aplikacji Laravel, jej użytkowników, bazy, sekretów ani kontaktów.
Serwer: Python 3.12+ i SQLite (biblioteka standardowa, bez pip). Telefon: Kotlin, Room, WorkManager,
Android 13+ (min SDK 33, compile/target 36). Panel: HTML/CSS/JavaScript bez procesu budowania.

**To laboratorium, nie kanał produkcyjny.** Testy automatyczne nie potwierdzają zachowania Samsunga,
modemu ani operatora. Odbiór wymaga rzeczywistych T01–T22 z [protokołu](docs/TESTY-URZADZENIE.csv).

## Uruchomienie lokalne serwera

Z katalogu projektu Udzio:

```bash
cd sms
python3 server/app.py --init
python3 server/app.py
```

Pierwsze polecenie pyta o własne hasło operatora (minimum 12 znaków) i zapisuje **wyłącznie scrypt hash**
w `.env` z uprawnieniami 0600. Nie nadpisuje istniejącej konfiguracji. Serwer wypisuje adres nasłuchu;
domyślny port to 8787, interfejs 127.0.0.1. Wejdź na ten adres w przeglądarce komputera i zaloguj się.
SQLite powstaje w `sms/runtime`, nie w bazie Udzio. Start jest pusty: kolejka zatrzymana, limit 1, brak numerów.

Android **nie łączy się przez HTTP**. Do testu telefonu potrzebny jest osobny adres HTTPS z certyfikatem
zaufanym przez system. Nie ustawiaj flag Chrome i nie wyłączaj sprawdzania certyfikatów.

## Serwer testowy z HTTPS i Dockerem

1. Skopiuj sam katalog `sms` na serwer. Zainstaluj Docker Engine z Compose.
2. W `sms` uruchom `python3 server/app.py --init` albo wygeneruj `.env` lokalnie i przenieś bezpiecznym kanałem.
3. Dopisz do `.env` `LAB_DOMAIN=` z własną domeną wskazującą na serwer. Otwórz 80/443.
4. Uruchom:

```bash
docker compose up -d --build
docker compose logs --tail=30
```

Caddy wystawia HTTPS. Serwer kolejki nie publikuje swojego portu na hoście. Baza leży w osobnym woluminie
`udziosms-lab_lab_data`; **nie używaj `down -v`, jeśli chcesz zachować wyniki**. `LAB_HTTP_PORT` i
`LAB_HTTPS_PORT` służą zmianie mapowania; wydanie publicznego certyfikatu nadal wymaga poprawnej walidacji
ACME (standardowo port 80/443). Przy istniejącym proxy użyj własnego proxy dla oddzielnego serwera Lab.
Prywatny HTTP adapter jest przeznaczony do tego małego laboratorium za proxy, nie do otwartego wielodostępnego SaaS.

## APK i pierwsza próba

Gotowy, podpisany prywatnym kluczem laboratoryjnym APK (jeśli zbudowano go w tym środowisku):
`artifacts/UdzioSMS-Lab.apk`. Suma kontrolna: `artifacts/SHA256SUMS`.

1. Porównaj SHA-256 pliku, zainstaluj APK ręcznie na telefonie Android 13+.
2. W panelu dodaj **jeden uzgodniony numer testowy** na allowlistę i wygeneruj kod parowania.
3. W aplikacji wpisz adres HTTPS (bez ścieżki), kod i nazwę telefonu. Kod ważny 5 minut, jednorazowy.
4. Nadaj SEND_SMS, uprawnienie odczytu stanu telefonu (wyłącznie lista aktywnych SIM) oraz powiadomienia.
   Aplikacja nie żąda READ_SMS, RECEIVE_SMS ani kontaktów.
5. Pokaż aktywne SIM i zapisz właściwą kartę. Nie polega na domyślnej SIM systemu.
6. Włącz START sesji w telefonie, „Start telefonu” oraz kolejkę w panelu.
7. Przy limicie 1 dodaj tekst z prefiksem `[TEST UDZIOSMS] `. Obserwuj oba telefony.
8. Zapisz faktyczny wynik w protokole, sprawdź historię i wyeksportuj JSON. Dopiero potem zwiększ limit do 10.

Sesja na telefonie ma stałe powiadomienie z pauzą. Samsung: jawnie wyłącz usypianie aplikacji testowej
w ustawieniach baterii; zapisz wersję Android/One UI w protokole. Po restarcie telefonu WorkManager
odtwarza synchronizację, ale wysyłanie wymaga ponownego START. Nie uruchamiamy usługi pierwszoplanowej z boot receivera.

## Budowanie i testowanie

Serwer (bez dodatkowych zależności):

```bash
./scripts/check.sh
```

Android: JDK 17, Android SDK platform 36 i Build Tools 36.0.0, dostęp do Google Maven/Maven Central.
AGP 8.13.2, Gradle 8.13, Kotlin 2.2.21, Room 2.8.4, WorkManager 2.11.2 są przypięte w projekcie.
Wrapper Gradle jest dołączony. Ustaw `JAVA_HOME` i `ANDROID_HOME`:

```bash
./scripts/build-android.sh
./scripts/sign-release.sh
```

Pierwszy skrypt wykonuje testy JVM, Android Lint i buduje debug APK. Drugi tworzy lokalny klucz do testów
(jeśli jeszcze nie istnieje), uruchamia testy/Lint i buduje podpisany release APK. **Nie jest podpisany
kluczem produkcyjnym Udzio.** Zachowaj bezpieczną kopię `runtime/smslab.jks` i `runtime/signing.env`:
aktualizacja bez odinstalowania wymaga tego samego podpisu. Hasła nie trafiają do argumentów keytool ani do Git.
Nie usuwaj danych aplikacji w trakcie testu — usunięcie dziennika uniemożliwia odzyskanie callbacków.

## Sterowanie i bezpieczeństwo

- Panel ma godzinną sesję w pamięci przeglądarki, logowanie ograniczone liczbą prób, brak publicznej kolejki.
- Telefon ma token ważny 24 godziny. W panelu można go natychmiast unieważnić; nowe parowanie wymaga
  wcześniejszego zablokowania poprzedniego urządzenia. Prototyp obsługuje jeden niezablokowany telefon.
- Pauza globalna blokuje claim **i nowe zatwierdzenie SENDING**. Nie cofnie SMS już przekazanego do modemu.
- Najwyżej 10 oczekujących zadań, domyślnie 1; co najmniej 15 sekund między rozpoczęciami prób;
  retry domyślnie wyłączone na pierwszą próbę (włącz dopiero po potwierdzeniu zachowania błędów modemu);
  domyślnie 50 prób na dobę UTC (próby retry także zużywają limit), możliwość jawnego zwiększenia do 200.
- Wysyłka tylko jednego segmentu GSM-7. Licznik rozpoznaje znaki rozszerzone, polskie litery i Unicode.
  Wielosegmentowość odłożona zgodnie z dopuszczonym wariantem §7.3 specyfikacji.
- SENT bez DELIVERED **pozostaje SENT** (T02/T22); nigdy nie ponawiamy tylko z powodu braku raportu.
- „Bezpiecznie wyloguj” odmawia przy aktywnym zadaniu, niesynchronizowanych wynikach lub oczekiwaniu na
  raport SENT. Gdy raport nigdy nie nadejdzie: zakończ test, wyeksportuj wyniki, zapisz odstępstwo, unieważnij
  token w panelu; dopiero wtedy operator może świadomie wyczyścić dane aplikacji przed nowym parowaniem.
- Jeżeli token wygaśnie w trakcie awarii, nie usuwaj dziennika: zapisz lokalny stan i zestaw go z UNKNOWN
  w panelu. Nie ma automatycznej migracji niesynchronizowanych zdarzeń na nową tożsamość telefonu.
- Dane numeru/treści usuwane po maksymalnie 30 dniach, cache odpowiedzi po 24 h; allowlista wymaga
  ponownego potwierdzenia po 30 dniach. Metadane zdarzeń pozostają. Kopie zapasowe i wyeksportowane pliki
  mają własną retencję operatora. SQLite/WAL mogą zawierać pozostałości fizyczne — po zakończeniu
  eksperymentu usuń cały testowy wolumin po zachowaniu zanonimizowanych wyników.

## Dokumentacja

- [Kontrakt i decyzje](docs/API-I-DECYZJE.md)
- [Weryfikacja i granice odbioru](docs/WERYFIKACJA.md)
- [Protokół T01–T22](docs/TESTY-URZADZENIE.csv)
- [Wnioski do przyszłej integracji](docs/INTEGRACJA.md)

Źródła sprawdzone podczas implementacji:
[SmsManager](https://developer.android.com/reference/android/telephony/SmsManager),
[typy usług pierwszoplanowych](https://developer.android.com/develop/background-work/services/fgs/service-types),
[Room](https://developer.android.com/jetpack/androidx/releases/room),
[WorkManager](https://developer.android.com/jetpack/androidx/releases/work).
