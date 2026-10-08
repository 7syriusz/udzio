# Kontrakt Lab v1 i decyzje wykonawcze

Punkt odniesienia: specyfikacja użytkownika UdzioSMS Lab 1.0, 24.09.2026. Implementacja 08.10.2026.
To niezależny prototyp, bez zmian w CORE. Serwer Python/SQLite wybrano dla samodzielnego uruchomienia
bez zależności Laravel i bez dostępu do jego bazy. Wyłącznie jeden telefon i pojedynczy segment GSM-7.

## Uwierzytelnienie i identyfikacja

Operator: `POST /lab/v1/session` z JSON `password`, następnie `Authorization: Bearer <session>`.
Sesja trwa godzinę. Telefon: Bearer token z parowania + `X-Device-ID` + UUID w `X-Request-ID`.
Token losowy 256-bit, serwer przechowuje SHA-256, ważność 24 h, natychmiastowe odwołanie w panelu.
Parowanie wymaga kodu wygenerowanego przez zalogowanego operatora, ważnego 5 minut, jednorazowego.
Po utracie odpowiedzi parowania operator unieważnia utworzone urządzenie i generuje nowy kod.

Wszystkie zmieniające wywołania telefonu są zapamiętywane na 24 h. Identyczne powtórzenie zwraca wynik;
inny payload/ścieżka dla tego samego request_id daje 409. Zdarzenia mają oddzielne trwałe event_id.
Idempotencja utworzenia wiadomości jest niezależna od cache żądań i pozostaje z rekordem wiadomości.

## Trasy

| Metoda / ścieżka `/lab/v1` | Dostęp | Funkcja |
|---|---|---|
| POST `/session`, `/logout` | hasło / sesja | Logowanie / unieważnienie sesji |
| POST `/pairing-code` | operator | Jednorazowy kod |
| POST `/devices/pair` | kod | `code`, `name` → `device_id`, `token`, `token_expires_at` |
| POST `/devices/{id}/heartbeat` | telefon | `subscription_id`, `send_sms_granted`, `sim_ready`, wersje urządzenia |
| POST `/queue/claim` | telefon | Jedno zadanie z dzierżawą albo 204 |
| POST `/messages/{id}/lease` | telefon | `lease_token`; odnowienie tylko bieżącego CLAIMED; SENDING nie wydłuża okna wyniku |
| POST `/messages/{id}/events` | telefon | Zdarzenie z lease_token i bieżącą próbą |
| POST `/messages` | operator | Utworzenie; nagłówek UUID `Idempotency-Key` |
| GET `/messages`, `/snapshot` | operator | Widok kolejki; opcjonalne `?status=SENT` |
| GET `/messages/{id}/events` | operator | Pełna chronologia zadania |
| POST `/messages/{id}/cancel` | operator | QUEUED / oczekujące FAILED_RETRYABLE; pozostałe 409 |
| POST `/messages/{id}/resolve` | operator | UNKNOWN → SENT / DELIVERED / FAILED_FINAL; wymagany `reason` |
| POST `/devices/{id}/pause`, `/resume`, `/revoke` | operator | Sterowanie telefonem |
| POST `/config` | operator | `enabled`, `queue_limit` 1–10, `daily_limit` 1–200, `interval_seconds` 15–3600, `retry_enabled` bool |
| POST `/allowlist` | operator | `number` E.164; opcjonalnie `remove: true` |
| POST `/segments` | operator | `body` → kodowanie, jednostki, segmenty, czy MVP dopuszcza tekst |
| GET `/export` | operator | JSON metryk, ostatnich 500 zadań i ich zdarzeń, maskowane numery, bez treści |

`GET /health` jest publicznym testem procesu bez danych. Panel to `/`. Serwer nie publikuje katalogu plików.
Brak sesji/cookies urządzenia przeglądarki; Bearer w pamięci JS i brak CORS chronią operacje przed CSRF.
Nie używaj endpointów telefonu z poświadczeniami operatora i odwrotnie.

## Payload

Tworzenie:

```json
{
  "recipient": "+48123456789",
  "body": "[TEST UDZIOSMS] Proba 1",
  "not_before": "2026-10-08T10:00:00Z",
  "expires_at": "2026-10-08T11:00:00Z"
}
```

To przykład syntetyczny, nie domyślny odbiorca. Daty muszą mieć strefę; ważność maksymalnie 24 h.
Claim zwraca `message_id`, `recipient`, `body`, `lease_token`, `lease_expires_at`, `attempt_no`, `segments: 1`.

