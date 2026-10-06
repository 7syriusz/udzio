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
- **F — hasło:** zastąpione przez Z-041 (E3.8e): co najmniej 15 znaków, lista haseł popularnych, kontrola wycieków
  (HIBP), Argon2id. Zmiana: `config/identity.php` (`passwords`) i `AppServiceProvider::boot` (`Password::defaults`).
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
  Uprawnienie do rozstrzygania — E3.9 (Z-042); ekran operatora — później.
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
- **Zasady dostępu przez reprezentację (dopisane w E3.7b, decyzja Jakuba 2026-10-05):**
  - reprezentacja pozwala działać w imieniu **konkretnej** osoby;
  - udostępnia tylko dane potrzebne do wykonywanej czynności — pola według zakresu czynności
    (`identity.representation.visible_fields`, `ActOnBehalf::visibleData`), nigdy pola klasy SPECIAL CATEGORY
    ani SECRET; reprezentacja nie odsłania automatycznie wszystkich danych osoby;
  - nie daje dostępu do organizacji, list członków ani danych innych osób (np. innych uczestników);
  - zakończenie reprezentacji odbiera bieżący dostęp (także przy podaniu daty z przeszłości), ale nie usuwa
    historii wcześniej wykonanych działań (`person.acted_on_behalf` zostaje w audycie).

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

## Z-028 — ORGANIZATION: cykl życia E3.1 (2026-09-27)

- A5 §2.1: organizacja jest odrębna od PERSON. E3.1 dodaje globalny rekord organizacji,
  bez automatycznego tworzenia osoby, konta, członkostwa czy roli; te powiązania należą do dalszych podetapów.
- Nazwa: po obcięciu brzegowych białych znaków musi zawierać widoczny znak, maksymalnie 255 znaków.
  Nazwy nie są unikalne — to publiczny ULID rozróżnia organizacje. `public_id`, `name`, `status` mają
  klasę INTERNAL: utworzenie organizacji nie oznacza publicznego udostępnienia jej danych.
- Status początkowy `active`; archiwizacja zmienia go na `archived` i zachowuje rekord oraz audyt.
  Przyjęte odwracalne założenie: akcja zmiany nazwy odmawia zmiany organizacji zarchiwizowanej;
  przywracanie nie jest operacją E3.1. Powtórna archiwizacja nie dopisuje pozornej zmiany.
- Zmiana nazwy i archiwizacja wymagają powodu. Akcje pobierają aktualny rekord pod blokadą MySQL
  w transakcji; audyt zapisuje się atomowo. Kontekst `audit_entries.organization_id` wskazuje publiczny
  ULID organizacji, natomiast `subject_id` pozostaje wewnętrznym ID zgodnie z `AuditsChanges`.
- `delete()` i trigger MySQL blokują usunięcie rekordu (także masowe DELETE). Nie jest to ochrona
  przed administracyjnym DROP/TRUNCATE; obowiązują ograniczenia uprawnień i Z-027.
  `down()` migracji odmawia usunięcia niepustej tabeli. Wycofanie aplikacji zachowuje tabelę i historię.
- Operacje są wewnętrznymi akcjami domenowymi. Nie udostępniono tras HTTP przed budową uprawnień
  E3.4–E3.7 i ekranów E3.10. Nie należy omijać akcji masowymi UPDATE: omijają audyt modelu.

## Z-029 — Hierarchia organizacji z historią (E3.2, 2026-09-27)

- A5 §2.1/2.3: jednostka jest tą samą ORGANIZATION; rodzic to relacja w czasie,
  nie branżowy typ ani pole nadpisywane przy przeniesieniu. `organization_parents` używa wzorca E1.5,
  z kluczem relacji `organization_id`: najwyżej jeden otwarty okres rodzica jednostki.
- Brak relacji oznacza korzeń. Przeniesienie zamyka poprzedni okres i otwiera nowy; odłączenie
  zamyka okres. Poddrzewo pozostaje przy przenoszonej jednostce. Okresy są półotwarte, z precyzją
  mikrosekund. Historia struktury nie oznacza historycznych nazw organizacji — te pozostają w audycie.
- E3.2 przyjmuje zmiany skuteczne teraz, z wymaganym powodem. Nie oferuje datowania wstecz ani planowania.
  Ponowienie tej samej relacji jest bezskutkowe. Przenoszona jednostka i docelowy rodzic muszą być aktywni.
  Archiwizacja E3.1 nie przenosi dzieci ani nie usuwa relacji; historyczny odczyt obejmuje archiwalne rekordy.
- Jedyny punkt modyfikacji struktury: `MoveOrganization`. Bezpośrednie `startPeriod`, `end` i masowe SQL
  nie są API struktury, bo omijałyby kontrolę cykli. Zmiany są atomowe z audytem. Odczyt przodków/ potomków
  jest iteracyjny, bez sztucznego limitu głębokości; potomkowie pobierani poziomami z eager loading.
- Prostota i bezpieczeństwo: wszystkie przeniesienia blokują najstarszy, nieusuwalny rekord organizacji
  jako wspólny mutex w MySQL. Następnie odczytują aktualne relacje przez blokujące odczyty (także po
  wcześniejszym odczycie w transakcji). Chroni to przed cyklami przy równoległych zmianach różnych jednostek.
  Koszt: serializacja zmian struktury całej platformy i możliwe krótkie oczekiwanie przy zmianie tego rekordu.
  Przy dużym ruchu można wymienić mechanizm blokady bez zmiany modelu relacji. Transakcje ponawiają deadlock.
- Hierarchia nie nadaje dostępu ani dziedziczenia ról — to E3.5–E3.7. Brak nowych tras i ekranów w E3.2.
- Migracja wyłącznie addytywna: istniejące organizacje pozostają korzeniami, nie tworzymy danych demo.
  Wycofanie kodu zachowuje tabelę historii; `down()` odmawia skasowania niepustej tabeli.
- Poprawka wzorca E1.5: warunki czasu przekazują do SQL pełne `Y-m-d H:i:s.u`, bo domyślne bindowanie
  obiektu daty przez połączenie Laravel obcinało mikrosekundy i psuło odczyt/zmiany na granicy okresów.

## Z-030 — Członkostwo w organizacji (E3.3, 2026-09-27)

- A5 §2.3: członkostwo to RELATION ROLE PERSON → ORGANIZATION (`memberships`) na wzorcu E1.5: funkcja,
  status (`active`/`suspended`), okres i historia. Klucz relacji: osoba + organizacja — osoba może należeć
  jednocześnie do wielu organizacji, ale w jednej ma najwyżej jeden otwarty okres.
- **Członkostwo nie jest rolą dostępową:** samo w sobie nie daje żadnych uprawnień (ACCESS ROLE — E3.4–E3.5).
- Operacje (każda z powodem, skuteczna „teraz”, bez datowania wstecz): `AdmitMember`, `ChangeMembership`
  (funkcja i/lub zawieszenie/wznowienie = nowy okres; ten sam stan = brak zmian), `TransferMembership`
  (zamyka okres w jednostce źródłowej i otwiera w docelowej z tą samą funkcją i statusem oraz odnośnikiem
  `transferred_from_id`), `EndMembership`. Ponowne przyjęcie po zakończeniu otwiera nowy okres.
- **F — funkcja:** tekst 1–100 znaków (bez listy słownikowej); słownik funkcji organizacji może dojść jako
  konfiguracja. Przyjęcie i przeniesienie tylko do aktywnej organizacji.
- Współbieżność: każdy zapis członkostwa blokuje najpierw osobę; równoległe przyjęcia lub przeniesienia tej
  samej osoby dają jeden wynik (testy dwóch procesów MySQL). Historia jest nieusuwalna (model i wyzwalacz
  MySQL), okresy nie są przepisywane. Klasa danych pól: INTERNAL.
- Brak tras HTTP i ekranów (E3.11). Migracja addytywna, bez danych przykładowych; `down()` odmawia
  usunięcia niepustej tabeli.

## Z-031 — PERMISSION i ACCESS ROLE (E3.4, 2026-09-27)

- A5 §2.4: **PERMISSION** to prawo do jednej operacji; katalog jest w kodzie (`Permission`), bo każdą wartość
  sprawdza kod. Nazwy opisują operacje (`members.manage`, `roles.assign`), nigdy branżę (A5-17). Kolejne
  etapy dopisują swoje uprawnienia (wydarzenia E4, zapisy E5, skanowanie E6, finanse E8…).
