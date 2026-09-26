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
