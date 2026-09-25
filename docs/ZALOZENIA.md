# Rejestr założeń

Założenia przyjęte tam, gdzie specyfikacja A5 nie rozstrzyga sprawy ([PLAN-ETAPOW.md](PLAN-ETAPOW.md), zasady 2 i 3).
Każde założenie ma być łatwe do zmiany. Wpis wskazuje, gdzie je zmienić.

Rodzaje:
- **T** — decyzja techniczna (zasada 2);
- **F** — założenie funkcjonalne widoczne dla użytkownika (zasada 3), do przeglądu przez Jakuba.

| ID | Data | Rodzaj | Założenie | Uzasadnienie | Jak zmienić |
|---|---|---|---|---|---|
| Z-001 | 2026-09-26 | T | Stos: Laravel 13, PHP 8.3, MySQL 8.4 (produkcja), Blade + Tailwind + Alpine.js | Najprostszy znany stos; sprawdzona infrastruktura z archiwum | Wymiana warstwy widoku bez zmiany domeny |
| Z-002 | 2026-09-26 | T | Testy domyślnie na MySQL; lokalnie prywatna instancja MySQL 8, w CI MySQL 8.4 | SQLite ukrywa błędy blokad (ustalenie z etapu 0 archiwum) | Konfiguracja phpunit |
| Z-003 | 2026-09-26 | T | Lokalnie bez Dockera (brak Dockera i uprawnień administratora); Docker tylko dla obrazu produkcyjnego, sprawdzany w CI | Warunki środowiska deweloperskiego | Dodanie `compose` deweloperskiego, gdy Docker będzie dostępny |
| Z-004 | 2026-09-26 | T | Czas w aplikacji i bazie w UTC; prezentacja w strefie użytkownika (domyślnie Europe/Warsaw) dodana przy pierwszych ekranach z datami | Jednoznaczna historia i audyt (A5 §3.3, §11) | `config/app.php` `timezone` + formatowanie w widokach |
| Z-005 | 2026-09-26 | T | Pliki `AGENTS.md`/`CLAUDE.md` ze szkieletu Laravela (instrukcja instalacji Laravel Boost i skryptów z internetu) nie są dołączone | Nie należą do projektu i nie były zlecone | Dodać ręcznie, jeśli zespół zdecyduje się na Laravel Boost |