- **ACCESS ROLE** to dane organizacji: nazwa + zestaw uprawnień (`access_roles`). Każda organizacja definiuje
  własne role; nie ma ról globalnych ani zaszytych w kodzie. Nieznane uprawnienie i pusty zestaw są
  odrzucane; zestaw jest zapisywany bez duplikatów i posortowany.
- **F — nazwy ról:** 1–100 znaków, unikalne wśród aktywnych ról jednej organizacji (bez rozróżniania wielkości
  liter); inne organizacje mogą używać tych samych nazw.
- Zmiana nazwy lub uprawnień wymaga powodu, a audyt zapisuje zestaw przed i po. Rola jest **wycofywana,
  nie usuwana** (model i wyzwalacz MySQL); wycofana rola nic nie nadaje i nie może być zmieniana, a jej nazwę
  można użyć ponownie. Rola nie przechodzi do innej organizacji. Role definiuje tylko aktywna organizacja.
- Poza E3.4: przypisanie roli do konta i SCOPE (E3.5), decyzja o dostępie (E3.6), wymóg MFA dla roli (E3.8).
- Poprawka przy okazji: ochrony „rekord zamknięty” w `AccessRole` i `PersonLinkReview` (E2.10) porównują
  oryginalny status jako enum (wcześniej tekst — ochrona w modelu nie działała; akcje ją zastępowały).

## Z-032 — Przypisanie roli w zakresie (E3.5, 2026-09-27)

- A5 §2.4: przypisanie `role_assignments` = ACCOUNT → ACCESS ROLE w SCOPE (jednostka organizacji), jako
  relacja w czasie (E1.5): okres, status, historia. Klucz: konto + rola + jednostka zakresu.
- **Jawna polityka dziedziczenia** (`scope_inheritance`, wymagana przy każdym nadaniu, bez wartości domyślnej):
  `unit_only` — tylko wskazana jednostka; `unit_and_descendants` — jednostka i jednostki pod nią **według
  struktury obowiązującej w chwili sprawdzenia** (przeniesiona jednostka wypada z zakresu, historia zostaje).
  Nigdy nie ma dziedziczenia w górę ani do obcej organizacji.
- **F — gdzie można nadać rolę:** tylko w organizacji, która zdefiniowała rolę, albo w jednostkach pod nią.
  Rola musi być aktywna, jednostka zakresu — aktywna.
- **F — wygaśnięcie:** nadanie może mieć termin (musi być w przyszłości); po nim przypisanie jest nieaktywne.
  Odwołanie kończy przypisanie „teraz”, także takie, które miało wygasnąć później. Każda zmiana z powodem.
- Rozszerzenie wzorca E1.5: `shortenScheduledEnd` — koniec okresu zaplanowany w przyszłości można tylko
  przyspieszyć, nie wcześniej niż „teraz”; to, co już się wydarzyło, pozostaje nienaruszone.
- Samo przypisanie nie jest decyzją o dostępie: sprawdzenie uprawnienie ∧ SCOPE ∧ aktywne przypisanie ∧
  aktywna rola powstaje w E3.6. Kto może nadawać role (`roles.assign`) — przez przepływ wywołujący (E3.6+).
  Historia nieusuwalna (model i wyzwalacz MySQL). Brak tras HTTP i ekranów (E3.11).

## Z-033 — Decyzja o dostępie i centralna kontrola ról (E3.6, 2026-09-27)

- **Jedno miejsce decyzji:** `AccessDecider::decide(konto, uprawnienie, jednostka docelowa, chwila)` na podstawie:
  ACCOUNT, aktywnego (w tej chwili) przypisania roli, wersji roli z tej chwili (uprawnienie w zestawie, rola
  aktywna), aktywności organizacji-właściciela roli, jednostki zakresu i jednostki docelowej, oraz struktury
  obowiązującej w tej chwili (`unit_only` / `unit_and_descendants`, E3.5 — zakres dynamiczny).
  Wynik jest jednoznaczny: `allowed` albo `denied`, zawsze z podstawą.
- **Podstawa decyzji:** przy zezwoleniu — przypisanie, rola i jej wersja, uprawnienie, jednostka zakresu,
  polityka dziedziczenia i ścieżka struktury (identyfikatory ówczesnych okresów rodzica od jednostki docelowej
  do jednostki zakresu); przy odmowie — przyczyna (`actor_without_account`, `target_inactive`,
  `no_active_assignment`, `no_matching_assignment`) i wynik oceny każdego rozważonego przypisania
  (`role_inactive`, `permission_missing`, `role_organization_inactive`, `scope_inactive`, `scope_not_covering`).
- **Odtwarzalność:** przypisania i struktura są okresami (E1.5), role mają wersje (E1.6 — wersja publikowana
  przy każdej zmianie), archiwizacja organizacji ma chwilę (`archived_at`). Decyzję z przeszłości odtwarza się,
  wywołując `decide` dla tamtej chwili. Dodatkowo `authorize` zapisuje każdą decyzję dla operacji chronionej:
  `access.granted` (w transakcji operacji) albo `access.denied` z pełną podstawą (niezależne połączenie, E1.4).
  Decyzja jest ustalana w całości przed zapisem — jeden fakt na decyzję. Błąd zapisu odmowy nie zmienia odmowy.
- **Centralna kontrola ról:** `CreateAccessRole`, `UpdateAccessRole`, `RetireAccessRole` (`roles.manage`)
  oraz `AssignRole`, `RevokeRoleAssignment` (`roles.assign`) same wywołują decydenta dla bieżącego ACTOR-a —
  każda droga (ekran, API, komenda, automat) przechodzi przez ten sam mechanizm; nie zależy to od kontrolera.
- **Reguły nadawania ról:** zastąpione w E3.6a katalogiem nadawania ról (Z-035). Reguła „przekazujesz tylko
  uprawnienia, które sam masz” została **usunięta**.
- **Proces bez konta:** odmowa, chyba że działa w jawnym, audytowanym trybie `SystemAuthority` (z powodem;
  tylko ACTOR typu `process`, nigdy żądanie HTTP) — przewidziany dla instalacji pierwszego administratora (E3.8).
- **Rozdzielenie ról:** decyzja korzysta wyłącznie z ACCESS ROLE przypisanych do ACCOUNT. RELATION ROLE
  (członkostwo, reprezentacja) opisują znaczenie PERSON w kontekście i nie dają dostępu.
- **Laravel Gate:** `Gate::allows('members.manage', $organizacja)` pyta tego samego decydenta (do ekranów);
  operacje domenowe używają `authorize`, które zapisuje decyzję.
- Poprawka: decydent czyta aktualny stan jednostki z bazy (nie z przekazanego obiektu).

## Z-034 — Izolacja danych (E3.7, 2026-09-27)

- A5-01 i A5 §1.1: tożsamość PERSON jest globalna, ale **nie daje globalnej widoczności**. `DataVisibility`
  ogranicza listy i odczyty do jednostek, w których konto ma dane uprawnienie. Zbiór jednostek liczy
  `AccessDecider::grantedOrganizationIds` według tych samych reguł co pojedyncza decyzja E3.6 (test zgodności).
- **Organizacje:** widoczne tylko jednostki z `organization.view` (zgodnie z zakresem i dziedziczeniem).
- **Członkostwa:** widoczne tylko okresy (bieżące i przeszłe) w jednostkach z `members.view`. Członkostwo tej
  samej osoby w obcej organizacji (np. funkcja) pozostaje niewidoczne.
- **F — kogo widzi konto:** własną PERSON; osoby, które reprezentuje z zakresem `profile.view`; osoby mające
  (teraz lub w przeszłości) członkostwo w jednostce z `members.view`. Historyczne członkostwo pozostawiono,
  aby dało się czytać historię jednostki — do przeglądu, gdyby miało wygasać po czasie (retencja Z-017).
- Reprezentacja nie daje wglądu w dane organizacji; rola dostępowa nie daje wglądu w cudze reprezentacje.
- **Odczyt spoza zakresu:** odpowiedź 404 (bez ujawniania istnienia) i odmowa w audycie z uzasadnieniem
  `not_visible` — zapisana raz, także przy wywołaniu z HTTP. Zarchiwizowana jednostka wypada z widoczności.
