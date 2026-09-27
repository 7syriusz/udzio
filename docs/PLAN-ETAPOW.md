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
| E2.10 | Porządki przed E3: pusta struktura bazy bez danych przykładowych, ochrona przed skasowaniem bazy, decyzje Z-017/Z-020/Z-022/Z-025 | Czysty schemat A5 E1–E2, decyzje wdrożone | Pełny zestaw, migracja pustej bazy lokalnej i testowej | 120 min |

## Zasady podziału E3 i dalej (budżet pracy AI)

Podetapy są cięte tak, aby jedna sesja AI zmieściła je bez wyczerpania zasobów:
- czas 60–120 min; zakres jednego pojęcia albo jednej warstwy (model **albo** ekran, nie oba naraz);
- orientacyjnie do ~15 zmienionych plików i 1–2 nowych tabel na podetap;
- testy zawężone do zmienianego obszaru w trakcie pracy, pełny zestaw raz przed commitem;
- jeśli podetap rośnie ponad budżet — zatrzymanie, commit tego, co zielone, i podział na `a`/`b` zamiast przeciągania;
- każdy podetap kończy się scaleniem do `main`, więc przerwanie pracy nigdy nie zostawia połowy funkcji na gałęzi.

## E3 — Organizacja i dostęp (A5 §2.1, §2.3, §2.4, §1.1 widoczność; A5-01, A5-05, A5-14, A5-17)

| Podetap | Zakres | Wynik / kryterium zakończenia | Testy | Czas |
|---|---|---|---|---|
| E3.1 | ORGANIZATION: model globalny (publiczny ULID, nazwa, status aktywna/zarchiwizowana), klasyfikacja pól, utworzenie, zmiana nazwy, archiwizacja bez kasowania | Organizacja odrębna od PERSON, z historią zmian | Tworzenie, zmiana z powodem, archiwizacja, brak usuwania | 90 min |
| E3.2 | Struktura: jednostki jako ta sama ORGANIZATION z rodzicem; hierarchia dowolnej głębokości, przeniesienie jednostki jako relacja w czasie, zapytania o przodków i potomków | Firma → oddział → dział bez nowych typów Core | Głębokość, zakaz cykli, historia przeniesień, stan „na dzień” | 120 min |
| E3.3 | Członkostwo: RELATION ROLE PERSON → ORGANIZATION (funkcja, status, okres) na wzorcu E1.5; przyjęcie, zawieszenie, zmiana funkcji, przeniesienie, zakończenie | Historia członkostwa nie ginie (A5-05) | Stan na dzień, przeniesienie między jednostkami, brak nakładania, test współbieżny | 120 min |
| E3.4 | PERMISSION i ACCESS ROLE: katalog uprawnień w kodzie (bez nazw branżowych), role jako dane organizacji = zestaw uprawnień, zmiany ról audytowane | Rola to konfiguracja, nie zaszycie w kodzie | Tworzenie roli, nieznane uprawnienie odrzucone, audyt zmian | 90 min |
| E3.5 | Przypisanie roli: ACCOUNT → ACCESS ROLE w SCOPE (jednostka) z okresem; jawna polityka dziedziczenia „tylko ta jednostka” / „jednostka i potomkowie” | SCOPE jest jawny, bez automatycznego dziedziczenia (A5 §2.4) | Nadanie, wygaśnięcie, dziedziczenie tylko wg polityki, brak w obcej gałęzi | 120 min |
| E3.6 | Silnik autoryzacji: PERMISSION ∧ SCOPE ∧ aktywne przypisanie w chwili działania; integracja z Gate/politykami Laravel; odmowa = `AccessDenied` (404 dla obcej organizacji) | Jedno miejsce decyzji o dostępie (A5-14) | Pozytywne i negatywne: brak uprawnienia, obca organizacja, wygasła rola, reprezentacja ≠ rola | 120 min |
| E3.7 | Izolacja danych: zapytania ograniczone do dozwolonych jednostek; globalna tożsamość bez globalnej widoczności (PERSON widoczna tylko przez kontekst) | Administrator jednej organizacji nie widzi danych innej (A5-01) | Listy i odczyty w obcej organizacji puste/404, audyt odmów | 120 min |
| E3.8 | Pierwszy administrator i MFA: komenda instalacyjna tworząca organizację i pierwszego administratora (bez danych przykładowych); role z wymogiem MFA → middleware `mfa` | Start systemu bez seederów; administrator z MFA (A5 §12) | Komenda idempotentna, rola z MFA bez MFA → 403 | 90 min |
| E3.9 | Uprawnienia do operacji E2: rozstrzyganie `PersonLinkReview` (Z-022) i ustanawianie REPRESENTATION przez uprawnioną rolę (Z-025) w zakresie | Procedury E2 mają właściciela w systemie ról | Uprawniony operator tak, inny i obca organizacja nie | 90 min |
| E3.10 | Ekrany: organizacja i struktura (drzewo, tworzenie jednostki, przeniesienie) | Administrator zarządza strukturą | Testy HTTP i odmów | 120 min |
| E3.11 | Ekrany: członkowie i role (lista członków, przyjęcie/zakończenie, role i przypisania) | Administrator zarządza członkami i dostępem | Testy HTTP i odmów, 404 dla obcych | 120 min |
| E3.12 | Zamknięcie E3: scenariusz przekrojowy A5-01/05/14/17, ARCHITEKTURA, A5-POKRYCIE, tag `e3-zamkniety` | Organizacje i dostęp gotowe pod wydarzenia (E4) | Pełny zestaw lokalnie i w CI | 60 min |

