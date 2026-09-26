# Rejestr założeń

Założenia przyjęte tam, gdzie specyfikacja A5 nie rozstrzyga sprawy ([PLAN-ETAPOW.md](PLAN-ETAPOW.md), zasady 2 i 3).
Każde założenie ma być łatwe do zmiany. Wpis wskazuje, gdzie je zmienić.

Rodzaje:
- **T** — decyzja techniczna (zasada 2);
- **F** — założenie funkcjonalne widoczne dla użytkownika (zasada 3), do przeglądu przez Jakuba.

| ID | Data | Rodzaj | Założenie | Uzasadnienie | Jak zmienić |
|---|---|---|---|---|---|
| Z-001 | 2026-09-26 | T | Stos: Laravel 13, PHP 8.3, MySQL 8.4 (produkcja), Blade + Tailwind + Alpine.js | Najprostszy znany stos; sprawdzona infrastruktura z archiwum | Wymiana warstwy widoku bez zmiany domeny |
| Z-002 | 2026-09-26 | T | Testy zawsze na MySQL 8.4 i bazie `udzio_test` (lokalnie `docker compose`, port 3307; w CI MySQL 8.4); dwa strażniki blokują inną bazę | SQLite ukrywa błędy blokad (ustalenie z etapu 0 archiwum) | Konfiguracja phpunit |
| Z-003 | 2026-09-26 | T | ~~Lokalnie bez Dockera~~ → **zmienione 2026-09-26:** Docker 29.8 i Compose v5.5 zainstalowane w WSL. Lokalna baza MySQL 8.4 (jak na produkcji) działa w `docker compose`; PHP nadal lokalnie | Ta sama wersja MySQL lokalnie, w CI i na produkcji | `compose.yaml` |
| Z-004 | 2026-09-26 | T | Czas w aplikacji i bazie w UTC; prezentacja w strefie użytkownika (domyślnie Europe/Warsaw) dodana przy pierwszych ekranach z datami | Jednoznaczna historia i audyt (A5 §3.3, §11) | `config/app.php` `timezone` + formatowanie w widokach |
| Z-005 | 2026-09-26 | T | ~~Bez Laravel Boost~~ → **zmienione 2026-09-26:** Laravel Boost ^2.10 zainstalowany przez Krzysztofa dla asystenta Codex (`AGENTS.md`, `.agents/skills/`, `boost.json`). Wytyczne Boosta obowiązują obok [PLAN-ETAPOW.md](PLAN-ETAPOW.md); przy konflikcie pierwszeństwo mają specyfikacja A5 i zasady projektu | Decyzja Krzysztofa | `php artisan boost:install` ponownie albo usunięcie pakietu |
| Z-006 | 2026-09-26 | T | Klucze wewnętrzne `bigint`; obiekty widoczne na zewnątrz (URL, QR, API) mają publiczny ULID | Bez ujawniania kolejnych numerów; proste relacje wewnętrzne | [ARCHITEKTURA.md](ARCHITEKTURA.md) §2 |
| Z-007 | 2026-09-26 | T | Kwoty jako liczby całkowite w najmniejszej jednostce + kod waluty; bez `float`/`double` (sprawdzane testem) | Dokładność rozliczeń (A5 §8) | [ARCHITEKTURA.md](ARCHITEKTURA.md) §2 |
| Z-008 | 2026-09-26 | T | Statusy i typy jako PHP `enum` zapisywany tekstowo; bez `ENUM` MySQL (sprawdzane testem) | Nowa wartość bez migracji schematu; konfiguracja zamiast zaszycia | [ARCHITEKTURA.md](ARCHITEKTURA.md) §2 |
| Z-009 | 2026-09-26 | T | Moduły domenowe w `app/Domain/<Moduł>` z zamkniętej listy pojęć A5; akcje jako klasy jednej operacji; cienkie kontrolery | Granice A5, zakaz modułów branżowych (A5-17), logika poza ekranami (A5 §13) | [ARCHITEKTURA.md](ARCHITEKTURA.md) §1, `ArchitectureTest::MODULES` |
| Z-010 | 2026-09-26 | T | Produkcja w Docker Compose (`compose.production.yaml`, projekt `udzio-prod`): nginx + PHP-FPM 8.3 + kolejka + harmonogram + MySQL 8.4; sekrety tylko w `.env.production` na serwerze; HTTPS (certbot) w E12 | Powtarzalne wdrożenie, ta sama wersja co w testach; bez sekretów w Git | `compose.production.yaml`, `docker/production/` |