- Kolejne moduły (wydarzenia, zapisy, finanse…) filtrują swoje zapytania przez `grantedOrganizationIds` z własnym
  uprawnieniem, zamiast własnej logiki zakresu.

## Z-035 — Katalog nadawania ról (E3.6a, 2026-09-28, decyzja Krzysztofa)

- **Rozdzielenie:** uprawnienie do wykonywania czynności (np. `audit.view`) to co innego niż uprawnienie do
  nadawania i odwoływania ról. Administrator może nadać rolę z uprawnieniami, których sam nie ma
  (np. pracownik kadr nadaje rolę „księgowy”).
- **Katalog w roli zarządczej:** rola z `roles.assign` ma `grant_rules` — listę reguł:
  - `role` — którą rolę wolno nadawać i odwoływać (tylko aktywne role tej organizacji lub jej jednostek);
  - `include_descendants` — czy także w jednostkach pod jednostką zakresu administratora;
  - `max_days` — maksymalny okres nadania w dniach (wtedy termin jest obowiązkowy);
  - `requires_approval` — czy nadanie czeka na zatwierdzenie.
  Katalog jest częścią wersjonowanej definicji roli (E1.6), więc decyzję z przeszłości można odtworzyć.
- **Gdzie wolno działać:** wyznacza to przypisanie roli zarządczej administratora (jednostka i jej polityka
  `unit_only` / `unit_and_descendants`) oraz reguła `include_descendants`.
- **Zatwierdzanie:** nadanie z regułą `requires_approval` tworzy przypisanie `pending` (nic nie daje).
  Zatwierdza inne konto uprawnione do nadania tej roli w tym zakresie — nigdy wnioskujący ani obdarowany.
  Odrzucenie = odwołanie oczekującego przypisania. Okres aktywny zaczyna się w chwili zatwierdzenia.
- **Zabezpieczenia (niekonfigurowalne):** zakaz samonadania; zakaz zatwierdzenia własnego wniosku; zakaz roli
  spoza katalogu; zakaz działania poza zarządzanym zakresem; zakaz pośredniego zwiększania własnych
  uprawnień: (a) nie można nadać komuś roli z większą władzą nadawania niż własna (`roles.assign` /
  `roles.manage`, których się nie ma, albo pozycje katalogu spoza własnego katalogu), (b) nie można zmieniać
  definicji roli, którą samemu się ma. Znane ograniczenie: zmowa dwóch administratorów (nadają sobie nawzajem
  role z katalogu) — łagodzi ją reguła `requires_approval`.
- **Jedna decyzja na operację:** każda operacja chroniona (nadanie, zatwierdzenie, odwołanie, definicja roli)
  ma jeden identyfikator korelacji (`OperationCorrelation`), wspólny dla wpisu decyzji i wpisów zmian.
  Powtórzone sprawdzenia w tej samej operacji nie tworzą kolejnych wpisów; sprawdzenia przez Gate (ekrany)
  nie zapisują decyzji wcale.
- **Komunikat dla użytkownika:** ogólne 403 „Nie masz uprawnień do wykonania tej czynności.” (klucz
  `access.unauthorized`, od E3.6b; bez nazw ról, przypisań, zakresów
  i przyczyny); pełna podstawa odmowy jest tylko w audycie.

### Z-034a — Izolacja danych po uwagach do E3.6 (E3.7a, 2026-09-28)

- **Dane ról oddzielnie od danych operacyjnych** (rozróżnienie z Z-035): przypisania ról widzi konto tylko
  dla ról ze swojego katalogu nadawania i tylko w jednostkach, w których może je nadawać, oraz własne
  przypisania. `members.view` ani `roles.manage` nie dają wglądu w cudze przypisania. Definicje ról widzi:
  posiadacz `roles.manage` (wszystkie role tych jednostek), posiadacz katalogu (role z katalogu) i posiadacz roli.
  Zbiór liczy `AccessDecider::roleGrantCatalog` według tych samych reguł co decyzja o nadaniu (test zgodności).
- **Przypisania oczekujące** (E3.6a) nie dają widoczności; zatwierdzający widzi je jako wnioski.
- **Odmowy odczytu przez centralny mechanizm:** `DataVisibility` zapisuje odmowę przez
  `AccessDecider::recordDenial` — jeden wpis na operację i przedmiot, z korelacją operacji. Odpowiedź dla
  użytkownika: ogólne 404, bez nazw ról, jednostek ani przyczyny.
- **Odtwarzalność:** każda metoda `DataVisibility` przyjmuje chwilę — widoczność z przeszłości liczona jest
  według ówczesnych przypisań, wersji ról i struktury.

### Rozstrzygnięcia (Jakub, 2026-10-05; wdrożone w E3.7b — szczegóły i nazwy techniczne: Z-037)

1. **Wybór kandydata do roli:** prawo nadawania roli daje ograniczone wyszukiwanie kandydata (imię i nazwisko,
   częściowo ukryty e-mail, czy konto aktywne, czy osoba jest we wskazanej jednostce) — nie pełne `members.view`.
   Pełny e-mail tylko z osobnym uprawnieniem. Wyszukiwanie nie przegląda całej bazy kont i nie potwierdza,
   czy dowolny adres ma konto w UDZIO.
2. **Role spoza katalogu:** niewidoczne dla zarządzającego rolami; wyjątek — osobne uprawnienie do kontroli
   lub audytu ról.
3. **Byli członkowie:** tylko z osobnym uprawnieniem do historii członkostwa, przez okres wynikający z polityki
   przechowywania (do ustalenia). Bez automatycznego usuwania; bezterminowe przechowywanie **nie** jest zasadą
   docelową.
4. **Reprezentacja:** dostęp do danych reprezentowanej osoby w zakresie koniecznym do czynności, zależnie od
   rodzaju czynności i klasy danych; bez dostępu do organizacji i jej członków.
5. **Zarchiwizowana jednostka:** jej historia pozostaje dostępna administratorowi jednostki nadrzędnej, jeśli
   ma aktualne uprawnienie do historii, jednostka należała do jego zakresu, a dane nie zostały usunięte zgodnie
   z polityką przechowywania. Archiwizacja nie usuwa historii ani audytu; przeniesienie nie zmienia faktu,
   gdzie jednostka była w danym momencie.
6. **Audyt odczytów:** obowiązkowo odmowy, odczyty danych szczególnie chronionych, eksporty, raporty z danymi
   osobowymi, masowe odczyty, dostęp administracyjny i awaryjny, odczyty przez `SystemAuthority`; zwykłe
   dozwolone wyświetlenie standardowej listy — bez wpisu.
7. **`SystemAuthority`:** tylko w określonym celu i zakresie, nigdy ogólny dostęp. Eksport zlecony przez
   użytkownika działa w jego aktualnych uprawnieniach; raport cykliczny przy każdym wykonaniu sprawdza je
   ponownie i bez nich nie powstaje; proces techniczny nie rozszerza zakresu ponad zlecającego; samodzielny
   proces systemowy wymaga zdefiniowanego celu, zakresu, podstawy i pełnego audytu. Szczegóły raportów — E10.
- **Historia a obecne uprawnienia:** obliczenie widoczności dla dowolnej chwili służy odtwarzaniu i audytowi,
  nie omijaniu uprawnień. Najpierw sprawdzane jest dzisiejsze prawo do danego rodzaju historii, dopiero potem
  stan wybranej chwili; dawna rola nie daje dziś dostępu.
- **Wniosek oczekujący:** nie daje przyszłemu posiadaczowi żadnych uprawnień; zatwierdzający widzi tylko:
  kogo dotyczy, jakiej roli, zakres i okres, kto i kiedy złożył, powód — bez dostępu do innych danych osoby.

## Z-035 — Katalog nadawania ról (E3.6a, 2026-09-28, decyzja Krzysztofa)

- **Rozdzielenie:** uprawnienie do wykonywania czynności (np. `audit.view`) to co innego niż uprawnienie do
  nadawania i odwoływania ról. Administrator może nadać rolę z uprawnieniami, których sam nie ma
  (np. pracownik kadr nadaje rolę „księgowy”).