Razem E3: ok. 19–20 h pracy w 12 podetapach.

## E4 — Wydarzenia, program i grupy (A5 §2.2, §3.1, §3.3; A5-06, A5-17)

| Podetap | Zakres | Wynik / kryterium zakończenia | Testy | Czas |
|---|---|---|---|---|
| E4.1 | EVENT: jedno pojęcie Core (publiczny ULID, organizacja-właściciel, tytuł, opis, czas początku/końca w UTC + strefa prezentacji), bez typów branżowych | Wydarzenie należy do organizacji i jej zakresu | Tworzenie, walidacja czasu, izolacja organizacji | 90 min |
| E4.2 | Hierarchia EVENT: podwydarzenia dowolnej głębokości, zakaz cykli, poddrzewo, przeniesienie z historią; reguła czasu dziecka względem rodzica jako konfiguracja | Cykl → termin → sesja bez nowych typów (A5-06) | Głębokość, cykle, przeniesienie, reguła czasu | 120 min |
| E4.3 | Cykl życia: szkic → opublikowane → odwołane/zakończone; przejścia z regułami i powodem, uprawnienie `event.publish` | Publikacja i odwołanie są kontrolowane i audytowane | Dozwolone i niedozwolone przejścia, uprawnienia | 90 min |
| E4.4 | Program: pozycje programu (lekkie, bez własnego zachowania) i awans pozycji do EVENT, gdy potrzebuje zapisów, zasobów lub uprawnień (A5 §3.1) | Program nie mnoży EVENT-ów bez potrzeby | Pozycje, kolejność, awans z zachowaniem historii | 90 min |
| E4.5 | Dostęp do wydarzeń: EVENT jako SCOPE ról, jawna polityka dziedziczenia na podwydarzenia, izolacja | Organizator podwydarzenia nie widzi reszty bez polityki | Pozytywne/negatywne, dziedziczenie wg polityki | 120 min |
| E4.6 | GROUP: neutralny zbiór osób lub podmiotów, trwały albo czasowy; członkostwo jako relacja w czasie; relacje grupy z EVENT-em i organizacją | Rodzina, drużyna, wolontariusze bez nowych typów Core (A5 §2.2) | Członkostwo w czasie, relacje, izolacja | 120 min |
| E4.7 | Ekrany: wydarzenia (lista, tworzenie, drzewo podwydarzeń, publikacja) | Organizator zarządza wydarzeniami | Testy HTTP i odmów | 120 min |
| E4.8 | Ekrany: program i grupy | Organizator zarządza programem i grupami | Testy HTTP i odmów | 120 min |
| E4.9 | Zamknięcie E4: scenariusz A5-06/17, dokumentacja, tag `e4-zamkniety` | Wydarzenia gotowe pod zgłoszenia (E5) | Pełny zestaw lokalnie i w CI | 60 min |