Każde zdarzenie zawiera:

```json
{
  "event_id": "UUID",
  "type": "SENDING",
  "attempt_no": 1,
  "lease_token": "sekret z claim",
  "provider_message_id": "UUID operacji lokalnej",
  "occurred_at_device": "2026-10-08T10:00:15Z",
  "sim_subscription_id": 3,
  "segments": 1
}
```

SENT/DELIVERED wymagają `result_code: RESULT_OK`. Błędy: ograniczony techniczny kod, bez treści/numeru.
SENDING musi dostać `send_authorized: true` przed wywołaniem SmsManager. Powtórzone potwierdzenie jest
sprawdzane ponownie względem bieżącej próby, lease, pauzy i czasu — stare ACK nie uprawnia do wysyłki.
Numery event_id w przykładach zastąp prawdziwym UUID.

## Granica wysyłki i odzyskiwanie

1. Serwer serializuje claim przez SQLite `BEGIN IMMEDIATE`. Jedno urządzenie ma najwyżej jeden CLAIMED/SENDING.
2. Telefon zapisuje request_id claim przed siecią, a odebrane zadanie w Room przed usunięciem request_id.
3. Klucz lokalnego zadania zawiera identyfikator dzierżawy, aby bezpieczne odzyskanie wygasłego CLAIMED
   nie kolidowało z poprzednią lokalną próbą, która nigdy nie doszła do SENDING.
4. Room zapisuje PREPARED, provider_message_id i zdarzenie SENDING. Dopiero zatwierdzony SENDING pozwala
   na trwały zapis DISPATCHED, potem pojedyncze wywołanie SmsManager.
5. Crash pomiędzy DISPATCHED a wywołaniem modemu może oznaczać niewysłaną wiadomość. Traktujemy ją jako
   UNKNOWN, zamiast ryzykować duplikat. To świadomy koszt ostrożności, nie gwarancja dostarczenia exactly-once.
6. Callback jest transakcyjnie dopisywany do outbox Room przed synchronizacją. Powtórzony callback nie
   dopisuje drugiego zdarzenia tego samego typu/operacji. Odzyskiwanie PREPARED/DISPATCHED nigdy nie wywołuje SMS.
7. SENDING bez wyniku przez 120 s → UNKNOWN. Spóźniony dowód SENT/DELIVERED z tej samej próby może uściślić UNKNOWN,
   dopóki operator go ręcznie nie rozstrzygnął. DELIVERED nie cofa się do SENT przy opóźnionym callbacku.
8. SENT bez DELIVERED pozostaje SENT, zgodnie z T02/T22 i §4.3. Niejasną strzałkę diagramu §4.1
   rozstrzygnięto na rzecz jawnego zakazu ponowienia i opisanych testów.

FAILED_RETRYABLE wyłącznie dla pewnych kodów RADIO_OFF i NO_SERVICE, jednego segmentu i bieżącego SENDING.
Retry jest domyślnie wyłączone na pierwszą próbę; panel pozwala je włączyć po odbiorze błędów modemu.
Po włączeniu: dwie kolejne próby po 60 i 300 s (łącznie maksymalnie 3 wywołania), do terminu ważności. Inne niepewne
błędy → UNKNOWN. Nie ma ręcznego „wyślij ponownie UNKNOWN”. Operator może wyłącznie zapisać wynik i powód.

## Granice konfiguracji

- Usługa `specialUse` jest uruchamiana jawnie przez użytkownika i ma powiadomienie/pauzę.
  WorkManager synchronizuje wyniki po restarcie; jego okres 15 minut nie jest pętlą wysyłania co 15 sekund.
- Heartbeat co 30 s w aktywnej sesji. Po 90 s serwer nie przydziela zadań. Dzienny limit dotyczy prób, nie tylko dostarczeń.
- Archiwalny eksport jest ograniczony do 500 najnowszych zadań — wystarcza na test 50; większe eksperymenty
  wymagają eksportowania po każdej serii. Pełna historia pozostaje w lokalnej bazie.
- Obraz serwera nie potrzebuje zewnętrznych pakietów Python. Caddy kończy TLS; backend nie ma portu publicznego.
- Android nie odbiera przychodzących SMS, nie żąda roli domyślnej aplikacji SMS i nie importuje kontaktów.
