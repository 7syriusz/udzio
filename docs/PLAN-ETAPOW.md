# Plan etapów projektu Udzio

Specyfikacja nadrzędna: [E1E2E3A5Skalik](specifications/E1E2E3A5Skalik.md) (UDZIO Core A5).
Założenia przyjęte w trakcie prac: [ZALOZENIA.md](ZALOZENIA.md).

## Zasady pracy

**Decyzje:**
1. Jeśli A5 określa zachowanie — stosujemy je.
2. Jeśli A5 milczy, a decyzja jest techniczna — wybieramy rozwiązanie najprostsze, typowe i odwracalne.
3. Jeśli brakuje decyzji funkcjonalnej widocznej dla użytkownika — nie zatrzymujemy pracy. Przyjmujemy rozsądne założenie, wpisujemy je do [ZALOZENIA.md](ZALOZENIA.md) i budujemy tak, aby można je było później zmienić (konfiguracja zamiast zaszycia w kodzie).
4. Pytanie do Jakuba zadajemy tylko wtedy, gdy różne odpowiedzi prowadzą do zasadniczo innego produktu, a zmiany nie da się łatwo wycofać.

**Git:**
- Każdy podetap trwa maksymalnie około 120 minut i ma własną gałąź `eN/MM-nazwa`.
- Po zakończeniu podetapu: pełny zestaw testów → push gałęzi → merge do `main` (`--no-ff`) → push `main`.
- Przy czerwonych testach nie scalamy — najpierw raport.
- Koniec etapu (E0, E1, …) to dodatkowo tag `eN-zamkniety` i akceptacja przed rozpoczęciem kolejnego etapu.

## Przegląd etapów

| Etap | Zakres | Czas | Złożoność testów |
|---|---|---|---|
| E0 | Fundament techniczny | ok. 1 tydz. | Niska |
| E1 | Zasady platformy: ACTOR/SUBJECT, audyt, relacje w czasie, wersjonowanie | 1–2 tyg. | Średnia |
| E2 | Tożsamość: PERSON, CONTACT, ACCOUNT, MFA, działanie w imieniu | ok. 2 tyg. | Średnia |
| E3 | Organizacja i dostęp: ORGANIZATION, członkostwo, ROLE/PERMISSION/SCOPE, izolacja | 2–3 tyg. | Wysoka |
| E4 | EVENT (hierarchia), program, GROUP | 1,5–2 tyg. | Średnia |
| E5 | REGISTRATION, PARTICIPATION, limity, kolejka | 2–3 tyg. | Wysoka |
| E6 | ENTITLEMENT, USAGE, IDENTIFIER/QR, ATTENDANCE | 3–4 tyg. | Bardzo wysoka |
| E7 | RESOURCE, LAYOUT, RESERVATION, ASSIGNMENT | 2–3 tyg. | Wysoka |
| E8 | Oferta, zamówienie, należności, płatności, korekty, zwroty, księga | 3–4 tyg. | Bardzo wysoka |
| E9 | FORM z wersjami, DOCUMENT, CONSENT | 2–3 tyg. | Średnia |
| E10 | Komunikacja, raporty, eksport | ok. 2 tyg. | Średnia |
| E11 | CLONE, MERGE, eksport/import konfiguracji | 2–3 tyg. | Wysoka |
| E12 | Utwardzenie i wdrożenie produkcyjne | 2–3 tyg. | Wysoka |

Razem ok. 26–35 tygodni (6–8 miesięcy) przy jednym programiście pracującym z AI.

---

## E0 — Fundament techniczny

Lokalnie PHP 8.3 działa bezpośrednio w WSL, a MySQL 8.4 (ta sama wersja co na produkcji) w `docker compose` (Docker zainstalowany 2026-09-26, Z-003). CI na GitHub Actions uruchamia testy na MySQL 8.4 i buduje obraz produkcyjny.