## Z-011 — Kontekst wykonawcy (E1.1, techniczne, 2026-09-26)

ACTOR ma typ `account`, `process`, `integration` albo `anonymous` i identyfikator bez danych kontaktowych.
Do czasu E2 uwierzytelniony wykonawca wskazuje identyfikator szkieletowego konta `User`; nie udaje PERSON.
Warstwa Identity rozwinie powiązanie z PERSON. SUBJECT pozostaje niezależnym argumentem przyszłego audytu.
HTTP rozpoznaje konto dopiero przy odczycie kontekstu, po wykonaniu uwierzytelnienia; nagłówki i parametry
żądania nie ustalają ACTOR-a. Kod zaufanej integracji może użyć `runAs` po własnej weryfikacji.

Komenda oraz zadanie (także kolejka sync) mają wykonawcę `process` identyfikowanego nazwą komendy/klasą zadania.
Nie dziedziczą automatycznie tożsamości ani uprawnień zlecającego. Jeżeli konkretna operacja ma działać
w imieniu osoby, musi przekazać jej odniesienie i ponownie sprawdzić zakres dostępu; sama zmiana ACTOR-a
tego nie realizuje. Jest to decyzja techniczna o rozdzieleniu wykonawcy i inicjatora, możliwa do rozszerzenia
w kontrakcie danego zadania. Kontekst nie przechowuje treści zadania, sekretów ani e-maila.

Kontekst jest `scoped`; zagnieżdżone zakresy przywracają poprzednią wartość także po wyjątku.
Najbardziej wewnętrzny zakres ma pierwszeństwo. Poza konkretnym wejściem konsolowym wykonawcą jest
`process:application`, poza HTTP — anonimowy dla środowiska WWW. Zmiana polityki odbywa się w providerze
Platform i middleware, bez migracji danych. Brak zmian w schemacie bazy w E1.1.

## Z-012 — Audyt tylko do dopisywania (E1.2, techniczne, 2026-09-26)

`RecordAudit` zapisuje synchronicznie na domyślnym połączeniu biznesowym. W transakcji wywołującego
wpis zatwierdza się lub wycofuje razem z operacją. ACTOR pochodzi z kontekstu E1.1; SUBJECT i organizacja
są odrębnymi, jawnymi odniesieniami historycznymi (typ + identyfikator, bez kaskadowego usuwania).
Organizacja może być pusta dla operacji globalnych. Korelacja jest ULID-em: wywołujący przekazuje ten sam
identyfikator dla powiązanych faktów; brak argumentu tworzy nową korelację. Czas zapisujemy w UTC
z mikrosekundami. Wyniki: `succeeded`, `denied`, `failed`. Powód opcjonalny; obowiązek dla konkretnych
zmian będzie egzekwowany przez operacje i E1.3. Nie powstały endpointy odczytu audytu przed E3.

Model odrzuca edycję/usuwanie; triggery MySQL blokują również UPDATE, DELETE i aktualizującą gałąź UPSERT.
To ochrona operacji DML, nie ochrona przed administratorem bazy: DROP/TRUNCATE i usunięcie triggerów
wymagają osobnego ograniczenia praw DDL konta wykonawczego przed produkcją. Obecne konto instalacyjne
`udzio` z obrazu MySQL ma nadal uprawnienia schematu nadane przez obraz — E1.2 nie oznacza utwardzenia E12.
Nie uznajemy triggerów za zabezpieczenie przed przejęciem konta administratora.

MySQL zachowuje `log_bin_trust_function_creators=0`. Triggery instaluje uprzywilejowany proces migracji:
lokalnie/testowo administrator deweloperskiej bazy, w Compose produkcyjnym jednorazowa usługa `migrate`
z profilem `tools`. Konto root nie jest kontem zwykłego procesu aplikacji; zmienna z hasłem roota jest
maskowana pustą wartością w środowisku app/queue/scheduler. Kontener migracji nie jest usługą stale działającą.
Wymóg konta migracyjnego wynika z zasad MySQL 8.4 przy włączonym binlogu:
https://dev.mysql.com/doc/refman/8.4/en/stored-programs-logging.html

Wycofanie E1.2: cofnąć kod aplikacji, zachowując tabelę i triggery. `down()` odmawia usunięcia tabeli,
jeśli zawiera historię; pustą tabelę można usunąć. Przed zmianą instalacji wykonać kopię bazy.
Nie przewidujemy fabryki/seedera wpisów audytu — testy tworzą fakty wyłącznie przez `RecordAudit`.