Razem E4: ok. 15–16 h pracy w 9 podetapach. Poza zakresem E4: ACTIVITY jako fakt wykonania (E6), cykliczność RECURRENCE/PERIOD (mechanika E5 wg A5 §3.3), wspólne pule miejsc grup (A5 §2.2 — do aktualizacji modelu).

Założenia do zapisania w trakcie (zasada 3, bez zatrzymywania pracy): domyślna polityka dziedziczenia uprawnień (brak dziedziczenia), sposób utworzenia pierwszego administratora, reguła czasu podwydarzeń, strefa czasowa wydarzenia.

## E5 — Zgłoszenia, uczestnictwo, limity i kolejka (A5 §3.3, §4; A5-07; LIMIT/CAPACITY, QUEUE/WAITLIST, RECURRENCE)

| Podetap | Zakres | Wynik / kryterium zakończenia | Testy | Czas |
|---|---|---|---|---|
| E5.1 | Konfiguracja zapisów EVENT jako definicja wersjonowana (okno zapisów, kto może zgłaszać, kogo) | Zgłoszenie wskazuje wersję konfiguracji (A5-11) | Wersje, okno czasowe, zmiana nie zmienia starych zgłoszeń | 90 min |
| E5.2 | REGISTRATION: zgłoszenie jednej lub wielu osób; zgłaszający (ACTOR) ≠ zgłaszani (SUBJECT, przez reprezentację); statusy, anulowanie, odrzucenie z powodem | Zgłoszenie nie przesądza udziału (A5 §4) | Statusy, reprezentacja, odmowy, idempotencja | 120 min |
| E5.3 | Zgłoszenie osoby bez konta (PERSON + kontakt, bez fikcyjnego konta), późniejsze dołączenie konta (E2.4) | Prosty zapis bez konta (A5 §1.2) | Zgłoszenie gościa, brak duplikatu po późniejszej rejestracji | 120 min |
| E5.4 | PARTICIPATION: relacja uczestnictwa w czasie; z akceptacji zgłoszenia albo bez zgłoszenia | REGISTRATION i PARTICIPATION odrębne (A5-07) | Uczestnictwo bez zgłoszenia, zgłoszenie bez uczestnictwa | 90 min |
| E5.5 | LIMIT/CAPACITY: limity miejsc na EVENT i poddrzewie, liczenie z faktów | Nie da się przekroczyć limitu | Test współbieżny ostatniego miejsca, limit poddrzewa | 120 min |
| E5.6 | QUEUE/WAITLIST: lista rezerwowa, kolejność i awans po zwolnieniu miejsca | Kolejka sprawiedliwa i odtwarzalna | Test współbieżny awansu, anulowanie w kolejce | 120 min |
| E5.7 | Akceptacja zgłoszeń ręczna lub automatyczna (konfiguracja), potwierdzenie e-mail | Organizator decyduje albo reguła | Obie ścieżki, uprawnienia, powiadomienie | 90 min |
| E5.8 | RECURRENCE: terminy cykliczne jako podwydarzenia z reguły powtarzania; zmiana reguły nie rusza przeszłych terminów | Cykle bez ręcznego tworzenia terminów | Generowanie, zmiana reguły, wyjątki dat | 120 min |
| E5.9 | Ekrany uczestnika: zapis siebie i reprezentowanych, moje zgłoszenia, anulowanie | Uczestnik zarządza zgłoszeniami | Testy HTTP i odmów | 120 min |
| E5.10 | Ekrany organizatora: zgłoszenia, akceptacja, lista rezerwowa, lista uczestników | Organizator obsługuje zapisy | Testy HTTP i odmów, izolacja | 120 min |
| E5.11 | Zamknięcie E5: scenariusz A5-07, dokumentacja, tag `e5-zamkniety` | Zapisy gotowe pod prawa i obecność (E6) | Pełny zestaw lokalnie i w CI | 60 min |

Razem E5: ok. 19 h w 11 podetapach.

## E6 — Prawa, użycia, identyfikatory i obecność (A5 §4, §5, §3.2; A5-08)

