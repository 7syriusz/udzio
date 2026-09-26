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
