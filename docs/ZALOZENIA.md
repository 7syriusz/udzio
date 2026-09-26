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