| Podetap | Zakres | Wynik / kryterium zakończenia | Testy | Czas |
|---|---|---|---|---|
| E6.1 | ENTITLEMENT: prawo beneficjenta (ilościowe, zakres EVENT, okres), wydanie z uczestnictwa lub ręcznie; odrębne od PERMISSION | Prawo do świadczenia jako fakt | Ilość, zakres, okres, beneficjent ≠ nabywca | 120 min |
| E6.2 | USAGE: częściowe wykorzystanie, pozostała ilość odtwarzana z faktów, cofnięcie użycia jako korekta | Niewykorzystana część odtwarzalna (A5-08) | Test współbieżny podwójnego użycia, cofnięcie | 120 min |
| E6.3 | IDENTIFIER: nieprzewidywalny identyfikator prawa/relacji, unieważnienie, wymiana, wiele reprezentacji | Identyfikator ≠ prawo | Unieważnienie, wymiana, brak zgadywania | 90 min |
| E6.4 | QR jako reprezentacja identyfikatora (SVG), bez logiki biznesowej w kodzie | QR do pokazania i wydruku | Generowanie, odczyt, unieważniony kod | 90 min |
| E6.5 | Skan: operacja skanu wg konfiguracji, idempotencja, nierozpoznany kod → anonimowy licznik bez fikcyjnej PERSON | Bramka działa bezpiecznie (A5 §4) | Powtórzony skan, zły kod, rodzaj operacji | 120 min |
| E6.6 | ATTENDANCE: obecność rozpoznanej osoby w zakresie EVENT, ręczne potwierdzenie; USAGE ≠ ATTENDANCE | Obecność jako osobny fakt (A5-08) | Użycie bez obecności, obecność bez użycia | 90 min |
| E6.7 | ACTIVITY: fakt wykonanej aktywności jako zdarzenie dla raportów i reguł | ACTIVITY ≠ EVENT (A5 §3.2) | Zapis faktów, powiązanie z kontekstem | 90 min |
| E6.8 | Skany z urządzenia bez sieci: kolejka skanów, synchronizacja, deduplikacja | Bramka odporna na brak sieci | Duplikaty po synchronizacji, kolejność | 120 min |
| E6.9 | Ekrany uczestnika: moje prawa i kody QR | Uczestnik ma dostęp do kodów | Testy HTTP i odmów | 90 min |
| E6.10 | Ekran skanera operatora (telefon, kamera) | Obsługa wejścia na wydarzenie | Testy HTTP, uprawnienie skanowania | 120 min |
| E6.11 | Ekrany obecności i zestawienie użyć | Organizator widzi frekwencję | Testy HTTP i izolacji | 90 min |
| E6.12 | Zamknięcie E6: scenariusz A5-08, dokumentacja, tag `e6-zamkniety` | Prawa i obecność gotowe | Pełny zestaw lokalnie i w CI | 60 min |

Razem E6: ok. 20 h w 12 podetapach.

## E7 — Zasoby, plany, rezerwacje i przydziały (A5 §6; A5-09)

| Podetap | Zakres | Wynik / kryterium zakończenia | Testy | Czas |
|---|---|---|---|---|
| E7.1 | RESOURCE: zasób hierarchiczny, jednostkowy i ilościowy (pula) | Sala, miejsce, sprzęt, pula bez typów branżowych | Hierarchia, rodzaje, izolacja | 120 min |
| E7.2 | LAYOUT: plan wersjonowany; położenie zasobu na wersji planu | Zasób odrębny od położenia (A5 §6) | Wersje planu, zmiana nie rusza przydziałów | 120 min |
| E7.3 | Dostępność zasobu w czasie: kalendarz i blokady | Wiadomo, kiedy zasób jest wolny | Nakładanie, strefy czasu | 90 min |
| E7.4 | RESERVATION pojedynczego zasobu: czasowa blokada, wygaśnięcie | Brak podwójnej rezerwacji | Test współbieżny, wygaśnięcie | 120 min |
| E7.5 | RESERVATION zestawu zasobów atomowo, jedna polityka konfliktu | Konflikt składnika = decyzja dla całości (A5-09) | Test współbieżny zestawu, częściowy konflikt | 120 min |
| E7.6 | Rezerwacja części puli ilościowej | Pule bez przekroczeń | Test współbieżny puli | 90 min |
| E7.7 | ASSIGNMENT: przydział zasobu, osoby lub roli do kontekstu; przydział przetrwa zmianę konfiguracji | Przydzielone miejsce nie znika (A5 §6) | Przydział, zmiana planu, historia | 90 min |
| E7.8 | Ekrany zasobów i podglądu planu | Organizator zarządza zasobami | Testy HTTP i odmów | 120 min |
| E7.9 | Ekrany rezerwacji i przydziałów | Obsługa rezerwacji w interfejsie | Testy HTTP i odmów | 120 min |
| E7.10 | Zamknięcie E7: scenariusz A5-09, dokumentacja, tag `e7-zamkniety` | Zasoby gotowe | Pełny zestaw lokalnie i w CI | 60 min |