- **Katalog w roli zarządczej:** rola z `roles.assign` ma `grant_rules` — listę reguł:
  - `role` — którą rolę wolno nadawać i odwoływać (tylko aktywne role tej organizacji lub jej jednostek);
  - `include_descendants` — czy także w jednostkach pod jednostką zakresu administratora;
  - `max_days` — maksymalny okres nadania w dniach (wtedy termin jest obowiązkowy);
  - `requires_approval` — czy nadanie czeka na zatwierdzenie.
  Katalog jest częścią wersjonowanej definicji roli (E1.6), więc decyzję z przeszłości można odtworzyć.
- **Gdzie wolno działać:** wyznacza to przypisanie roli zarządczej administratora (jednostka i jej polityka
  `unit_only` / `unit_and_descendants`) oraz reguła `include_descendants`.
- **Zatwierdzanie:** nadanie z regułą `requires_approval` tworzy przypisanie `pending` (nic nie daje).
  Zatwierdza inne konto uprawnione do nadania tej roli w tym zakresie — nigdy wnioskujący ani obdarowany.
  Odrzucenie = odwołanie oczekującego przypisania. Okres aktywny zaczyna się w chwili zatwierdzenia.
- **Zabezpieczenia (niekonfigurowalne):** zakaz samonadania; zakaz zatwierdzenia własnego wniosku; zakaz roli
  spoza katalogu; zakaz działania poza zarządzanym zakresem; zakaz pośredniego zwiększania własnych
  uprawnień: (a) nie można nadać komuś roli z większą władzą nadawania niż własna (`roles.assign` /
  `roles.manage`, których się nie ma, albo pozycje katalogu spoza własnego katalogu), (b) nie można zmieniać
  definicji roli, którą samemu się ma. Znane ograniczenie: zmowa dwóch administratorów (nadają sobie nawzajem
  role z katalogu) — łagodzi ją reguła `requires_approval`.
- **Jedna decyzja na operację:** każda operacja chroniona (nadanie, zatwierdzenie, odwołanie, definicja roli)
  ma jeden identyfikator korelacji (`OperationCorrelation`), wspólny dla wpisu decyzji i wpisów zmian.
  Powtórzone sprawdzenia w tej samej operacji nie tworzą kolejnych wpisów; sprawdzenia przez Gate (ekrany)
  nie zapisują decyzji wcale.
- **Komunikat dla użytkownika:** ogólne 403 „Nie masz uprawnień do wykonania tej czynności.” (klucz
  `access.unauthorized`, od E3.6b; bez nazw ról, przypisań, zakresów
  i przyczyny); pełna podstawa odmowy jest tylko w audycie.

### Z-034a — Izolacja danych po uwagach do E3.6 (E3.7a, 2026-09-28)

- **Dane ról oddzielnie od danych operacyjnych** (rozróżnienie z Z-035): przypisania ról widzi konto tylko
  dla ról ze swojego katalogu nadawania i tylko w jednostkach, w których może je nadawać, oraz własne
  przypisania. `members.view` ani `roles.manage` nie dają wglądu w cudze przypisania. Definicje ról widzi:
  posiadacz `roles.manage` (wszystkie role tych jednostek), posiadacz katalogu (role z katalogu) i posiadacz roli.
  Zbiór liczy `AccessDecider::roleGrantCatalog` według tych samych reguł co decyzja o nadaniu (test zgodności).
- **Przypisania oczekujące** (E3.6a) nie dają widoczności; zatwierdzający widzi je jako wnioski.
- **Odmowy odczytu przez centralny mechanizm:** `DataVisibility` zapisuje odmowę przez
  `AccessDecider::recordDenial` — jeden wpis na operację i przedmiot, z korelacją operacji. Odpowiedź dla
  użytkownika: ogólne 404, bez nazw ról, jednostek ani przyczyny.
- **Odtwarzalność:** każda metoda `DataVisibility` przyjmuje chwilę — widoczność z przeszłości liczona jest
  według ówczesnych przypisań, wersji ról i struktury.

### Wątpliwości do rozstrzygnięcia (E3.7, do Jakuba)

1. Czy prawo nadawania ról (`roles.assign`) ma dawać minimalny wgląd w kandydatów (konta/osoby w zarządzanym
   zakresie), aby dało się wybrać, komu nadać rolę? Obecnie **nie** — potrzebne jest osobne `members.view`.
2. Czy zarządzający rolami ma widzieć, kto w jego zakresie ma role **spoza** jego katalogu (np. kto jest
   administratorem)? Obecnie **nie** (uwaga 7 do E3.6).
3. Czy administrator ma widzieć osoby, które **były** członkami jego jednostek (historia)? Obecnie **tak**,
   bez limitu czasu — ewentualny limit wiązałby się z retencją (Z-017).
4. Czy widoczność osób reprezentowanych (`profile.view`) i własnej osoby jest zgodna z zasadą „członkostwo
   i reprezentacja same nie nadają dostępu”? Przyjęto, że reprezentacja daje wgląd **tylko** w dane osoby
   reprezentowanej (zakres z E2.7), nigdy w dane organizacji.
5. Czy po archiwizacji jednostki jej historia (członkostwa, przypisania) ma pozostać widoczna dla administratora
   jednostki nadrzędnej? Obecnie jednostka zarchiwizowana **wypada** z widoczności razem z historią.
6. Czy udane odczyty list mają być audytowane? Obecnie **nie** (tak jak sprawdzenia Gate); audytowane są
   odmowy i odczyty pól klas SPECIAL CATEGORY/SECRET (E1.7).
7. Czy procesy techniczne (raporty, eksporty w kolejce) mają mieć widoczność przez `SystemAuthority`?
   Obecnie `DataVisibility` wymaga konta; zostanie to ustalone przy raportach (E10).

## Z-036 — Języki interfejsu i komunikatów systemowych (E3.6b, 2026-10-05, decyzja Jakuba)

- **Polski** jest językiem domyślnym i awaryjnym (`config/localization.php`, `app.locale`,
  `app.fallback_locale`). Kompletna jest tylko wersja polska; brak klucza w innym języku = tekst polski.
- **Klucze tłumaczeń zamiast tekstów w kodzie.** Każdy komunikat dla użytkownika (walidacja, komunikaty po zapisie,
  błędy HTTP, odmowa dostępu, ekrany, nazwy uprawnień i kanałów, treść e-maili) korzysta z klucza w
  `lang/pl/*.php`; teksty Laravel i Fortify są w `lang/pl.json`, `validation.php`, `auth.php`, `passwords.php`.
  Pilnują tego testy w `tests/Unit/ArchitectureTest.php`.
- **Kody techniczne nie są tłumaczone i nie trafiają do użytkownika:** przyczyny odmów (`role_not_in_catalog`),
  nazwy uprawnień (`members.view`), komunikaty wyjątków domenowych (np. `ValidityConflict`) zostają po
  angielsku jako informacje dla logów i audytu; użytkownik widzi przetłumaczony komunikat ogólny
  (`App\Http\Exceptions\UserFacingMessage`). Danych wpisanych przez użytkowników się nie tłumaczy.
- **Wybór języka — jedno miejsce (`LocaleResolver`, stosowany przez middleware `SetLocale`):**
  1. jawny wybór w bieżącej sesji (także wejście publiczne bez konta, `POST /locale`);
  2. język zapisany na koncie (`users.locale`, ustawiany przy jawnym wyborze zalogowanego);
  3. język domyślny kontekstu (organizacja, strona publiczna) — parametr przygotowany, użyty, gdy kontekst
     dostanie ustawienie języka;
  4. polski.
  Wartość spoza listy `localization.supported` jest pomijana. Języka nie wybiera się automatycznie z kraju,
  adresu IP ani danych organizacji; nagłówka przeglądarki (`Accept-Language`) też nie używamy (założenie,
  odwracalne przez dodanie kroku w `LocaleResolver`).
- **Trzy niezależne obszary językowe — osobne mechanizmy, nie jeden:**
  1. **interfejs i komunikaty systemowe** — `LocaleResolver` + `SetLocale`, teksty w `lang/`, lista
     `localization.supported` (wdrożone w E3.6b);
  2. **język e-maili, SMS-ów i powiadomień** — `RecipientLocale` (`User::preferredLocale`), ustalany dla
     odbiorcy wyłącznie z języka zapisanego na jego koncie, bez sesji i żądania, z własną listą
     `localization.message_languages` (język wiadomości dopisuje się, gdy istnieją jego szablony); teksty systemowe
     w `lang/pl/notifications.php`, szablony komunikacji organizatora — E10;
  3. **wersje językowe treści organizatora** (opisy, regulaminy, formularze, nazwy wydarzeń) — dane w bazie,
     nie pliki `lang/` ani żaden z powyższych mechanizmów; w etapach tych treści.