## Z-013 — Automatyczny audyt modeli (E1.3, techniczne, 2026-09-26)

Modele dołączają `AuditsChanges` i jawnie deklarują typ SUBJECT-u, organizację oraz pola widoczne
lub redagowane. Aktualnie jedynym takim modelem jest szkieletowe konto `User` (globalne, bez organizacji).
Imię/nazwa, e-mail, hasło (także hash) i token pamiętania są redagowane. Data weryfikacji pozostaje widoczna.
Pozostałe kolumny techniczne, w tym `updated_at`, nie generują historii zmian. Klasyfikacja ogólna E1.7
rozwinie tę politykę; nowe pola biznesowe trzeba jawnie przypisać do jednej z list.

Zapis/edycja/usunięcie instancji i wpis audytu są jedną transakcją. Przy edycji pobierany jest aktualny
stan wiersza z blokadą `FOR UPDATE`, więc stara instancja modelu nie podaje nieaktualnej wartości „przed”.
Brak zmian pól audytowanych nie tworzy wpisu. Porównanie odbywa się przed redakcją: zmiana sekretu
zostawia fakt zmiany, mimo że obie wartości pokazują `[REDACTED]`. Tworzenie ma domyślny powód
`record.created`; edycja i usuwanie wymagają jawnego `AuditReason::because(...)`. Powód wraca do
poprzedniego po bloku, również po wyjątku. Nie jest parametrem klienta nadawanym automatycznie przez HTTP.

Mechanizm obejmuje `save`, `update` instancji, `delete` i warianty quiet. Surowy SQL, masowe operacje
buildera, `increment` oraz `saveOrIgnore` nie są objęte tym kontraktem: w audytowanym kodzie biznesowym
nie wolno zastępować nimi zapisu instancji bez osobnej, atomowej operacji audytu. Nie ma obecnie takich
operacji biznesowych na User. `AuditEntry` nie używa traitu, aby nie audytować audytu rekurencyjnie.
Operacje na innym połączeniu oraz zmiana klucza istniejącego modelu są odrzucane. Po błędzie transakcji
trzeba odczytać model ponownie przed dalszą pracą; obiekt PHP nie jest snapshotem bazy.

E1.3 dodaje tylko nullable JSON `before_values` i `after_values`. Stare wpisy pozostają niezmienione.
Wycofanie kodu zachowuje kolumny; `down()` odmawia ich usunięcia, jeśli zawierają historię.
Dla przyszłych modułów kontekstowych implementacja musi jawnie zwracać właściwą organizację.

## Z-014 — Audyt odmów i odczytów danych chronionych (E1.4, techniczne, 2026-09-26)

- **Niezależny zapis:** odmowy i odczyty danych chronionych trafiają do `audit_entries` przez osobne połączenie
  `audit` do tej samej bazy. Odmowa zwykle przerywa operację biznesową, a jej ślad nie może zniknąć razem
  z wycofaną transakcją (A5-14). Zwykłe zmiany (E1.2/E1.3) pozostają atomowe z operacją.
- **Blokady:** InnoDB nie zwalnia blokad po wycofaniu do punktu zapisu (savepoint), dopóki trwa zewnętrzna
  transakcja. Zapis przez połączenie `audit` mógłby wtedy czekać na blokady tej samej operacji. Połączenie
  `audit` ma więc `innodb_lock_wait_timeout = 5` s, a błąd zapisu jest logowany (`critical`) bez zmiany
  odpowiedzi klienta. Zauważone w testach z transakcją testową; w produkcji dotyczy tylko transakcji
  zagnieżdżonych.
- **Co jest odmową:** każda odpowiedź 403 oraz `AuthorizationException` (w tym `AccessDenied` ukryty jako 404).
  Zwykłe 404 (brak trasy lub rekordu) nie jest odmową.
- **Dane w audycie:** status, metoda, nazwa trasy (albo wzorzec URI), zdolność. Bez parametrów zapytania,
  treści żądania i wartości pól. Odczyt chroniony zapisuje tylko nazwy pól i cel.
- **Limit:** 20 wpisów na minutę dla pary wykonawca–cel (pamięć podręczna aplikacji). Nadmiar jest pomijany
  i logowany (`warning`), żeby zalew odmów nie wypełnił audytu.
- **Poza zakresem E1.4:** automatyczne wykrywanie pól chronionych (E1.7) oraz odmowy poza HTTP bez jawnego
  wywołania. Weryfikacja: 10 nowych przypadków; pełny zestaw 57 testów / 176 asercji, także w losowej
  kolejności (4 przebiegi).