| Podetap | Zakres | Wynik / kryterium zakończenia | Testy | Czas |
|---|---|---|---|---|
| E0.0 | Plan, specyfikacja A5 w repozytorium, rejestr założeń | `docs/` w `main` | — (tylko dokumenty) | 60 min |
| E0.1 | Szkielet Laravel 13 (PHP 8.3), Pint, strona startowa, polski język i strefa czasowa | Aplikacja startuje; `php artisan test` zielony | Testy startowe frameworka | 90 min |
| E0.1a | Poprawka: przywrócenie `.gitignore` katalogów Laravela, usunięcie plików generowanych z repozytorium | Czysty klon bez plików generowanych, z kompletem katalogów | Testy startowe | 30 min |
| E0.1b | Laravel Boost (zainstalowany przez Krzysztofa dla Codex) — zatwierdzenie w repozytorium, aktualizacja założeń Z-003 i Z-005 | Boost w `main` | Testy startowe | 30 min |
| E0.2 | Lokalna baza MySQL 8.4 w `docker compose` (baza aplikacji i osobna testowa), `.env.example`, testy domyślnie na MySQL, strażnik bazy testowej | Testy działają na MySQL; uruchomienie poza bazą testową jest zablokowane | Test strażnika, pełny zestaw na MySQL | 120 min |
| E0.3 | CI GitHub Actions: Pint + testy na MySQL 8.4 przy każdym pushu i PR | Zielony przebieg CI na `main` | Cały zestaw w CI | 90 min |
| E0.4 | Mechanizm testów współbieżności (dwa niezależne procesy PHP) + test przykładowy | Test udowadnia, że dwa procesy czekały na tę samą blokadę | Test współbieżności na MySQL | 120 min |
| E0.5 | Architektura modułowa: katalogi domen, konwencje (ID, kwoty w groszach, czas UTC), `docs/ARCHITEKTURA.md`, test architektury | Konwencje zapisane i sprawdzane testem | Test architektury | 90 min |
| E0.6 | Obraz produkcyjny Docker (PHP-FPM, nginx), `compose.production.yaml`, budowa obrazu w CI | Obraz buduje się w CI | Budowa obrazu i test zdrowia `/up` w CI | 120 min |
| E0.7 | Zamknięcie E0: instrukcja uruchomienia, przegląd, tag `e0-zamkniety` | Nowa osoba uruchamia projekt według README | Pełny zestaw lokalnie i w CI | 60 min |

## E1 — Zasady platformy (A5 §1.4, §2.3, §10, §11, §12; A5-03, A5-04, A5-05, A5-11, A5-14, A5-16)

| Podetap | Zakres | Wynik / kryterium zakończenia | Testy | Czas |
|---|---|---|---|---|
| E1.1 | Kontekst ACTOR: użytkownik, proces (komenda, kolejka), integracja, żądanie anonimowe; ustawiany automatycznie i jawnie | Każde wywołanie zna swojego wykonawcę | Pierwszeństwo kontekstu, HTTP, konsola, kolejka | 120 min |
| E1.2 | Audyt tylko do dopisywania: ACTOR, SUBJECT, czynność, kontekst (organizacja), wynik, powód, korelacja, czas | Wpisu nie da się zmienić ani usunąć | Zapis, blokada UPDATE/DELETE, wycofanie razem z transakcją | 120 min |
| E1.3 | Automatyczny audyt zmian modeli: poprzednia i nowa wartość, redakcja pól wrażliwych | Zmiana istotnego stanu zostawia pełny ślad (A5-04) | Tworzenie, zmiana, redakcja, brak śladu przy braku zmian | 120 min |
| E1.4 | Audyt odmów dostępu i odczytów danych chronionych | Odmowa jest zapisana bez ujawniania danych (A5-14) | Odmowa 403/404, limit zapisu, odczyt danych RESTRICTED | 90 min |
| E1.5 | Wzorzec relacji w czasie: okres obowiązywania, status, historia zmian | Zmiana relacji nie kasuje poprzedniego stanu (A5-05) | Stan „na dzień”, zakończenie, wznowienie, historia, kontrola czasu w testach | 120 min |
| E1.6 | Wzorzec definicja → wersja → wynik wykonania | Wynik wskazuje dokładną wersję definicji (A5-11, A5-16) | Nowa wersja nie zmienia starych wyników | 120 min |
| E1.7 | Klasyfikacja danych (PUBLIC, INTERNAL, RESTRICTED, SECRET, SPECIAL CATEGORY) jako metadane pól + polityka redakcji | Audyt i eksport respektują klasę danych | Redakcja według klasy | 90 min |
| E1.8 | Idempotencja operacji (klucz żądania + skrót treści) | Ponowienie nie tworzy duplikatu; inna treść z tym samym kluczem jest odrzucana | Ponowienie, konflikt, test współbieżny na MySQL | 90 min |
| E1.9 | Zamknięcie E1: testy przekrojowe A5-03/04/05/11/16, dokumentacja wzorców, tag `e1-zamkniety` | Wzorce gotowe do użycia w E2 | Pełny zestaw lokalnie i w CI | 60 min |