- **Konto i sesja:** konto przechowuje wybrany język (`users.locale`); jawny wybór niezalogowanego jest
  zapisywany w sesji (`POST /locale`) i stosowany przy wejściu publicznym bez konta przez `LocaleResolver`.
- **Konfiguracja wersjonowana:** domyślne wartości w `config/app.php` (`locale`, `fallback_locale` = `pl`,
  `faker_locale` = `pl_PL`) i `config/localization.php`; te same wartości w `.env.example`,
  `.env.production.example` i `phpunit.xml`. Brak zmiennych `APP_LOCALE`/`APP_FALLBACK_LOCALE` w środowisku
  nie przełącza na angielski (test).
- **Dodanie języka:** pliki `lang/<kod>/`, `lang/<kod>.json` i wpis w `localization.supported` (dla wiadomości —
  szablony i wpis w `localization.message_languages`); bez zmian logiki biznesowej (test).

## Z-037 — Rozstrzygnięcia Z-034a we wdrożeniu (E3.7b, 2026-10-05)

- **Nowe uprawnienia (zamiast jednego szerokiego `members.view`):**

  | Nazwa techniczna | Co daje |
  |---|---|
  | `members.history.view` | byli członkowie i zakończone okresy członkostwa (`DataVisibility::membershipHistory`), także zarchiwizowanych jednostek |
  | `structure.history.view` | historia struktury i zarchiwizowanych jednostek, widoczność dla daty z przeszłości |
  | `roles.audit.view` | kontrola ról: wszystkie przypisania w zakresie, także spoza katalogu nadawania; historia ról |
  | `people.contacts.view` | pełne dane kontaktowe (np. niezamaskowany e-mail kandydata) |
  | `people.protected.view` | odczyt danych szczególnie chronionych osób z zakresu (zawsze audytowany) |
  | `data.export` | eksporty i masowe odczyty danych zakresu (zawsze audytowane) |

  Wybór kandydata do roli **nie** ma osobnego uprawnienia: wynika z `roles.assign` i katalogu nadawania
  (tylko rola z katalogu, tylko jednostka, w której wolno ją nadać) i nie daje `members.view`.
  `members.view` pokazuje od E3.7b tylko **obecnych** członków.
- **Wybór kandydata (`RoleCandidateSearch`):** obszar = obecni członkowie jednostek, w których zarządzający
  może nadać daną rolę; wynik (`RoleCandidate`): identyfikator osoby, imię i nazwisko, e-mail zamaskowany
  (`a•••@e•••.pl`, pełny tylko z `people.contacts.view`), czy konto aktywne (zweryfikowany e-mail), czy osoba
  jest we wskazanej jednostce (lub poniżej). Fraza min. 3 znaki; e-mail tylko dokładne dopasowanie w obszarze
  (ta sama odpowiedź dla konta spoza zakresu i nieistniejącego); imię/nazwisko — początek słowa, bez znaków
  wieloznacznych; maks. 10 wyników; limit 20 wyszukiwań na minutę na konto (`config/organization.php`).
  Rola spoza katalogu lub jednostka spoza zakresu → odmowa z audytem.
- **Prawo do historii a odtworzenie decyzji:** `AccessDecider::decide(..., $chwila)` to techniczne odtworzenie
  decyzji (audyt, wyjaśnienia). Prawo użytkownika do przeglądania historii liczy
  `AccessDecider::historyOrganizationIds` wyłącznie z przypisań aktywnych **dziś**; dla daty z przeszłości obejmuje
  jednostki, które w tamtej chwili były pod jednostką zakresu (przeniesienie nie zmienia historii), a dla
  „teraz” — dodatkowo jednostki zarchiwizowane, które w chwili archiwizacji należały do zakresu.
  `DataVisibility` dla daty z przeszłości najpierw sprawdza to prawo (brak → odmowa 403 z audytem,
  przyczyna `history_not_permitted`), dopiero potem na podstawie struktury z tamtej chwili ustala, które jednostki
  były w zakresie dzisiejszego przypisania. Role posiadane w tamtej chwili nie mają znaczenia: rola z przeszłości
  bez dzisiejszego prawa daje odmowę, a prawo nadane dziś obejmuje także przeszłość (poprawka E3.7c — wcześniej
  wynik był dodatkowo zawężany do ról z tamtej chwili).
- **Przechowywanie historii członkostwa:** `organization.history.visible_days` = `null` (bez limitu) jako
  ustawienie **tymczasowe** do czasu polityki przechowywania (Z-017); dane nie są usuwane automatycznie.
- **Wniosek oczekujący:** `DataVisibility::pendingRoleRequests` zwraca tylko wnioski, które konto może
  zatwierdzić (bez własnych), jako `RoleRequestSummary`: kogo (imię i nazwisko, zamaskowany e-mail), rola,
  jednostka, polityka dziedziczenia, termin, kto wnioskował, kiedy i z jakim powodem.
- **Audyt odczytów (`RecordDataAccess`, połączenie `audit`, tylko metadane):** `data.exported`
  (`DataVisibility::export`, wymaga `data.export`), `data.bulk_read` (`DataVisibility::fetch` powyżej
  `organization.reads.bulk_threshold` = 200 wierszy), `data.read` (dane szczególnie chronione,
  `DataVisibility::readProtected`), `data.read_by_system` (`DataVisibility::systemMemberships`),
  `system_authority.entered`; rodzaje `report.downloaded` i `access.privileged` są przygotowane dla raportów
  (E10) i dostępu awaryjnego (gdy powstanie). Zwykłe listy — bez wpisu.
- **`SystemAuthority` z celem (`SystemPurpose`):** cel, podstawa, lista uprawnień i jednostki zakresu (z
  jednostkami podrzędnymi) są obowiązkowe; decyzja poza nimi = odmowa (`system_purpose_permission_missing`,
  `system_purpose_scope_not_covering`). Proces zlecony przez konto (`onBehalfOf`) wymaga przy każdej decyzji
  aktualnego uprawnienia tego konta (`ordering_account_not_permitted`). Wejście w tryb systemowy jest
  audytowane. Konto (żądanie HTTP) nie może użyć `SystemAuthority`. W testach pomocniczy cel obejmujący
  wszystko istnieje tylko w `tests/Fixtures`.


## Z-038 — MFA dla dostępu uprzywilejowanego (E3.8a, 2026-10-06)

- **Warunki konta przed działaniem przypisania roli** (`PrivilegedAccessPolicy`, sprawdzane w `AccessDecider` dla każdej
  decyzji, listy jednostek i prawa do historii):
  - każde uprawnienie wynikające z **przypisanej roli** (organizacji lub platformy) wymaga potwierdzonego adresu
    e-mail konta (przyczyna odmowy `email_unverified`). Nie dotyczy to publicznych czynności uczestnika bez roli
    (np. zapisu na wydarzenie) — ich zasady określi scenariusz (doprecyzowanie Z-040 pkt 4);
  - MFA wymaga rola, która ma je w swojej **polityce bezpieczeństwa** (`access_roles.requires_mfa`, część wersji roli,
    zmiana audytowana przed/po) **albo** zawiera uprawnienie uprzywilejowane. Nazwa roli nie ma znaczenia.
  - Uprawnienia uprzywilejowane (konfiguracja `organization.privileged_access.permissions`): `roles.manage`,
    `roles.assign`, `roles.audit.view`, `audit.view`, `people.protected.view`, `data.export`. Lista jest konfiguracją
    — do przeglądu, gdy dojdą uprawnienia finansowe (E8) i eksporty (E10).
- **Przypisanie może istnieć** bez MFA; nieaktywne są wtedy **wszystkie** uprawnienia tej roli (nie tylko
  uprzywilejowane) — rola jest jednostką polityki. Uprawnienia zaczynają działać po potwierdzeniu MFA kodem
  i przestają po wyłączeniu MFA przez posiadacza.