## Z-015 — Relacje w czasie (E1.5, techniczne + funkcjonalne, 2026-09-26)

- **Okres półotwarty:** relacja obowiązuje w `[valid_from, valid_to)`; `valid_to = NULL` oznacza okres otwarty.
  Czas w UTC z dokładnością do mikrosekund. Pytanie „na dzień” to `activeAt($chwila)` albo `effectiveAt($chwila)`.
- **Klucz relacji:** model deklaruje `validityKey()` (np. osoba + organizacja). Dla jednego klucza okresy nie
  nachodzą na siebie i istnieje najwyżej jeden okres otwarty. Pilnują tego blokada `lockForUpdate` na kluczu
  oraz unikalna kolumna generowana `open_key` w MySQL (druga linia obrony przy wyścigu).
- **Zmiana = nowy okres:** zawieszenie, wznowienie i zmiana funkcji zamykają bieżący okres i otwierają nowy
  (`transition`). Zakończenie (`end`) tylko zamyka okres. Poprzednie okresy zostają w historii (A5 §11).
- **Statusy:** `active`, `suspended` (`RelationStatus`). Zawieszona relacja jest obowiązująca, ale nieaktywna.
  Nowy status dodaje się w enumie bez migracji.
- **F — bez przepisywania historii:** zamkniętego okresu nie można edytować, a `valid_from` nie zmienia się.
  Przejście musi nastąpić po początku bieżącego okresu. Korekta wsteczna (np. błędnie wpisana data przyjęcia)
  nie jest w E1.5 obsługiwana; jeśli będzie potrzebna, powstanie jako jawna, audytowana operacja korekty.
  Zmiana: `HasValidityPeriod::bootHasValidityPeriod`.
- **Audyt i powód:** modele relacji używają też `AuditsChanges`, więc zamknięcie okresu wymaga powodu,
  a otwarcie nowego jest audytowane jako utworzenie.
- **Pierwsze użycia:** reprezentacja (E2.7) i członkostwo (E3). W E1.5 wzorzec jest sprawdzany na modelu
  testowym `Tests\Fixtures\ValidityProbe` (migracja w `tests/Fixtures/migrations`, ładowana tylko w testach).
  Weryfikacja: 9 nowych przypadków, w tym test współbieżności (dwa procesy, jeden okres otwarty).

## Z-016 — Definicja → wersja → wynik (E1.6, techniczne + funkcjonalne, 2026-09-26)

- **Wspólna tabela wersji:** `definition_versions` przechowuje zamrożoną kopię (`content`, JSON) każdej
  opublikowanej definicji dowolnego typu (`definition_type` = typ SUBJECT-u definicji). Wersje są tylko do
  dopisywania (ochrona w PHP i wyzwalacze MySQL, jak w audycie). Wynik wskazuje `definition_version_id`
  z kluczem obcym `restrictOnDelete` i nie może go zmienić (A5-11, A5-16). Jeśli moduł będzie potrzebował
  relacyjnej struktury wersji (np. pola formularza), może dodać własne tabele podrzędne wobec wersji.
- **Szkic i publikacja:** model definicji jest edytowalnym szkicem. Wynik powstaje tylko według wersji
  opublikowanej przez `publishVersion()`. Numeracja 1, 2, 3… bez luk dla jednej definicji (blokada wiersza
  definicji; test współbieżności).
- **F — ponowna publikacja bez zmian nie tworzy wersji:** identyczna treść (skrót SHA-256 kanonicznego JSON,
  klucze posortowane, kolejność list zachowana) zwraca ostatnią wersję. Zmiana: `HasVersions::publishVersion`.
- **F — wersja obowiązująca w chwili:** `versionAt($chwila)` to ostatnia wersja opublikowana do tej chwili.
  Publikacja z datą przyszłą ani wsteczną nie jest w E1.6 obsługiwana.
- **Audyt:** publikacja zapisuje `<typ>.version_published` z numerem i skrótem, wykonawcę i (opcjonalnie) powód.
  Powód nie jest wymagany, bo publikacja nie zmienia istniejących wyników.
- **Weryfikacja:** modele testowe `DefinitionProbe` i `DefinitionResultProbe`; 9 nowych przypadków, w tym
  test współbieżności publikacji. Migracje testowe mają datę `9999_…`, żeby zawsze działały po migracjach aplikacji.

