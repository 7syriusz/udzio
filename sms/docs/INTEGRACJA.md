# Materiał do późniejszej integracji — bez zmian CORE

## Fakty ustalone przez implementację i testy automatyczne

- Potrzebne są oddzielne identyfikatory: zadanie, próba, operacja telefonu, request_id, event_id i dzierżawa.
- SENT i DELIVERED nie mogą być jednym stanem. Brak DELIVERED nie jest dowodem niewysłania.
- Niejednoznaczny wynik jest jawnym wynikiem UNKNOWN. Ponowienie nie może wynikać wyłącznie z timeoutu.
- Przed retry potrzebny jest dowód braku wysyłki. Powtórna synchronizacja jest czymś innym niż kolejna próba SMS.
- Provider musi przechować lokalny wynik, gdy API jest niedostępne. Wygaśnięcie lease nie może uruchamiać
  ponownej wysyłki wiadomości, której wywołanie modemowe już mogło się odbyć.
- Wspólna historia musi uwzględnić czas urządzenia i przyjęcia zdarzenia oraz ręczne rozstrzygnięcie.

To wnioski z protokołu i symulacji; nie dowód działania modemu ani deklaracja gotowości produkcyjnej.

## Kandydat kontraktu providera, do zatwierdzenia po testach fizycznych

`SendSmsCommand`: correlation/idempotency key, numer, treść, not_before, expires_at.
`ProviderEvent`: id zdarzenia, id zadania/operacji, numer próby, SENT/DELIVERED/FAILED/UNKNOWN,
czas zdarzenia, kod błędu, informacja czy błąd pewnie wyklucza wysyłkę.
`ProviderHealth`: gotowość, heartbeat, pauza, ograniczenia tempa i kodowania.

CORE nadal rozstrzyga odbiorcę, podstawę komunikacji, kontekst biznesowy, terminy i zgodę.
CONNECT przechowuje konfigurację, wybiera provider i mapuje wynik techniczny. Nie przenosić do Core:
SIM subscription ID, dzierżaw Androida, PendingIntent, statusu baterii i serwisowych kodów modemu.

## Decyzje nadal wymagające pomiarów

- Czy SEND_SMS i callbacki działają na danym A53/One UI bez roli domyślnej aplikacji?
- Czy raporty dostarczenia operatora przychodzą i czy dekodowany PDU ma spodziewany format/status?
- Czy sesja specialUse działa przy wygaszonym ekranie 30 minut oraz po utracie sieci?
- Jak zachowują się NO_SERVICE/RADIO_OFF na konkretnym modemie? Potwierdzić brak SMS przed automatycznym retry.
- Jakie są dopuszczalne zasady używania SIM jako bramki i ograniczenia operatora?
- Czy rozszerzyć MVP o multipart? Wymaga agregacji segmentów i odrębnej obsługi częściowego powodzenia,
  której nie wolno zastąpić automatycznym ponowieniem całej wiadomości.

Rozstrzygnięcia i obserwacje dopisać do TESTY-URZADZENIE.csv po T01–T22, bez numerów i treści wiadomości.
Nie integrować Lab z produkcyjną bazą Udzio przed tym odbiorem.