- **Odmowa:** audyt `access.denied` z przyczyną `mfa_required` / `email_unverified`; użytkownik dostaje polski komunikat
  (`access.mfa_required` z odnośnikiem do ustawień bezpieczeństwa, `access.email_unverified`), bez nazw ról,
  uprawnień ani kodów technicznych. Inne przyczyny nadal dają ogólny komunikat.
- **Odtwarzanie decyzji z przeszłości:** MFA liczy się od `two_factor_confirmed_at`. Stan MFA nie ma historii, więc po
  wyłączeniu lub resecie MFA odtworzona decyzja sprzed tej chwili wyjdzie jako odmowa — pełną podstawę dawnej decyzji
  zawiera wpis `access.granted` w audycie.
- **Kody odzyskiwania** (uzupełnienie Z-024): szyfrowane w bazie, jednorazowe, ponowne wygenerowanie (po potwierdzeniu
  hasła) unieważnia cały poprzedni zestaw; użycie i wygenerowanie są audytowane jako zmiana konta z powodem, wartości
  `[REDACTED]`.
- **Brak obejścia MFA:** reset hasła (odzyskanie konta) i zmiana hasła nie zmieniają MFA, a logowanie nadal wymaga
  kodu; adresu e-mail konta nie da się zmienić (Fortify `updateProfileInformation` wyłączone, ekran konta zmienia tylko
  dane PERSON); drugie konto na ten sam adres nie powstaje. Wyłączenie MFA i nowe kody wymagają potwierdzenia hasła.

## Z-039 — Administrator platformy, instalacja i reset MFA (E3.8b, 2026-10-06)

- **Administrator platformy ≠ administrator organizacji.** Uprawnienia platformy (`PlatformPermission`:
  `platform.administrators.manage`, `platform.mfa.reset`) dają wyłącznie przypisania ról platformy
  (`platform_role_assignments`, relacja w czasie E1.5); decyduje `PlatformAccess`, oddzielnie od `AccessDecider`.
  Żadna rola organizacji (także rola ze wszystkimi uprawnieniami organizacji ani założyciel organizacji) nie daje
  uprawnień platformy, a rola platformy nie daje uprawnień w organizacjach. Role platformy są zdefiniowane
  w `config/platform.php` (na razie jedna: `administrator`).
- **Każde uprawnienie platformy jest uprzywilejowane:** działa tylko z potwierdzonym e-mailem i potwierdzonym MFA
  (`PrivilegedAccessPolicy`, Z-038), niezależnie od nazwy i definicji roli. Odmowy jak w Z-038 (audyt, polski
  komunikat).
- **Pierwszy administrator — jednorazowa instalacja:** `php artisan platform:install-administrator <e-mail>
  --given-name= --family-name=`. Komenda nie przyjmuje hasła; konto dostaje losowe, nieznane nikomu hasło,
  a na adres trafiają link do ustawienia hasła i link potwierdzający e-mail. Rola platformy działa dopiero po
  potwierdzeniu e-maila i włączeniu MFA. Instalacja **nie** tworzy organizacji (organizacje powstaną przez
  administratora platformy — E3.10) ani żadnych danych przykładowych; seeder pozostaje pusty.
  - Adres, pod którym istnieje już konto, jest odrzucany — istniejące konto nigdy nie zostaje awansowane
    (zabezpieczenie przed zajęciem adresu przez inną osobę przed instalacją).
  - Jednorazowość: tabela `platform_installations` ma najwyżej jeden rekord (id = 1, wyzwalacze blokują inny
    identyfikator i usunięcie). Rekord jest zapisywany jako pierwszy w transakcji instalacji, więc równoczesne
    uruchomienia czekają na siebie; przegrany jest odrzucany (`installation_completed`), a jego transakcja nie
    zostawia konta. Ponowne użycie po zakończeniu — odmowa z audytem.
  - Audyt: `system_authority.entered` (cel `platform.install`), `access.granted`, `account.created`,
    `platform_role_assignment.created`, `platform.installed` (aktor: proces komendy).
- **`SystemAuthority` przy instalacji:** cel `platform.install` z jedynym uprawnieniem `platform.install`, bez
  zakresu organizacji (`SystemPurpose` dopuszcza pusty zakres tylko dla celu z samymi uprawnieniami platformy),
  aktywny tylko na czas jednej operacji (`run`). `PlatformAccess` uznaje proces systemowy wyłącznie dla
  `platform.install` i tylko przed zakończeniem instalacji; każde inne uprawnienie platformy dla procesu =
  odmowa `system_authority_not_for_platform`. Uprawnienia `platform.install` nie ma żadna rola.
- **Reset MFA** (`ResetAccountMfa`): wymaga `platform.mfa.reset` (wraz z MFA wykonującego), opisu potwierdzenia
  tożsamości posiadacza konta (obowiązkowy, zapisywany w audycie) i powodu; nikt nie resetuje własnego MFA swoją
  rolą (`own_account`) — ta sama zasada dotyczy nadania sobie roli platformy (`GrantPlatformRole`). Skutek:
  usunięcie sekretu TOTP i wszystkich kodów odzyskiwania, zakończenie wszystkich sesji i unieważnienie
  „zapamiętaj mnie”; audyt `account.mfa_reset` (wykonujący, konto, powód, potwierdzenie tożsamości, liczba
  zakończonych sesji). **Do wdrożenia przy ekranie (E3.10/E3.11):** trasa resetu za `password.confirm`
  (ponowne potwierdzenie hasła wykonującego) i `mfa`.
- **Brak konta awaryjnego i hasła uniwersalnego.** Procedura awaryjna jest potrzebna (rozstrzygnięcie Z-040 pkt 1)
  i powstaje jako osobny obowiązkowy podetap E3.8d — jawne polecenie konsolowe dla osoby z dostępem
  administracyjnym do serwera, bez nadawania uprawnień.
- **Tworzenie organizacji:** `CreateOrganization` nie ma jeszcze kontroli — wdrożenie w E3.10 (Z-040 pkt 3). Założenie
  organizacji jest podstawową funkcją UDZIO dostępną użytkownikom, **nie** wymaga ani nie daje uprawnień platformy;
  założyciel otrzymuje wyłącznie prawa w utworzonej organizacji.

## Z-040 — Rozstrzygnięcia po E3.8 (Jakub, 2026-10-06)

1. **Procedura awaryjna administratora platformy — obowiązkowa (podetap E3.8d, przed udostępnieniem systemu poza
   środowiskiem deweloperskim).** Bez uniwersalnego hasła, ukrytego konta, pomijania MFA i bez automatycznego resetu
   na podstawie samego dostępu do e-maila. Jawne polecenie konsolowe (tylko dla osoby z dostępem administracyjnym
   do serwera), które: wskazuje konkretne konto; wymaga powodu i opisu potwierdzenia tożsamości; wymaga jawnego
   potwierdzenia wykonania; usuwa MFA, kody odzyskiwania i wszystkie sesje; nie nadaje uprawnień i nie tworzy
   administratora; zapisuje pełny audyt; powiadamia właściciela konta; po resecie uprawnienia platformy działają
   dopiero po ponownym ustawieniu MFA (Z-038). Niezależnie od tego przy uruchomieniu platformy powstaje co najmniej
   dwóch administratorów platformy.
   - **Wdrożone w E3.8d:** `php artisan platform:emergency-mfa-reset <e-mail> --reason= --identity-confirmation=
     --confirm=<e-mail>` (bez opcji — pytania interaktywne; potwierdzeniem jest ponowne wpisanie adresu konta).
     Działa jako proces pod `SystemAuthority` z celem `platform.emergency_mfa_reset` i jedynym uprawnieniem
     `platform.emergency.mfa_reset`, którego nie ma żadna rola; konto zawsze dostaje odmowę (`console_only`),
     a operacja nie ma trasy HTTP (`ArchitectureTest`). Skutek jak przy resecie przez operatora (`ClearAccountMfa`):
     usunięcie sekretu TOTP i kodów odzyskiwania, koniec sesji i „zapamiętaj mnie”, e-mail do właściciela
     (`MfaResetNotice`, w języku odbiorcy). Audyt `account.mfa_reset` z `procedure: emergency`, powodem, opisem
     potwierdzenia tożsamości i operatorem (użytkownik systemu i nazwa serwera); próba bez potwierdzenia —
     wpis z wynikiem `denied` (`refusal: not_confirmed`), bez zmian na koncie. Zwykły reset przez operatora
     (`ResetAccountMfa`, `procedure: operator`) także powiadamia właściciela.