Razem E7: ok. 17 h w 10 podetapach.

## E8 — Oferta, zamówienia, należności i płatności (A5 §7, §8; A5-10, A5-16)

| Podetap | Zakres | Wynik / kryterium zakończenia | Testy | Czas |
|---|---|---|---|---|
| E8.1 | OFFERING: pozycja katalogu z warunkami dostępności | Katalog ≠ zamówienie | Warunki, izolacja | 90 min |
| E8.2 | PRICING POLICY: naliczenie ceny z podstawą i wersją reguły | Cena odtwarzalna po zmianie reguły (A5-16) | Wersje reguł, kwoty całkowite | 120 min |
| E8.3 | ORDER / ORDER ITEM z niezależnymi rolami BUYER, PAYER, PARTICIPANT, BENEFICIARY; idempotencja | Role ekonomiczne niezależne (A5-10) | Te same i różne osoby w rolach, ponowienie | 120 min |
| E8.4 | OBLIGATION: należność z terminem, częściowe wykonanie | Należność ≠ płatność | Terminy, częściowe wykonanie | 90 min |
| E8.5 | PAYMENT: rejestracja wpłaty (przelew, gotówka) niezależnie od należności | Wpłata jako fakt | Rejestracja, powód, audyt | 90 min |
| E8.6 | ALLOCATION: rozliczenie wpłaty na jedną lub wiele należności, saldo | Wiadomo, co zapłacono | Test współbieżny rozliczeń, nadpłata | 120 min |
| E8.7 | LEDGER: niezmienny dziennik zdarzeń wartości, odtwarzanie salda | Saldo z historii (A5 §8) | Odtworzenie salda, niezmienność | 120 min |
| E8.8 | ADJUSTMENT i REFUND jako odrębne operacje z powodem i historią | Korekta ≠ zwrot | Obie operacje, wpływ na saldo | 120 min |
| E8.9 | Operator płatności online: kontrakt dostawcy, webhook idempotentny (piaskownica) | Płatność online bez zależności od jednego dostawcy | Podwójny webhook, podpis, błąd dostawcy | 120 min |
| E8.10 | Powiązanie z E5/E6: opłacenie → zgłoszenie/ENTITLEMENT wg reguły | Płatność uruchamia skutki | Reguła, zwrot cofa skutek | 120 min |
| E8.11 | Ekrany uczestnika: zamówienie i płatność | Uczestnik płaci | Testy HTTP i odmów | 120 min |
| E8.12 | Ekrany finansów organizatora: należności, wpłaty, rozliczenia, korekty | Organizator rozlicza | Testy HTTP i odmów, izolacja | 120 min |
| E8.13 | Zamknięcie E8: scenariusz A5-10, dokumentacja, tag `e8-zamkniety` | Finanse gotowe | Pełny zestaw lokalnie i w CI | 60 min |

Razem E8: ok. 23 h w 13 podetapach. Poza zakresem: OFFER z kontrofertą, VALUE/POINTS i UDZ.io (katalog E5 A5 §15).

## E9 — Formularze, dokumenty, zgody, sprawy (A5 §9, §10; A5-11)

