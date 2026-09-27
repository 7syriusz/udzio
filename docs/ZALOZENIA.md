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

## Z-017 — Klasyfikacja danych i polityki klas (E1.7, zaktualizowane 2026-09-27; decyzja techniczno-bezpieczeństwowa)

**Status:** rekomendowane wartości domyślne — do akceptacji Jakuba (skutki dla użytkownika poniżej).
Klasa jest metadaną pola (`dataClassification()`); model audytowany musi sklasyfikować każde zapisywane
pole, a pole bez klasy nie trafia ani do audytu, ani do eksportu. Scenariusz może podnieść klasę pola
(A5 §12). Wartości: `config/data_classification.php`.

| Klasa (przykłady) | Wartość w historii zmian | Eksport | Odczyt zapisywany | Przechowywanie | Po upływie / na żądanie usunięcia |
|---|---|---|---|---|---|
| PUBLIC (nazwa wydarzenia) | widoczna | pełny | nie | dopóki istnieje rekord | zostaje |
| INTERNAL (statusy, daty, identyfikatory) | widoczna | pełny | nie | dopóki istnieje rekord | zostaje |
| RESTRICTED (imię, nazwisko, e-mail, telefon, data urodzenia) | tylko fakt zmiany | pełny dla uprawnionych | nie | 2 lata od końca ostatniej relacji | anonimizacja |
| SPECIAL CATEGORY (zdrowie, przynależność polityczna) | tylko fakt zmiany | zamaskowany | tak | 1 rok od końca celu | usunięcie |
| SECRET (hasło, tokeny, sekret MFA, tajny głos) | tylko fakt zmiany | nigdy | tak | tylko do końca celu | usunięcie |

**Skutki widoczne dla użytkownika:**
- W historii zmian operator widzi, **że** zmieniono np. nazwisko albo e-mail, ale nie widzi poprzedniej ani
  nowej wartości danych osobowych.
- Eksport dla uprawnionej osoby zawiera dane kontaktowe; dane szczególnej kategorii są zamaskowane, a hasła
  i sekrety nigdy nie są eksportowane.
- Każde wyświetlenie danych szczególnej kategorii zostawia ślad „kto, kiedy, jakie pola, w jakim celu”.
- Po 2 latach od zakończenia ostatniej relacji dane osobowe są anonimizowane; rozliczenia i statystyki
  zostają, ale bez możliwości wskazania osoby. Dane szczególnej kategorii są usuwane po roku od końca celu.
- Żądanie usunięcia danych działa tak samo jak koniec retencji: dane osobowe znikają lub są anonimizowane,
  historia rozliczeniowa i raportowa zostaje (A5 §12).

**Wdrożenie:** redakcja w audycie i eksporcie oraz audyt odczytu działają od E1.7. Retencja i usuwanie są
na razie polityką w konfiguracji (`retention_days`, `erasure`); harmonogram czyszczenia i obsługa żądań
usunięcia powstaną w etapie prywatności/utrzymania (najpóźniej E12). Szyfrowanie w spoczynku — E12.

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

## Z-020 — CONTACT i weryfikacja kanału (E2.2, zaktualizowane 2026-09-27; funkcjonalne)

- **Kontakt ≠ osoba:** kontakt ma jednego właściciela (PERSON). Ten sam adres może mieć kilka osób
  (wspólny e-mail rodziny) — nigdy ich nie łączy. Kontakt używany w cudzej sprawie pozostaje kontaktem
  właściciela; powiązanie osób daje REPRESENTATION (Z-025).
- **Normalizacja:** e-mail przycinany i zapisywany małymi literami; telefon w formacie E.164. Numer bez
  prefiksu dostaje kod kraju z `config/identity.php` (domyślnie +48), `00` zamieniane na `+`.
- **Niezmienność:** adres i właściciel kontaktu się nie zmieniają; nowy adres = nowy kontakt. Usunięcie
  ustawia `removed_at` (historia zostaje) i unieważnia oczekujące kody.
- **Weryfikacja kodem:** 6 cyfr, ważny 30 min, 5 błędnych prób unieważnia kod, 5 próśb na godzinę; nowy kod
  unieważnia poprzedni; przechowywany tylko HMAC-SHA256 kodu. Wartości w `config/identity.php`.
- **Decyzja — telefon bez operatora SMS:** dopóki operator nie jest wybrany, telefon **nie może** otrzymać
  statusu zweryfikowanego (blokada w modelu `Contact`), nie powstaje żaden kod, a ekran i API nie informują
  o wysłaniu kodu — pokazują „potwierdzanie tego kanału nie jest jeszcze dostępne”.
- **Kontrakt dostawcy:** `App\Domain\Identity\Contracts\ContactCodeSender` (`send(contact, code, ttl)`),
  rejestr `identity.contacts.senders` (e-mail: `MailContactCodeSender`, telefon: `null`). Podłączenie
  operatora SMS = klasa implementująca kontrakt + wpis w konfiguracji; reszta procesu bez zmian.
- **Weryfikacja:** 15 przypadków z E2.2 + 3 dla kontraktu i blokady telefonu.

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

