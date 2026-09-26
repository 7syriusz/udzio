# Architektura i konwencje

Specyfikacja nadrzędna: [E1E2E3A5Skalik](specifications/E1E2E3A5Skalik.md). Założenia: [ZALOZENIA.md](ZALOZENIA.md).

## 1. Jedna aplikacja, moduły domenowe

Udzio jest jedną aplikacją Laravel podzieloną na moduły odpowiadające pojęciom A5. Kod modułu leży w `app/Domain/<Moduł>/`:

| Moduł | Pojęcia A5 | Etap |
|---|---|---|
| `Platform` | ACTOR/SUBJECT, audyt, relacje w czasie, wersjonowanie, klasyfikacja danych, idempotencja | E1 |
| `Identity` | PERSON, ACCOUNT, CONTACT, reprezentacja | E2 |
| `Organization` | ORGANIZATION, struktura, członkostwo, ROLE, PERMISSION, SCOPE | E3 |
| `Event` | EVENT, program, GROUP | E4 |
| `Participation` | REGISTRATION, PARTICIPATION, ENTITLEMENT, USAGE, IDENTIFIER, ATTENDANCE | E5–E6 |
| `Resource` | RESOURCE, LAYOUT, RESERVATION, ASSIGNMENT | E7 |
| `Finance` | OFFERING, ORDER, OBLIGATION, PAYMENT, ALLOCATION, ADJUSTMENT, REFUND, LEDGER | E8 |
| `Form` | FORM, FORM RESPONSE, DOCUMENT, CONSENT | E9 |
| `Communication`, `Reporting` | COMMUNICATION, REPORT DEFINITION | E10 |

Katalog modułu powstaje wraz z pierwszym kodem modułu. Nazwa branżowa nie tworzy modułu (A5-17).

**Układ modułu:**
- `Models/` — modele Eloquent;
- `Actions/` — operacje biznesowe (jedna klasa = jedna operacja, np. `RegisterPerson`);
- `Policies/`, `Enums/`, `Events/`.

**Przepływ:**
- Kontrolery w `app/Http` są cienkie: walidują żądanie i wywołują akcje modułu. Logika nie siedzi w ekranach ani kontrolerach (A5 §13).
- Moduł korzysta z innego modułu przez jego akcje i modele. Nie zapisuje bezpośrednio do cudzych tabel.

## 2. Konwencje danych

| Obszar | Konwencja |
|---|---|
| Klucze | Wewnętrznie `bigint` auto-increment. Obiekty widoczne na zewnątrz (URL, QR, API) mają dodatkowo publiczny identyfikator ULID — nigdy sekwencyjne `id` w adresie. |
| Kwoty | Liczby całkowite w najmniejszej jednostce (`*_minor`, np. grosze) + kod waluty ISO 4217. Nigdy `float`/`double`. |
| Czas | UTC w bazie i w aplikacji (Z-004). Prezentacja w strefie użytkownika. |
| Statusy i typy | PHP `enum` z wartością tekstową, w bazie `string` (bez `ENUM` MySQL). Nowa wartość nie wymaga migracji schematu. |
| Usuwanie | Fakty biznesowe nie są kasowane ani nadpisywane bez śladu (A5 §11). Zmiana stanu to nowy status albo nowy wpis historii. `SoftDeletes` tylko tam, gdzie wynika to z modelu. |
| Klucze obce | Domyślnie `restrictOnDelete` dla danych z historią; `cascade` tylko dla danych technicznych. |
| Nazewnictwo | Kod, tabele i kolumny po angielsku, nazwy pojęć zgodne z A5 (`person`, `registration`, `entitlement`). Interfejs i komunikaty po polsku. |
| Konfiguracja ≠ wykonanie | Definicje wersjonowane; każdy wynik wskazuje wersję definicji (A5-11, A5-16). Wzorzec powstaje w E1. |

## 3. Testy

- Testy działają wyłącznie na MySQL 8.4 i bazie `*_test`. Pilnują tego dwa strażniki (Z-002).
- Operacje ilościowe, rezerwacje, użycia i płatności mają testy współbieżności: `tests/Support/Concurrency/Race.php`, grupa `concurrency`.
- Każda operacja wymagająca uprawnień ma test pozytywny i negatywny (obca organizacja, brak uprawnienia) — A5 §12.
- Test architektury (`tests/Unit/ArchitectureTest.php`) sprawdza konwencje, które da się sprawdzić automatycznie.

## 4. Automatycznie sprawdzane reguły

| Reguła | Dlaczego |
|---|---|
| Brak `env()` poza `config/` | Zmienne środowiskowe czytane przez konfigurację (działa cache konfiguracji na produkcji). |
| Brak `dd`, `dump`, `var_dump`, `ray` w `app/` | Kod diagnostyczny nie trafia na produkcję. |
| Migracje bez `float()` / `double()` | Kwoty jako liczby całkowite (sekcja 2). |
| Brak `enum()` w migracjach | Statusy jako tekst + PHP `enum`. |
| Kod domenowy tylko w `app/Domain/<Moduł>/` z nazwą z listy modułów | Pilnuje granic A5 i zakazu modułów branżowych. |
