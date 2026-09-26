# Udzio

Nowa, czysta wersja projektu Udzio, rozpoczęta 2026-09-26.

Poprzednia wersja (Udzio / SKALIK) jest zamknięta i archiwalna:
repozytorium `7syriusz/udzio_old_project`, tag `archiwum-2026-09-26`.
Służy wyłącznie jako materiał referencyjny; kod nie jest przenoszony.

## Uruchomienie lokalne

Wymagane: PHP 8.3, Composer, Node.js, Docker (Compose).

```bash
cp .env.example .env && php artisan key:generate   # tylko przy pierwszym uruchomieniu
composer install
docker compose up -d          # MySQL 8.4 na 127.0.0.1:3307 (bazy udzio i udzio_test)
php artisan migrate
php artisan test              # testy zawsze na bazie udzio_test
```

Testy migrują i czyszczą bazę, dlatego działają wyłącznie na MySQL i bazie `*_test`
(strażniki: `tests/bootstrap.php` i `Tests\TestCase::createApplication()`).

## Dokumentacja

- Specyfikacja nadrzędna: [docs/specifications/E1E2E3A5Skalik.md](docs/specifications/E1E2E3A5Skalik.md)
- Plan etapów i zasady pracy: [docs/PLAN-ETAPOW.md](docs/PLAN-ETAPOW.md)
- Rejestr założeń: [docs/ZALOZENIA.md](docs/ZALOZENIA.md)