| Podetap | Zakres | Wynik / kryterium zakończenia | Testy | Czas |
|---|---|---|---|---|
| E9.1 | FORM: definicja pól typowanych z walidacją i klasą danych każdego pola, wersjonowana | Formularz jako konfiguracja | Typy, walidacje, wersje | 120 min |
| E9.2 | FORM RESPONSE: odpowiedź wskazuje wersję, walidacja wg wersji | Odpowiedź odtwarzalna (A5-11) | Zmiana formularza nie psuje starych odpowiedzi | 120 min |
| E9.3 | Formularze w zgłoszeniu (E5), także za osobę reprezentowaną | Dane zbierane przy zapisie | Wypełnianie w imieniu, klasyfikacja | 90 min |
| E9.4 | DOCUMENT: treść wersjonowana (regulamin, polityka), publikacja | Dokument z historią wersji | Wersje, publikacja | 90 min |
| E9.5 | CONSENT: akt zgody wobec wersji dokumentu, wycofanie bez usuwania faktów, zgoda w imieniu | Zgoda odrębna od dokumentu (A5 §9) | Wycofanie, reprezentacja `consents.manage` | 120 min |
| E9.6 | Wymagane zgody jako warunek operacji (np. zapisu) | Brak zgody blokuje operację | Warunek, nowa wersja dokumentu | 90 min |
| E9.7 | CASE i TASK (lekko): sprawa i zadanie z odpowiedzialnym, terminem, statusem, historią | Obsługa problemów i czynności | Statusy, odpowiedzialny, historia | 120 min |
| E9.8 | Ekran kreatora formularzy (organizator) | Organizator buduje formularze | Testy HTTP i odmów | 120 min |
| E9.9 | Ekrany wypełniania formularzy i moich zgód | Uczestnik wypełnia i zarządza zgodami | Testy HTTP i odmów | 120 min |
| E9.10 | Zamknięcie E9: dokumentacja, tag `e9-zamkniety` | Formularze i zgody gotowe | Pełny zestaw lokalnie i w CI | 60 min |

Razem E9: ok. 18 h w 10 podetapach.

## E10 — Komunikacja, automatyzacje, raporty i eksport (A5 §13, §14)

| Podetap | Zakres | Wynik / kryterium zakończenia | Testy | Czas |
|---|---|---|---|---|
| E10.1 | COMMUNICATION: szablon wersjonowany, wiadomość, odbiorca, kanał, status, historia | Wysłana wiadomość jako fakt | Szablon ≠ wiadomość, historia | 120 min |
| E10.2 | Wysyłka e-mail przez kolejkę: statusy doręczenia, ponowienia, idempotencja | Niezawodna wysyłka | Ponowienie, brak duplikatów | 120 min |
| E10.3 | Odbiorcy wg kontekstu (uczestnicy, członkowie), zgody i kontakt reprezentanta | Wiadomość trafia do właściwych osób | Zgody, reprezentacja, izolacja | 120 min |
| E10.4 | SMS: podłączenie wybranego operatora do kontraktu Z-020, weryfikacja telefonu | Telefon weryfikowalny | Kontrakt, błędy operatora | 90 min |
| E10.5 | Automatyzacja TRIGGER → CONDITION → ACTION (np. potwierdzenie zapisu), idempotencja | Reguły zamiast logiki w ekranach (A5 §13) | Wyzwolenie, warunek, powtórzenie | 120 min |
| E10.6 | REPORT DEFINITION: zestawienie liczone z Core, snapshot tylko gdy wymagany | Raport odtwarzalny (A5 §14) | Wynik, snapshot, uprawnienia | 120 min |
| E10.7 | Eksport danych (CSV/XLSX) z polityką klas danych i audytem | Eksport zgodny z Z-017 | Redakcja, pominięcie SECRET, audyt | 90 min |
| E10.8 | Ekrany komunikacji: tworzenie, podgląd, historia | Organizator komunikuje się | Testy HTTP i odmów | 120 min |
| E10.9 | Ekrany raportów i eksportu | Organizator raportuje | Testy HTTP i odmów | 120 min |
| E10.10 | Zamknięcie E10: dokumentacja, tag `e10-zamkniety` | Komunikacja i raporty gotowe | Pełny zestaw lokalnie i w CI | 60 min |

Razem E10: ok. 18 h w 10 podetapach. Pełny silnik RULE/WORKFLOW — poza zakresem A5 (§13, §15).

## E11 — CLONE, MERGE, eksport i import konfiguracji (A5 §11, §14; A5-12, A5-13)