## Z-022 — Weryfikacja e-maila konta i powiązanie z PERSON (E2.4, zaktualizowane 2026-09-27; funkcjonalne)

- **Weryfikacja:** podpisany link (ważny 60 min) wysyłany po rejestracji; ponowne wysłanie z `/email/verify`.
- **Jednoznaczny przypadek łączy automatycznie:** jedna osoba z tym samym zweryfikowanym, aktywnym kontaktem
  e-mail i bez konta → konto dołącza do niej; jej tożsamość i historia bez zmian (A5-02). Brak takiej osoby
  → nowa PERSON z danych rejestracji + zweryfikowany kontakt.
- **Decyzja — niejednoznaczność:** kilka możliwych osób (albo osoba z innym kontem) → system **nie scala
  i nie łączy** niczego automatycznie i nie pokazuje użytkownikowi danych znalezionych osób ani organizacji.
  Otwiera kontrolowaną procedurę naprawczą `PersonLinkReview` (jedno otwarte zgłoszenie na konto, lista
  kandydatów widoczna tylko dla roli rozstrzygającej) i zapisuje audyt `account.person_link_conflict`.
  Użytkownik widzi neutralny komunikat o dodatkowej weryfikacji z numerem zgłoszenia.
- **Rozstrzygnięcie:** `ResolvePersonLinkReview` — po dodatkowej weryfikacji łączy konto z jednym z kandydatów
  albo z nową osobą; inna osoba jest odrzucana; rozstrzygnięte zgłoszenie jest niezmienne i audytowane.
  Uprawnienie do rozstrzygania i ekran operatora — E3 (role).
- **Bez weryfikacji nie ma powiązania:** niezweryfikowany kontakt nigdy nie powoduje dołączenia.

## Z-023 — Reset hasła, limity prób i sesje (E2.5, techniczne + funkcjonalne, 2026-09-26)

- **Reset hasła:** link e-mail Fortify (token ważny 60 min, kolejny link dla tego samego konta najwcześniej po
  60 s — `config/auth.php`). Po resecie kończą się **wszystkie** sesje konta i token „zapamiętaj mnie”.
- **F — brak ujawniania kont:** formularz „Nie pamiętam hasła” odpowiada tym samym komunikatem, gdy konto
  istnieje, nie istnieje albo prośba jest zbyt częsta (`NeutralPasswordResetLinkResponse`).
- **F — limit logowania:** 5 prób na minutę dla pary e-mail + adres IP (Fortify, `FortifyServiceProvider`);
  potem 429. Limit prób kodu MFA — E2.6.
- **Sesje:** identyfikator sesji zmienia się po zalogowaniu (Fortify). Middleware `AuthenticateSession`
  kończy sesje ze starym skrótem hasła. „Wyloguj pozostałe urządzenia” (`DELETE /user/other-sessions`)
  wymaga hasła i zostawia bieżącą sesję; zmiana hasła przez właściciela (ekran w E2.8) także kończy
  pozostałe sesje.
- **F — czas sesji:** 120 min bezczynności (domyślne Laravel, `SESSION_LIFETIME`); ciasteczko `secure`
  na produkcji, `http_only`, `SameSite=Lax`.
- **Audyt:** każdy zapis konta wykonany przez framework ma powód (reset, rotacja tokenu, przeliczenie skrótu).
- **Weryfikacja:** 7 nowych przypadków.

## Z-024 — MFA (TOTP) i kody odzyskiwania (E2.6, techniczne + funkcjonalne, 2026-09-26)

- **Mechanizm:** Fortify TOTP (RFC 6238, aplikacja uwierzytelniająca, kod QR) z potwierdzeniem kodem przed
  aktywacją; 8 kodów odzyskiwania, każdy jednorazowy (po użyciu zastępowany nowym). Sekret i kody szyfrowane
  w bazie, klasa SECRET (w audycie tylko fakt zmiany). Ponowne użycie tego samego kodu TOTP jest odrzucane.
- **F — włączenie/wyłączenie:** wymaga potwierdzenia hasła w ciągu ostatnich 3 godzin (domyślne Laravel,
  `auth.password_timeout`). Każda zmiana jest audytowana z powodem.
- **F — limit:** 5 prób kodu na minutę dla logowania (Fortify `two-factor`); potem 429.
- **Wymuszenie dla administratorów:** middleware `mfa` (`RequireTwoFactor`) wpuszcza tylko konta
  z potwierdzonym MFA; pozostałe dostają 403 (audytowane jako odmowa). Do tras administracyjnych dołączane
  od E3, gdy powstaną role. Ekran włączania MFA — E2.8.
- **Weryfikacja:** 8 nowych przypadków.

## Z-025 — REPRESENTATION (E2.7, zaktualizowane 2026-09-27; funkcjonalne)

- **Uniwersalna relacja w czasie** (`representations`, wzorzec E1.5): reprezentant PERSON → reprezentowana
  PERSON. CORE **nie ma** typu „rodzic–dziecko” — to jedno z zastosowań reprezentacji.