## E2 — Tożsamość (A5 §1; A5-01, A5-02, A5-03)

| Podetap | Zakres | Wynik / kryterium zakończenia | Testy | Czas |
|---|---|---|---|---|
| E2.1 | PERSON: istnieje bez konta, globalny identyfikator publiczny, dane podstawowe z klasyfikacją | Osoba bez konta | Tworzenie, brak duplikacji przy zmianie kontekstu | 90 min |
| E2.2 | CONTACT: e-mail i telefon jako kanały, weryfikacja kanału, kontakt może należeć do innej osoby (opiekuna) | Kontakt ≠ tożsamość (A5 §1.3) | Weryfikacja, kontakt opiekuna, brak scalania po samym kontakcie | 120 min |
| E2.3 | ACCOUNT: rejestracja i logowanie (Fortify); jedna PERSON — najwyżej jedno konto | Konto jako warstwa dostępu (A5-02) | Rejestracja, logowanie, blokada drugiego konta dla PERSON | 120 min |
| E2.4 | Potwierdzenie e-maila konta i automatyczne powiązanie konta z PERSON o tym samym zweryfikowanym e-mailu, z zachowaniem historii | Późniejsze konto dołącza się do istniejącej osoby | Powiązanie, brak powiązania bez weryfikacji, konflikt, audyt | 120 min |
| E2.5 | Reset hasła, limity prób, bezpieczeństwo sesji (regeneracja, wylogowanie innych sesji) | A5 §12: sesje i odzyskiwanie dostępu | Limit prób, reset, unieważnienie sesji | 90 min |
| E2.6 | MFA (TOTP) z kodami odzyskiwania; mechanizm wymuszenia dla administratorów (egzekwowane w E3) | MFA gotowe do wymagania | Włączenie, logowanie z kodem, kod odzyskiwania, błędny kod | 120 min |
| E2.7 | Działanie w imieniu: relacja reprezentacji w czasie z jawnym zakresem; ACTOR ≠ SUBJECT w audycie | Rodzic (ACTOR) działa za dziecko (SUBJECT) w zakresie | Zakres, koniec relacji odbiera dostęp, audyt obu ról | 120 min |
| E2.8 | Ekrany: profil osoby, kontakty, bezpieczeństwo konta, reprezentowane osoby | Użytkownik zarządza własnymi danymi | Testy HTTP i odmów dostępu | 120 min |
| E2.9 | Zamknięcie E2: testy A5-01/02/03, dokumentacja, tag `e2-zamkniety` | Tożsamość gotowa pod organizacje (E3) | Pełny zestaw lokalnie i w CI | 60 min |

Etapy E3–E12 zostaną rozpisane na podetapy przed rozpoczęciem każdego z nich.

## Postęp wykonania