| Podetap | Zakres | Wynik / kryterium zakończenia | Testy | Czas |
|---|---|---|---|---|
| E11.1 | CLONE konfiguracji wydarzenia w jawnym zakresie, bez danych wykonania | Kopia bez uczestników i płatności (A5-12) | Zakres, brak danych wykonania | 120 min |
| E11.2 | CLONE poddrzewa wydarzeń z definicjami (formularze, oferty, zasoby) i mapowaniem identyfikatorów | Kopiowanie całych cykli | Mapowanie, spójność, wersje | 120 min |
| E11.3 | MERGE PERSON: scalenie duplikatów z pochodzeniem, przeniesienie relacji, konflikt dwóch kont | Scalenie bez utraty pochodzenia (A5-13) | Relacje, historia, konflikty | 120 min |
| E11.4 | Cofnięcie błędnego MERGE z historii | Kontrolowana korekta decyzji (A5-13) | Cofnięcie, zmiany po scaleniu | 120 min |
| E11.5 | MERGE ORGANIZATION i innych kartotek wg tego samego wzorca | Wzorzec MERGE ogólny | Scalenie, cofnięcie | 90 min |
| E11.6 | Eksport pełnej konfiguracji z `schema_version` | Konfiguracja przenośna (A5 §14) | Kompletność, wersja schematu | 90 min |
| E11.7 | Eksport pakietu zmian (delta) | Przenoszenie zmian | Delta, kolejność | 120 min |
| E11.8 | Import z walidacją i migracją wersji, bez danych operacyjnych, idempotentny | Bezpieczny import | Zła wersja, powtórny import | 120 min |
| E11.9 | Ekrany: klonowanie, scalanie (operator), import i eksport | Operacje dostępne w interfejsie | Testy HTTP i odmów | 120 min |
| E11.10 | Zamknięcie E11: scenariusz A5-12/13, tag `e11-zamkniety` | Funkcje platformy kompletne | Pełny zestaw lokalnie i w CI | 60 min |

Razem E11: ok. 18 h w 10 podetapach.

## E12 — Utwardzenie i wdrożenie produkcyjne (A5 §12)

| Podetap | Zakres | Wynik / kryterium zakończenia | Testy | Czas |
|---|---|---|---|---|
| E12.1 | Retencja i anonimizacja wg Z-017 (harmonogram), obsługa żądania usunięcia danych | Dane nie żyją dłużej niż trzeba | Anonimizacja, zachowanie historii rozliczeń | 120 min |
| E12.2 | Szyfrowanie danych SPECIAL CATEGORY i SECRET w spoczynku, rotacja klucza | Ochrona danych wrażliwych | Szyfrowanie, rotacja, odczyt | 120 min |
| E12.3 | Uprawnienia bazy: konto aplikacji bez DDL, osobne konto migracji (Z-012) | Aplikacja nie zmieni schematu ani wyzwalaczy | Próba DDL z konta aplikacji odrzucona | 90 min |
| E12.4 | Nagłówki bezpieczeństwa, CSP, limity żądań, przegląd ciasteczek i sesji | Twardsza warstwa HTTP | Testy nagłówków i limitów | 90 min |
| E12.5 | Przegląd bezpieczeństwa (OWASP), przekrojowe testy negatywne, skan zależności | Znane klasy błędów zamknięte | Raport i poprawki | 120 min |
| E12.6 | Wydajność: indeksy, zapytania N+1, test obciążenia zapisu i skanu | Krytyczne ścieżki szybkie | Pomiary przed/po | 120 min |
| E12.7 | Monitoring, logi, zdrowie kolejek, alerty | Awarie widoczne | Symulacja awarii | 90 min |
| E12.8 | Kopie zapasowe i test odtworzenia | Dane odtwarzalne | Odtworzenie na czystym serwerze | 90 min |
| E12.9 | Serwer VPS OVH: przygotowanie, Docker Compose produkcyjny, HTTPS (certbot), domena | Serwer gotowy | Test dymny na serwerze | 120 min |
| E12.10 | Wdrożenie: pipeline wdrożeniowy, migracje, wycofanie wersji, próbne wdrożenie | Powtarzalne wdrożenia | Wdrożenie i wycofanie | 120 min |
| E12.11 | Dostępność (WCAG) i dopracowanie kluczowych ekranów | Aplikacja przyjazna | Przegląd dostępności | 120 min |
| E12.12 | Zamknięcie projektu: kryteria A5, dokumentacja operacyjna, tag `e12-zamkniety` | Produkcja | Pełny zestaw, test na produkcji | 60 min |

Razem E12: ok. 21 h w 12 podetapach.

