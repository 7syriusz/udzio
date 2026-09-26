# Udzio

[![CI](https://github.com/7syriusz/udzio/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/7syriusz/udzio/actions/workflows/ci.yml)

Nowa, czysta wersja projektu Udzio, rozpoczęta 2026-09-26.

Poprzednia wersja (Udzio / SKALIK) jest zamknięta i archiwalna:
repozytorium `7syriusz/udzio_old_project`, tag `archiwum-2026-09-26`.
Służy wyłącznie jako materiał referencyjny; kod nie jest przenoszony.

## Uruchomienie lokalne

Wymagane: PHP 8.3 z rozszerzeniami `pdo_mysql`, `mbstring`, `bcmath`, `intl`, `zip` i `pcntl`, Composer 2, Node.js 22.12+ (sprawdzone: 22.23.2), Docker z Compose. Polecenia wykonuj w katalogu sklonowanego repozytorium.

```bash
composer install
test -f .env || cp .env.example .env
php artisan key:generate     # tylko dla NOWEJ instalacji, bez istniejącego klucza
docker compose up -d --wait  # MySQL 8.4 na 127.0.0.1:3307 (bazy udzio i udzio_test)
php artisan migrate
npm ci
npm run build
php artisan test              # testy zawsze na bazie udzio_test
php artisan serve --host=127.0.0.1 --port=8000
```

Nie generuj ponownie `APP_KEY` w istniejącej instalacji: służy również do odszyfrowywania danych.
Skrypt inicjalizacji MySQL tworzy obie bazy przy pierwszym uruchomieniu pustego wolumenu.
`docker compose stop` zatrzymuje bazę z zachowaniem danych; `down -v` usuwa wolumen i dane.
Przy zajętym porcie 3307 sprawdź uruchomione usługi przed zmianą portu w Compose i `.env`.

Testy migrują i czyszczą bazę, dlatego działają wyłącznie na MySQL i bazie `*_test`
(strażniki: `tests/bootstrap.php` i `Tests\TestCase::createApplication()`).

Testy współbieżności (`php artisan test --group concurrency`) uruchamiają niezależne procesy PHP
z własnymi połączeniami MySQL: `tests/Support/Concurrency/Race.php`.

CI (GitHub Actions) przy każdym pushu sprawdza styl (Pint) i uruchamia testy na MySQL 8.4.
Nie scalamy do `main` przy czerwonym CI.

## Produkcja (Docker)

```bash
cp .env.production.example .env.production   # uzupełnić sekrety na serwerze
docker compose -f compose.production.yaml --env-file .env.production up -d --build
docker compose -f compose.production.yaml --env-file .env.production exec app php artisan migrate --force
```

W `.env.production` ustaw oddzielne hasła bazy i roota, unikalny `APP_KEY`, adres aplikacji
i konfigurację poczty. Plik zawiera sekrety i nie trafia do Git. Ten stos E0 udostępnia HTTP;
HTTPS, kopie poza serwerem i monitoring są kryteriami E12, więc E0 nie oznacza gotowości produkcyjnej.

CI buduje obrazy i wykonuje test dymny stosu (`/up`, strona główna, blokada `/.env`).

## Dokumentacja

- Specyfikacja nadrzędna: [docs/specifications/E1E2E3A5Skalik.md](docs/specifications/E1E2E3A5Skalik.md)
- Plan etapów i zasady pracy: [docs/PLAN-ETAPOW.md](docs/PLAN-ETAPOW.md)
- Architektura i konwencje: [docs/ARCHITEKTURA.md](docs/ARCHITEKTURA.md)
- Rejestr założeń: [docs/ZALOZENIA.md](docs/ZALOZENIA.md)