- 2026-09-26: E0.0–E0.6 ukończone; punkt wejścia E0.7: `1322651`, CI zielone (przebieg `36206144509`).
- E0.7: poprawiona kolejność uruchomienia z czystego klonu, opis zależności, budowania frontendu i ochrony danych. Pełny zestaw lokalny: **11 testów, 20 asercji**, MySQL 8.4. Zamknięcie potwierdza tag `e0-zamkniety`, nadawany po zielonym CI gałęzi.
- Użytkownik zlecił przejście z E0 do E1 w tej samej pracy; osobne pytanie o tę granicę nie jest wymagane.
- E0.7: czysta kopia kodu w `/tmp` — instalacja Composer/npm, build, 11 testów / 20 asercji oraz HTTP `/` i `/up`: 200. Pierwsze CI gałęzi (`36207359502`) nie przeszło startu/migracji; szczegółowy log wymaga dostępu GitHub. Lokalnie potwierdzono start kolejki przed powstaniem tabel. Rozdzielono start MySQL, jednorazową migrację i uruchomienie procesów aplikacji; wynik ponownego CI jest warunkiem scalenia.
- E0 zamknięty i scalony: `bc15ee4`, tag `e0-zamkniety`; poprawione CI gałęzi: `36207673981` — sukces.
- E1.1: kontekst ACTOR w HTTP, komendach i kolejce, jawny zakres integracji, izolacja po błędach i resecie pracownika. 13 nowych przypadków / 25 asercji; bez migracji bazy. Zasady: Z-011 i ARCHITEKTURA §5. Pełny zestaw lokalny: **24 testy / 45 asercji**. Scalenie wymaga zielonego CI.
- E1.1 scalony do `main`: `aff4819`; CI gałęzi `36208109842` — sukces.
- E1.2: audyt tylko do dopisywania, rozdzielenie ACTOR/SUBJECT, korelacja i kontekst organizacji, ochrona PHP oraz triggery MySQL. 12 nowych przypadków / 32 asercje; wariant administracyjnej usługi migracji bez zmiany globalnych ograniczeń MySQL. Szczegóły i ograniczenia: Z-012. Pełna regresja lokalna: **36 testów / 77 asercji**. Scalenie wymaga zielonego CI.
- E1.2 scalony do `main`: `ebbf114`; CI gałęzi `36223481216` — sukces.
- E1.3: automatyczny audyt zapisów i usunięć instancji User, wartości przed/po, redakcja sekretów, jawny powód oraz atomowość. 11 nowych przypadków / 36 asercji. Pełna regresja: **47 testów / 113 asercji**. Zakres i ograniczenia: Z-013. Scalenie wymaga zielonego CI.
- E1.3 scalony do `main`: `c1d4eba`.
- E1.4: audyt odmów dostępu (każde 403 i odmowa ukryta jako 404 przez `AccessDenied`) oraz odczytów danych chronionych (tylko nazwy pól). Zapis osobnym połączeniem `audit`, więc przetrwa wycofanie transakcji biznesowej. Limit 20/min dla pary wykonawca–cel; awaria audytu nie zmienia odpowiedzi. 10 nowych przypadków. Przy okazji: `ResolveActorTest` (E1.1) dostał transakcję testową, bo jego `abort(403)` jest teraz audytowane. Pełny zestaw: **57 testów / 176 asercji**, także w losowej kolejności. Zasady: Z-014, ARCHITEKTURA §8.
- E1.5: wzorzec relacji w czasie (`HasValidityPeriod`, `ValidityColumns`, `RelationStatus`): okresy półotwarte, stan na dzień, zawieszenie/wznowienie/zmiana funkcji jako nowy okres, zakaz nakładania i przepisywania historii, powód i audyt zmian. Test współbieżności: dwa procesy otwierają tę samą relację, powstaje dokładnie jeden okres. 9 nowych przypadków. Pełny zestaw: **66 testów / 202 asercje**, także w losowej kolejności. Zasady: Z-015, ARCHITEKTURA §9.
- E1.5 scalony do `main`: `183c8a5`; CI gałęzi `36237561921` i `main` `36237731788` — sukces.
- E1.6: wzorzec definicja → wersja → wynik: wspólna tabela `definition_versions` (zamrożona kopia JSON, skrót SHA-256, tylko dopisywanie), `HasVersions` (szkic → publikacja, numeracja bez luk, brak duplikatów tej samej treści, wersja w chwili, audyt publikacji), `RecordsDefinitionVersion` (wynik trwale wskazuje wersję właściwego typu). 9 nowych przypadków, w tym test współbieżności. Pełny zestaw: **75 testów / 225 asercji**, także w losowej kolejności. Zasady: Z-016, ARCHITEKTURA §10.
- E1.6 scalony do `main`: `3339b34` (CI gałęzi zielone).
- E1.7: klasyfikacja danych jako metadane pól (`DataClass`, `ClassifiesData`), polityki klas w `config/data_classification.php`, redakcja w audycie i eksporcie według klasy, audyt odczytu dla SPECIAL CATEGORY i SECRET (`RecordProtectedRead::forModel`), odmowa zapisu pola bez klasy. `auditVisibleFields`/`auditRedactedFields` zastąpione klasyfikacją. 7 nowych przypadków. Pełny zestaw: **82 testy / 252 asercje**, także w losowej kolejności. Zasady: Z-017, ARCHITEKTURA §11.
- E1.7 scalony do `main`: `428cd1f` (CI gałęzi zielone).
- E1.8: idempotencja operacji (`RunIdempotently`, tabela `idempotency_keys`): klucz na operację i wykonawcę, skrót treści, zwrot zapisanego wyniku przy ponowieniu, `IdempotencyConflict` dla innej treści, zwolnienie klucza po błędzie. Test współbieżny wykrył zakleszczenie oczekujących po wycofaniu pierwszego żądania — dodane ponowienie transakcji. 7 nowych przypadków. Pełny zestaw: **89 testów / 269 asercji**, także w losowej kolejności. Zasady: Z-018, ARCHITEKTURA §12.
- E1.8 scalony do `main`: `d8b8dbd` (CI gałęzi zielone).
- E1.9: zamknięcie E1 — przekrojowy test `E1AcceptanceTest` (A5-03/04/05/11/15/16 w jednym scenariuszu), macierz pokrycia [A5-POKRYCIE.md](A5-POKRYCIE.md), lista kontrolna nowego modelu (ARCHITEKTURA §13). Pełny zestaw: **90 testów / 280 asercji**, także w losowej kolejności. Tag `e1-zamkniety` po zielonym CI na `main`.
- E1.9 scalony do `main`: `a59d850` (CI gałęzi zielone). Tag `e1-zamkniety` po potwierdzeniu CI na `main`.
- E2.1: PERSON (`app/Domain/Identity`): globalna osoba bez konta, niezmienny publiczny ULID, dane podstawowe RESTRICTED, rejestracja bez automatycznego dopasowania, korekta z powodem. Nowe konteksty wskazują tę samą osobę. 10 nowych przypadków. Pełny zestaw: **100 testów / 304 asercje**, także w losowej kolejności. Założenia: Z-019.
- E2.1 scalony do `main`: `d217fd8` (CI gałęzi zielone).
- E2.2: CONTACT: kanały e-mail i telefon z normalizacją, właściciel-osoba (także opiekun), brak łączenia osób po wspólnym adresie, usunięcie z historią, weryfikacja jednorazowym kodem (skrót HMAC, ważność, limit prób i próśb). 15 nowych przypadków. Pełny zestaw: **115 testów / 340 asercji**, także w losowej kolejności. Założenia: Z-020.
- E2.2 scalony do `main`: `1ae3c31` (CI gałęzi zielone).
- E2.3: ACCOUNT: Fortify (tylko rejestracja i logowanie), widoki logowania i rejestracji, konto bez PERSON do czasu weryfikacji, `LinkAccountToPerson` (jedna PERSON — najwyżej jedno konto, bez przenoszenia konta), hasło min. 12 znaków, audyt zapisów frameworka z powodem technicznym. Passkeys wyłączone. 9 nowych przypadków. Pełny zestaw: **124 testy / 385 asercji**, także w losowej kolejności. Założenia: Z-021.
- E1 zamknięty: CI na `main` zielone dla wszystkich scaleń E1 (ostatnie: przebieg `36239051281`); tag `e1-zamkniety` na `a59d850`.
- E2.3 scalony do `main`: `4ef0447` (CI gałęzi zielone).
- E2.4: weryfikacja e-maila konta (Fortify, podpisany link) i automatyczne powiązanie z PERSON: jedna osoba ze zweryfikowanym kontaktem → dołączenie z zachowaniem historii; brak → nowa osoba ze zweryfikowanym kontaktem; niejednoznaczność → konflikt w audycie bez powiązania. 8 nowych przypadków. Pełny zestaw: **132 testy / 409 asercji**, także w losowej kolejności. Założenia: Z-022.
- E2.4 scalony do `main`: `eb8b3a3` (CI gałęzi zielone).
- E2.5: reset hasła (Fortify, widoki, neutralna odpowiedź bez ujawniania kont), zakończenie wszystkich sesji i tokenu „zapamiętaj mnie” po resecie, limit logowania 5/min, regeneracja sesji, „wyloguj pozostałe urządzenia” z hasłem i `AuthenticateSession`, audyt zapisów frameworka. 7 nowych przypadków. Pełny zestaw: **139 testów / 463 asercje**, także w losowej kolejności. Założenia: Z-023.
- E2.5 scalony do `main`: `1866bb0` (CI gałęzi zielone).
- E2.6: MFA TOTP (Fortify) z potwierdzeniem kodem, kody odzyskiwania jednorazowe, limit prób, wyłączenie; audytowane podklasy akcji Fortify; middleware `mfa` do wymuszenia dla administratorów (od E3); widoki wyzwania MFA i potwierdzenia hasła. 8 nowych przypadków. Pełny zestaw: **147 testów / 523 asercje**, także w losowej kolejności. Założenia: Z-024.
- E2.6 scalony do `main`: `ae6b8a5` (CI gałęzi zielone).
- E2.7: działanie w imieniu — reprezentacja jako relacja w czasie z rodzajem i jawnymi zakresami, `ActOnBehalf` (sprawdzenie w chwili działania, odmowa audytowana z SUBJECT-em reprezentowanej osoby, wpis `person.acted_on_behalf`), zmiana zakresów i zakończenie z historią. Poprawka E1.5: `ValidityColumns` nadaje indeksom krótkie nazwy (limit 64 znaków MySQL). 8 nowych przypadków. Pełny zestaw: **155 testów / 545 asercji**, także w losowej kolejności. Założenia: Z-025.
- E2.7 scalony do `main`: `bdac95c` (CI gałęzi zielone).
- E2.8: ekrany konta (`/account`): moje dane, kontakty z weryfikacją kodem, bezpieczeństwo (MFA, zmiana hasła — włączone `updatePasswords`, wylogowanie urządzeń), osoby reprezentowane z podglądem i edycją w zakresie; cudze zasoby jako 404 z audytem odmowy. 10 nowych przypadków HTTP. Pełny zestaw: **165 testów / 612 asercji**, także w losowej kolejności. Założenia: Z-026.
- E2.8 scalony do `main`: `f4c5d9a` (CI gałęzi zielone).
- E2.9: zamknięcie E2 — scenariusz przekrojowy `E2AcceptanceTest` (A5-01/02/03: osoba w dwóch kontekstach, późniejsze konto dołącza do istniejącej osoby z zachowaniem historii, matka jako ACTOR zmienia dane córki jako SUBJECT), macierz A5-POKRYCIE, ARCHITEKTURA §14. Pełny zestaw: **166 testów / 624 asercje**, także w losowej kolejności. Tag `e2-zamkniety` po zielonym CI na `main`.