2. **Reset MFA przez ekran — wymagania przed wykonaniem:** osoba wykonująca jest zalogowana, ma uprawnienie
   `platform.mfa.reset`, ma aktywne MFA, **ponownie potwierdza hasło** (`password.confirm`) lub równoważnie się
   uwierzytelnia, podaje sposób potwierdzenia tożsamości właściciela konta i powód. Sama aktywna sesja nie wystarcza.
   Do czasu ekranu reset MFA nie ma żadnej trasy HTTP — pilnuje tego `ArchitectureTest`
   (`test_privileged_operations_have_no_http_entry_yet`: `ResetAccountMfa`, `GrantPlatformRole`,
   `InstallFirstAdministrator`, `CreateOrganization` nie występują w `app/Http` ani `routes`).
3. **Tworzenie organizacji:** kontrola w E3.10 przez centralny mechanizm decyzji. Do tego czasu żaden kontroler,
   API ani formularz nie tworzy organizacji (ten sam test). Założyciel dostaje tylko prawa w swojej organizacji, nigdy
   uprawnienia platformy.
4. **Potwierdzony e-mail** jest wymagany do: uprawnień z przypisanej roli, zarządzania organizacją i uprawnień
   platformy. Nie jest automatycznie wymagany do publicznych czynności uczestnika (zasady zapisu bez konta —
   scenariusz).
5. **Hasła wygodne dla użytkownika** (wdrożone w E3.8e — Z-041; poniżej stan sprzed wdrożenia i plan):
   bez reguł składu (wielka/mała litera, cyfra, znak specjalny); spacje i polskie znaki dozwolone; długie frazy;
   bez okresowej zmiany; wklejanie z menedżera haseł dozwolone; blokada haseł popularnych i ujawnionych w wyciekach;
   limit prób logowania; nieodwracalny skrót do haseł, preferencyjnie Argon2id z indywidualną solą; nigdy
   odwracalne szyfrowanie. Minimum 15 znaków dla konta chronionego tylko hasłem; dla kont z MFA można dopuścić
   krótsze, ale bez reguł składu.
   - **Spacje są częścią hasła:** nie są usuwane z początku, końca ani środka (Laravel nie przycina pól `password`,
     `password_confirmation`, `current_password`). Formularz może ostrzec o spacji na początku lub końcu, ale nie
     zmienia hasła. Testy: `PasswordWithSpacesTest` (założenie konta, potwierdzenie, zmiana, ustawienie/reset linkiem,
     logowanie tylko dokładnym hasłem).
   - **Stan na 2026-10-06:** minimum 12 znaków, maksimum 255, brak reguł składu; brak kontroli haseł popularnych
     i ujawnionych; skrót bcrypt (koszt 12, sól w skrócie, `rehash_on_login` włączone); logowanie 5 prób/min na
     adres e-mail + IP, kod MFA 5 prób/min; wklejanie nie jest blokowane (formularze bez JavaScriptu).
   - **Ryzyko bcrypt:** bcrypt uwzględnia tylko pierwsze 72 bajty hasła (polska litera = 2 bajty); dłuższa fraza
     jest po cichu obcinana. Argon2id nie ma tego ograniczenia.
   - **Plan E3.8e:** (a) `Password::min(15)` dla wszystkich kont (wariant prostszy niż osobne minimum dla kont z MFA
     — do decyzji), maksimum np. 256 znaków; (b) `uncompromised()` — sprawdzenie w bazie Have I Been Pwned metodą
     k-anonimowości (wysyłany jest tylko 5-znakowy prefiks skrótu SHA-1) oraz lokalna lista haseł popularnych;
     zachowanie przy niedostępności usługi do decyzji (domyślnie: nie blokować zakładania konta); (c) Argon2id
     (`HASH_DRIVER=argon2id`) z planem zgodności: `hashing.argon.verify=false` na czas przejścia, aby istniejące skróty
     bcrypt nadal działały, przeliczenie na Argon2id przy najbliższym udanym logowaniu (`rehash_on_login`), testy
     logowania kontem z bcrypt, przeliczenia i nowego konta; raport liczby kont jeszcze z bcrypt; po okresie
     przejściowym konta bez logowania dostają reset hasła; (d) obecne konta z hasłem krótszym niż 15 znaków nie są
     blokowane — nowe minimum obowiązuje przy zakładaniu konta i zmianie hasła.
6. **Logowanie bez hasła (późniejsze rozszerzenie):** link logujący (Magic Link) dla uczestników i klucze dostępu
   (passkeys: odcisk palca, rozpoznanie twarzy, zabezpieczenie urządzenia; Fortify ma wbudowaną obsługę). Nie jest
   warunkiem zamknięcia E3.8. Zasada architektury: hasło nie jest jedyną metodą logowania — warunki dostępu
   (potwierdzony e-mail, MFA dla ról uprzywilejowanych, Z-038) dotyczą konta, nie metody logowania. Link logujący
   nie może zastąpić MFA dla ról uprzywilejowanych ani resetować MFA.
7. **CI:** przed oznaczeniem E3 jako zakończonego wynik CI na GitHubie jest sprawdzany (publiczne API GitHub Actions
   — działa bez `gh`) albo potwierdzany ręcznie przez Jakuba. Stan 2026-10-06: E3.8a i E3.8b — sukces na gałęziach
   i na `main` (`3bd74b9`).

## Z-041 — Polityka haseł i Argon2id (E3.8e, decyzje Jakuba 2026-10-06)

- **Długość:** co najmniej 15 znaków dla wszystkich kont (także z MFA), najwyżej 255 — liczone w znakach widocznych
  dla użytkownika (`mb_strlen`), spacje się wliczają. Bez reguł składu (wielka litera, cyfra, znak specjalny).
  Spacje i polskie znaki dozwolone; hasło nie jest przycinane ani zmieniane i jest porównywane dokładnie.
  Komunikaty: „Hasło musi mieć co najmniej 15 znaków. Możesz użyć łatwego do zapamiętania zdania ze spacjami.”,
  „Hasło może mieć najwyżej 255 znaków.” Ustawienia: `identity.passwords.min_length` / `max_length`.
- **Hasła popularne i oczywiste — zawsze, lokalnie** (`NotCommonPassword`, `resources/security/common-passwords.txt`):
  porównanie bez rozróżniania wielkości liter i bez spacji (samo hasło się nie zmienia); odrzucane są hasła z listy,
  jeden znak lub krótki fragment (do 4 znaków) powtórzony, ciągi klawiatury, cyfr i alfabetu (także wstecz).
  Lista jest do uzupełniania wraz z doświadczeniem.
- **Hasła z wycieków — Have I Been Pwned** (`NotBreachedPassword` + `BreachedPasswordVerifier`, włączane
  `PASSWORD_BREACH_CHECK`, domyślnie włączone): metoda k-anonimowości — do usługi trafia tylko 5 pierwszych znaków
  skrótu SHA-1, dopasowanie odbywa się lokalnie; nagłówek `Add-Padding`; limit czasu 3 s. Hasło już odrzucone
  lokalnie nie jest wysyłane. Niedostępność usługi (błąd połączenia, przekroczenie czasu, odpowiedź inna niż
  2xx) nie blokuje założenia konta ani ustawienia, zmiany czy resetu hasła; w logu technicznym trafia tylko rodzaj
  awarii (bez hasła, skrótu i jego prefiksu), a użytkownik nie widzi żadnego komunikatu. Odrzucone hasło (popularne
  albo z wycieku) — jeden komunikat: „To hasło jest zbyt popularne albo pojawiło się w wycieku danych. Wybierz
  inne, najlepiej dłuższą frazę.” — bez nazwy wycieku i liczby wystąpień. Testy nie łączą się z siecią
  (`Http::preventStrayRequests`, w `phpunit.xml` kontrola wyłączona i włączana w testach z atrapą usługi).
- **Argon2id** (`config/hashing.php`): sterownik `argon2id`, parametry wg minimum OWASP: 19 MiB pamięci (19456 KiB),
  2 przebiegi, 1 wątek — pomiar: ok. 70 ms na sprawdzenie hasła (wartości domyślne Laravel 64 MiB / 4 przebiegi:
  ok. 430 ms i 64 MiB na każde równoczesne logowanie — za dużo dla małego serwera). Sól indywidualna w każdym
  skrócie (PHP `password_hash`). Argon2id nie obcina długich haseł (bcrypt uwzględniał tylko 72 bajty).
  Hasła nigdy nie są szyfrowane odwracalnie.