- **Każdy okres zapisuje:** reprezentanta, reprezentowanego, zakres (`scopes`), sposób ustanowienia
  (`method`), źródło/podstawę (`basis`, np. numer dokumentu, decyzji lub akceptacji; klasa RESTRICTED),
  status, okres obowiązywania, ACTOR-a ustanawiającego (`established_by_*`) oraz historię zmian (audyt).
- **Sposoby ustanowienia:** `parties_acceptance` (akceptacja stron), `declaration` (oświadczenie),
  `role_decision` (decyzja uprawnionej roli), `document` (dokument), `additional_verification`.
  Konfiguracja (`identity.representation.methods`) wybiera dostępne sposoby, a `grantable_scopes` —
  dozwolony zakres. Wymagane potwierdzenia wykonuje przepływ wywołujący (ekran/rola, E3+) i wskazuje je
  w `basis`.
- **Reguły niekonfigurowalne:** nikt nie ustanawia ani nie rozszerza reprezentacji dla samego siebie
  (ACTOR ≠ reprezentant); zakres poza `grantable_scopes` jest odrzucany; wymagany co najmniej jeden zakres
  i podstawa; osoba nie reprezentuje samej siebie.
- **Zakresy:** `profile.view`, `profile.update`, `contacts.view`, `contacts.manage`, `registrations.manage`,
  `payments.manage`, `consents.manage` — reprezentacja daje tylko wymienione (A5 §1.4).
- **Sprawdzenie działania:** `ActOnBehalf` — aktywna reprezentacja z zakresem w chwili działania; odmowa
  `AccessDenied` (audyt E1.4); działanie audytowane jako `person.acted_on_behalf`: ACTOR = konto
  reprezentanta, SUBJECT = osoba reprezentowana (A5-03). Zmiana zakresu = nowy okres z własnym sposobem
  i podstawą; zakończenie odbiera dostęp.

## Z-026 — Ekrany konta (E2.8, funkcjonalne, 2026-09-26)

- **Ekrany** (`/account`, tylko zalogowani ze zweryfikowanym e-mailem): moje dane (PERSON), kontakty
  (dodanie, kod weryfikacyjny, usunięcie), bezpieczeństwo (MFA z kodem QR i kodami odzyskiwania, zmiana
  hasła, wylogowanie pozostałych urządzeń), osoby reprezentowane (lista aktywnych reprezentacji, podgląd
  i edycja w zakresie). Po zalogowaniu użytkownik trafia na `/account`.
- **F — cudze zasoby = 404:** cudzy kontakt albo osoba bez reprezentacji z potrzebnym zakresem odpowiada 404
  (nie ujawnia istnienia), a odmowa jest audytowana (E1.4).
- **Konto bez PERSON:** przy otwartej procedurze naprawczej (Z-022) widzi neutralny komunikat o dodatkowej
  weryfikacji z numerem zgłoszenia, bez danych innych osób; przed weryfikacją e-maila — prośbę o jej wykonanie.
  Ekrany danych osobowych są wtedy niedostępne.
- **Wygląd:** proste widoki Blade + Tailwind, etykiety pól, komunikaty błędów przy polach, bez JavaScriptu.
  Dopracowanie wyglądu i dostępności — przy ekranach operatora (E3+).
- **Weryfikacja:** 10 nowych przypadków HTTP (w tym odmowy dostępu).

## Z-027 — Pusta baza i ochrona przed skasowaniem (2026-09-27, techniczne)

- **Brak danych przykładowych:** `DatabaseSeeder` jest pusty; UDZIO nie ma seederów demonstracyjnych.
  Testy budują dane fabrykami i akcjami domenowymi. Po `php artisan migrate` tabele biznesowe są puste.
- **Ochrona:** `DestructiveCommandGuard` (wywoływany w `AppServiceProvider`) blokuje `migrate:fresh`,
  `migrate:refresh`, `migrate:reset`, `migrate:rollback` i `db:wipe` (mechanizm Laravel
  `DB::prohibitDestructiveCommands`), chyba że środowisko to `local` albo `testing` **i** każda chroniona
  baza (`mysql`, `audit`) ma nazwę `*_test` albo jest jawnie wskazana w `DB_ALLOW_DESTRUCTIVE_ON`.
  Produkcja jest chroniona zawsze, także z tą zmienną; lokalna baza deweloperska `udzio` — domyślnie.
- **Świadoma odbudowa bazy deweloperskiej:** `DB_ALLOW_DESTRUCTIVE_ON=udzio` tylko na czas jednego polecenia,
  np. `DB_ALLOW_DESTRUCTIVE_ON=udzio DB_USERNAME=root DB_PASSWORD=root php artisan migrate:fresh`.
- **Zdarzenie z 2026-09-26:** w E2.2 wykonano `migrate:fresh` na lokalnej bazie deweloperskiej `udzio`
  (kontener `udzio-mysql`, port 127.0.0.1:3307) — nie produkcyjnej ani współdzielonej. Polecenie przerwało
  się na migracji audytu (brak uprawnień do wyzwalaczy dla konta `udzio`), zostawiając bazę w połowie.
  2026-09-27 baza została utworzona od nowa i zmigrowana od zera (konto administratora zgodnie z Z-012).