**Łącznie E3–E12:** 109 podetapów, ok. 190–200 h pracy (przy tempie z E1–E2 kilka sesji dziennie, kilka tygodni). Czasy są orientacyjne; każdy podetap mieści się w 120 min zgodnie z zasadami budżetu powyżej.

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
- E2.9 scalony do `main`: `b27c94f`; CI gałęzi zielone, CI `main` — przebieg `36242187635` (sukces). CI `main` zielone dla wszystkich scaleń E2.1–E2.9. Tag `e2-zamkniety` został pierwotnie nadany na `b27c94f`.
- E2.10 (2026-09-27, zlecone przed E3): przegląd migracji pod kątem pozostałości starego Skalika — w tabelach i kolumnach brak; usunięty specjalny typ reprezentacji `guardian` (zastąpiony sposobem ustanowienia, podstawą i ACTOR-em ustanawiającym, Z-025) i demonstracyjny użytkownik z `DatabaseSeeder` (seeder pusty, Z-027). Decyzje wdrożone: Z-022 procedura naprawcza `PersonLinkReview` bez ujawniania danych; Z-020 kontrakt `ContactCodeSender`, telefon bez operatora nieweryfikowalny; Z-017 retencja i sposób usunięcia dla każdej klasy (rekomendacja do akceptacji); ochrona `DestructiveCommandGuard`. Lokalna baza deweloperska utworzona od nowa i zmigrowana od zera bez seederów; baza testowa odtworzona osobno. Pełny zestaw: **190 testów / 685 asercji**, także w losowej kolejności.
- **E2 zamknięty:** zamknięciem jest commit scalający gałąź `e2/10-czysta-baza-i-decyzje` do `main`; tag `e2-zamkniety` przeniesiono na ten commit po zielonym CI na `main` (wcześniejsze położenie: `b27c94f`). E3 nie zostało rozpoczęte — czeka na akceptację raportu z E2.10.
- 2026-09-27: rozpisano E3 (12 podetapów) i E4 (9 podetapów) z zasadami budżetu pracy AI. E3 nie zostało rozpoczęte.

- E3.1 (2026-09-27, zlecone po zamknięciu E2): `Organization`, publiczny ULID, nazwa, status aktywna/zarchiwizowana; tworzenie, zmiana nazwy z powodem i archiwizacja z audytem. Blokada usunięcia w modelu i MySQL, atomowość zmian z audytem, blokada aktualnego rekordu przy zmianach. 13 nowych przypadków / 61 asercji. Pełna regresja: **203 testy / 746 asercji**, MySQL. Założenia: Z-028, ARCHITEKTURA §16. Punkt wejścia: `d9b0109`, gałąź `e3/01-organizacja`. Scalenie wymaga zielonego CI. Następny podetap: E3.2.
- E3.1 scalony do `main`: `a7546a3`; CI gałęzi `36308777078` i main `36308969872` — sukces.
- E3.2 (2026-09-27): `OrganizationParent` jako relacja w czasie E1.5, przeniesienie i odłączenie z powodem i historią, odczyt przodków/potomków na moment bez limitu głębokości. Kontrola cykli obejmuje równoległe przeniesienia (test dwóch procesów MySQL). Naprawiono obcinanie mikrosekund przy bindowaniu granic czasu we wspólnym `HasValidityPeriod`; przypadek jednej mikrosekundy zabezpiecza regresję. 10 nowych przypadków / 39 asercji. Pełny zestaw: **213 testów / 785 asercji**. Z-029, ARCHITEKTURA §17. Gałąź `e3/02-struktura`, punkt wejścia `a7546a3`. Scalenie po zielonym CI. Następny: E3.3.
- 2026-09-27: rozpisano E5–E12 na podetapy (88 podetapów) wg tych samych zasad budżetu pracy AI.
- E3.3 (2026-09-27): członkostwo PERSON → ORGANIZATION jako relacja w czasie (funkcja, status, okres): przyjęcie, zmiana funkcji, zawieszenie i wznowienie, przeniesienie między jednostkami z odnośnikiem do źródła, zakończenie; blokada osoby i testy dwóch procesów (przyjęcie, przeniesienie). Implementację rozpoczętą w poprzedniej sesji dokończono testami. 14 nowych przypadków. Pełny zestaw: **227 testów / 822 asercje**, także w losowej kolejności. Założenia: Z-030.