## Z-017 — Klasyfikacja danych i polityki klas (E1.7, funkcjonalne, 2026-09-26)

- **Metadane pól:** klasa jest przypisana do pola modelu (`dataClassification()`). Model audytowany musi
  sklasyfikować każde zapisywane pole; pole bez klasy nie trafia ani do audytu, ani do eksportu.
- **F — domyślne polityki** (`config/data_classification.php`, do przeglądu przez Jakuba):
  - PUBLIC i INTERNAL: wartości w audycie, eksport pełny;
  - RESTRICTED (np. imię, e-mail): w audycie tylko fakt zmiany (`[REDACTED]`), eksport pełny dla uprawnionych;
  - SPECIAL CATEGORY (np. przynależność polityczna, zdrowie): w audycie `[REDACTED]`, odczyt audytowany,
    w eksporcie `[REDACTED]`;
  - SECRET (np. hasło, token, tajny głos): w audycie `[REDACTED]`, odczyt audytowany, w eksporcie pominięte.
- **Klasa ustalana przez scenariusz:** Core daje domyślną klasę pola; scenariusz może ją podnieść
  (A5 §12). Konfiguracja klas per scenariusz powstanie razem z pierwszym scenariuszem, który tego potrzebuje.
- **Poza zakresem E1.7:** szyfrowanie danych w spoczynku, retencja i anonimizacja (późniejsze etapy),
  uprawnienie do eksportu danych SPECIAL CATEGORY bez redakcji (E3, ROLE/PERMISSION).
- **Weryfikacja:** 7 nowych przypadków; pełny zestaw 82 testy / 252 asercje, także w losowej kolejności.

## Z-018 — Idempotencja operacji (E1.8, techniczne + funkcjonalne, 2026-09-26)

- **Tożsamość żądania:** (operacja `scope`, wykonawca `owner` = typ:identyfikator ACTOR-a, klucz klienta).
  Klucz: 8–191 znaków ASCII, np. ULID/UUID z nagłówka `Idempotency-Key`. Klucze różnych wykonawców
  i różnych operacji się nie mieszają, więc cudzy klucz nie ujawnia cudzego wyniku. Anonimowi wykonawcy
  dzielą przestrzeń kluczy — klucz musi być losowy (co najmniej 128 bitów).
- **Ta sama treść:** skrót SHA-256 kanonicznego JSON (jak w E1.6). Ponowienie z tą samą treścią zwraca zapisany
  wynik bez ponownego wykonania; inna treść z tym samym kluczem → `IdempotencyConflict` (w HTTP: 409, gdy
  powstanie warstwa API).
- **Atomowość:** klucz i wynik są zapisywane w tej samej transakcji co operacja. Nieudana operacja zwalnia
  klucz — ponowienie wykona ją od nowa. Wynik musi być zgodny z JSON.
- **Współbieżność:** równoległe żądanie czeka na unikalnym indeksie i dostaje zapisany wynik. Gdy pierwsze
  żądanie zostanie wycofane, oczekujące mogą się zakleszczyć; transakcja ponawia się do 3 razy. Ponowienie
  działa tylko dla transakcji zewnętrznej — wywołuj `RunIdempotently` na zewnątrz transakcji biznesowej.
- **F — czas przechowywania kluczy:** bez wygasania w E1.8. Czyszczenie starych kluczy (np. po 30 dniach)
  dojdzie z zadaniami utrzymaniowymi (E12); kolumna `created_at` ma indeks.
- **Weryfikacja:** 7 nowych przypadków, w tym test współbieżności (dwa procesy, operacja wykonana raz).

## Z-019 — PERSON (E2.1, funkcjonalne, 2026-09-26)

- **Globalna tożsamość:** tabela `people` nie ma kolumny organizacji ani scenariusza. Kontekst (członkostwo,
  zapis, rola) wskazuje osobę przez relację, więc nowy kontekst nie tworzy drugiej PERSON (A5-01).
  Widoczność danych osoby ograniczy SCOPE/CONTEXT (E3).
- **F — dane podstawowe:** imię, nazwisko (wymagane), data urodzenia (opcjonalna, `RRRR-MM-DD`,
  od 1900 r. do dziś). Wszystkie RESTRICTED: audyt zapisuje fakt zmiany, nie wartość. Dodatkowe pola
  (płeć, PESEL, adres) dopiero, gdy wymaga ich scenariusz — z własną klasą danych (PESEL co najmniej RESTRICTED).