- **Łagodne przejście z bcrypt:** nowe i zmieniane hasła — Argon2id; istniejące skróty bcrypt nadal działają
  (`hashing.argon.verify = false` na czas przejścia); po poprawnym logowaniu skrót jest przeliczany na Argon2id
  (`rehash_on_login`, zapis przez `AuditedUserProvider` z powodem „password hash recomputed”, wartość w audycie
  `[REDACTED]`); nieudane logowanie niczego nie przelicza. Bez wymuszania zmiany hasła i bez zbiorczego
  przeliczania. **Do wykonania później (E12):** sprawdzić liczbę kont z bcrypt (`password LIKE '$2y$%'`), a gdy nie
  zostanie żadne — ustawić `HASH_VERIFY=true`; konta, które przez długi czas się nie logowały, mogą dostać
  zaproszenie do ustawienia hasła (decyzja przy E12).
- **Obecne konta z hasłem krótszym niż 15 znaków** nie są blokowane — nowe minimum obowiązuje przy zakładaniu konta
  oraz ustawianiu, zmianie i resecie hasła. Limit prób logowania bez zmian: 5/min (e-mail + IP), kod MFA 5/min.
- **Weryfikacja:** `PasswordPolicyTest` (11 przypadków) i `PasswordWithSpacesTest`.

## Z-042 — Uprawnienia do operacji E2 (E3.9, doprecyzowane decyzjami Jakuba w E3.9a, 2026-10-06)

- **Zasada:** procedury Identity z E2 (`ResolvePersonLinkReview`, `GrantRepresentation`) pozostają prymitywami bez
  zależności od organizacji; wykonuje je uprawniona rola przez akcje Organization z centralną decyzją
  (`ResolvePersonLinkReviewAsOperator`, `EstablishRepresentation`). Żadna ścieżka HTTP nie wywołuje prymitywów
  bezpośrednio (`ArchitectureTest`).

### Rozstrzyganie powiązania konta z osobą (Z-022)

- **Zakres operatora organizacji** (`person_links.resolve`, `PersonLinkReviewAccess::coveredCandidates`) obejmuje
  kandydatów, którzy są:
  - **obecnymi członkami** jednostek w jego zakresie;
  - **byłymi członkami** jednostek, w których operator ma **dziś** także `members.history.view` (z tym samym limitem
    przechowywania co historia członkostwa, `organization.history.visible_days`; także jednostki zarchiwizowane
    należące do zakresu) — przykład: Anna uczestniczyła rok temu w zajęciach i dopiero teraz zakłada konto;
    sprawę rozstrzyga operator organizacji z dzisiejszym prawem do historii, nie administrator platformy;
  - osobami związanymi z organizacją inną uzasadnioną relacją — **gdy taka relacja powstanie w Core** (wtedy
    dopisywana tu i w `coveredCandidates`).
  Dawne członkostwo operatora ani jego dawna rola nie dają dzisiaj dostępu (prawa liczone z przypisań aktywnych
  dziś, Z-037).
- **Widoczność:** operator widzi tylko objętych kandydatów (identyfikator, imię, nazwisko). O kandydatach spoza
  zakresu dowiaduje się wyłącznie, że sprawa wymaga rozstrzygnięcia na wyższym poziomie
  (`escalation_notice`, komunikat „Tej sprawy nie można rozstrzygnąć w Twoim zakresie — wymaga rozstrzygnięcia
  na wyższym poziomie.” — także przy odmowie `candidate_outside_scope` / `candidates_outside_scope`). Brak
  objętych kandydatów — 404 (`no_candidate_in_scope`).
- **Nowa PERSON:** tylko gdy zakres operatora obejmuje wszystkich kandydatów; nie wolno tworzyć kolejnej PERSON,
  aby ominąć istniejących kandydatów.
- **Podstawa rozstrzygnięcia — zawsze** (operator i administrator platformy, połączenie i nowa osoba):
  `PersonLinkBasis` — `email_reconfirmed` (ponowne potwierdzenie adresu e-mail), `phone_confirmed` (potwierdzenie
  numeru telefonu), `person_confirmation` (potwierdzenie przez zainteresowaną osobę), `document` (dokument lub inny
  wiarygodny dowód), `organizations_agreement` (zgodne potwierdzenia organizacji posiadających zapisy) — oraz opis
  sprawdzonego dowodu i powód. **Podobieństwo imienia, nazwiska ani danych kontaktowych nie jest podstawą** (nie ma
  takiej wartości). Bez wiarygodnej podstawy zgłoszenie pozostaje otwarte — bezpieczniej niż połączyć konto
  z danymi obcej osoby. Audyt `person_link_review.resolved` (poziom, podstawa, dowód, wybrana osoba lub nowa).
- **Administrator platformy** (`platform.person_links.resolve`) obsługuje sprawy, których nie może rozstrzygnąć
  żadna pojedyncza organizacja; nie zgaduje — ta sama obowiązkowa podstawa; widzi tylko dane potrzebne do
  rozstrzygnięcia (identyfikator, imię, nazwisko kandydatów); MFA; pełny audyt; nigdy sprawa własnego konta.
- Nikt nie rozstrzyga zgłoszenia własnego konta (`own_review`, `own_account`).

### Ustanawianie reprezentacji (Z-025) przez rolę

- Rola ustanawia reprezentację **tylko według polityki**, którą przewiduje scenariusz lub konfiguracja organizacji
  (`organization.representation_policies`, domyślnie pusta — żadna rola niczego nie ustanawia). Polityka określa:
  wobec kogo (funkcje członkostwa, maksymalny wiek), na jakiej podstawie (`document` lub `role_decision`), jaki
  dokument trzeba sprawdzić, na jaki okres (`max_days` — wtedy data końca obowiązkowa), jakie zakresy dostaje
  reprezentant i w których organizacjach obowiązuje. Pracownik nie ustanawia reprezentacji, bo uważa ją za
  przydatną. Przykłady podstaw: dokument opieki rodzica, postanowienie sądu lub organu, upoważnienie potwierdzone
  przez pełnoletnią osobę (to podstawa od strony — nie od roli), wpis pracownika po sprawdzeniu dokumentu.
- Akceptacja obu stron i oświadczenie pozostają odrębnymi podstawami od zainteresowanych osób — nie od roli.
- Operator potrzebuje `representations.establish` w jednostce, w której osoba reprezentowana jest obecnym członkiem
  zgodnym z polityką; poza zakresem — 404 (`represented_outside_scope`). Wymagane: sprawdzony dokument (zapisany
  jako podstawa reprezentacji), powód; audyt `access.granted` i `representation.established_by_role` (polityka,
  dokument, zakresy, koniec). Reguły E2 nadal obowiązują (włączone sposoby, dozwolone zakresy, nigdy dla siebie).

### Zmiana i zakończenie reprezentacji (reguły; ekrany później)

- Zakończyć może: osoba reprezentowana, jeżeli pozwala na to jej sytuacja prawna; reprezentant; uprawniona rola,
  jeżeli reprezentacja powstała na podstawie podlegającej jej kontroli (np. polityka jej organizacji).
- Przy reprezentacji wynikającej z prawa lub decyzji organu samodzielne zakończenie może być ograniczone zgodnie
  z jej podstawą (do zapisania w polityce, gdy powstanie ekran).
- Zakończenie nie usuwa historii wcześniejszych działań (`person.acted_on_behalf` zostaje w audycie).
- Zmiana zakresu nie poprawia starego wpisu: kończy dotychczasowy okres i otwiera nowy (już tak działa
  `ChangeRepresentationScopes`, wzorzec E1.5).
- Operacje roli wymagają powodu, audytu i MFA.

### MFA

- `person_links.resolve` i `representations.establish` są uprzywilejowane (`organization.privileged_access.permissions`)
  — obie mogą udostępnić konto lub dane innej osoby (decyzja Jakuba). Odmowa z powodu MFA lub e-maila daje polską
  wskazówkę zamiast „nie znaleziono” (`AccessDecider::blockedBySecurityCondition`).
- **Weryfikacja:** `E2OperationsAuthorizationTest` (14 przypadków).