- **F — brak automatycznego dopasowania:** rejestracja zawsze tworzy nową PERSON. Te same imię, nazwisko
  i data urodzenia mogą należeć do dwóch osób, a błędne połączenie jest trudne do odwrócenia. Rozpoznanie
  przez zweryfikowany kontakt/konto — E2.4; łączenie duplikatów — MERGE (A5-13, późniejszy etap).
  Zmiana: `RegisterPerson`.
- **Identyfikator:** publiczny ULID (`public_id`) jest niezmienny; na zewnątrz nigdy wewnętrzne `id`.
- **Weryfikacja:** 10 nowych przypadków (w tym 4 warianty walidacji).

## Z-020 — CONTACT i weryfikacja kanału (E2.2, funkcjonalne, 2026-09-26)

- **Kontakt ≠ osoba:** kontakt ma jednego właściciela (PERSON). Ten sam adres może mieć kilka osób
  (wspólny e-mail rodziny) — nigdy ich nie łączy. Kontakt opiekuna używany w sprawie dziecka pozostaje
  kontaktem opiekuna; powiązanie dziecka z opiekunem da relacja reprezentacji (E2.7).
- **F — normalizacja:** e-mail przycinany i zapisywany małymi literami; telefon w formacie E.164. Numer bez
  prefiksu dostaje kod kraju z `config/identity.php` (domyślnie +48), `00` zamieniane na `+`.
- **Niezmienność:** adres i właściciel kontaktu się nie zmieniają; nowy adres = nowy kontakt. Usunięcie
  ustawia `removed_at` (historia zostaje) i unieważnia oczekujące kody. Ta sama osoba nie ma dwóch
  aktywnych identycznych kontaktów (unikalna kolumna generowana `active_key`).
- **F — weryfikacja kodem:** 6 cyfr, ważny 30 min, 5 błędnych prób unieważnia kod, 5 próśb o kod na godzinę
  na kontakt; nowy kod unieważnia poprzedni. Przechowywany jest tylko HMAC-SHA256 kodu. Komunikat błędu
  nie zdradza przyczyny. Wszystkie wartości w `config/identity.php`.
- **F — SMS:** operator SMS nie jest jeszcze wybrany. Weryfikacja telefonu zapisuje kod, ale go nie
  dostarcza (ostrzeżenie w logu, bez kodu). Do uzupełnienia przy wyborze operatora (E10 lub wcześniej).
- **Klasy danych:** adres RESTRICTED, pozostałe pola INTERNAL.
- **Weryfikacja:** 15 nowych przypadków (w tym 4 warianty normalizacji).

## Z-021 — ACCOUNT, rejestracja i logowanie (E2.3, techniczne + funkcjonalne, 2026-09-26)

- **Fortify:** uwierzytelnianie przez Laravel Fortify (widoki Blade własne). Funkcje włączane podetapami:
  rejestracja w E2.3, weryfikacja e-mail E2.4, reset hasła E2.5, MFA E2.6, ekrany profilu E2.8.
  Passkeys (dostarczane z Fortify) wyłączone — poza planem; do rozważenia później.
- **Konto nie jest osobą:** rejestracja tworzy tylko ACCOUNT (`users`) z imieniem i nazwiskiem podanymi
  przy rejestracji. Powiązanie z PERSON następuje po weryfikacji e-maila (E2.4): z istniejącą osobą albo
  z nową. Do tego czasu konto nie ma dostępu do danych żadnej osoby.
- **Jedna PERSON — najwyżej jedno konto:** unikalne `users.person_id` + `LinkAccountToPerson` (blokady,
  konflikt `AccountLinkConflict`). Powiązane konto nie przechodzi do innej osoby (ochrona w modelu).
- **F — hasło:** co najmniej 12 znaków, bez wymogów składu (zalecenie NIST SP 800-63B). Sprawdzanie
  w bazie wycieków (HIBP) — do rozważenia w E2.5 (wymaga połączenia zewnętrznego). Zmiana:
  `AppServiceProvider::boot` (`Password::defaults`).
- **F — e-mail logowania:** zapisywany małymi literami, unikalny dla konta. Logowanie ignoruje wielkość liter.
- **Audyt zapisów frameworka:** rotacja tokenu „zapamiętaj mnie” i przeliczenie skrótu hasła idą przez
  `AuditedUserProvider` z jawnym powodem technicznym; wartości SECRET są redagowane.
- **Weryfikacja:** 9 nowych przypadków; zestaw po zmianie tabeli `users` (zamiast `name`: `given_name`,
  `family_name`, `person_id`).
