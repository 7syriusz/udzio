# UDZIO Core A5 — globalny model fundamentu

> Źródło: dokument przekazany przez Krzysztofa 2026-09-26 (treść Jakuba). Przeniesiony do Markdown bez zmian merytorycznych; tabele odtworzone z wersji tekstowej. Dokument nadrzędny dla całego projektu. 2026-10-08 (decyzja Jakuba): zmieniono nazwę pliku i usunięto odwołanie do dawnego dokumentu — treść modelu bez zmian.

Status: A5 określa abstrakcyjny, globalny fundament UDZIO. Dokument jest nadrzędnym modelem pojęć, relacji i granic odpowiedzialności dla dalszych specyfikacji.

## 0. Cel, status i granice dokumentu

A5 porządkuje globalny fundament UDZIO tak, aby różne zastosowania korzystały z tych samych pojęć Core, bez tworzenia osobnych modeli dla nazw branżowych.

A5 jest wersją modelu UDZIO Core, a nie etapem E5. Nie należy utożsamiać go z etapem E5, w którym będzie projektowana i zamrażana uniwersalna mechanika Core 2.0.

Dokument opisuje pojęcia, relacje i granice odpowiedzialności. Nie jest schematem bazy danych, projektem endpointów, listą ekranów ani gotową konfiguracją scenariusza.

A5 jest punktem odniesienia dla szczegółowych specyfikacji kolejnych warstw i mechanik. Specyfikacje szczegółowe rozwijają model, lecz nie zmieniają znaczenia jego podstawowych pojęć.

### 0.1. Warstwy modelu

| Warstwa | Znaczenie w A5 |
|---|---|
| CORE-A | Tożsamość i kontekst: PERSON, ACCOUNT, ORGANIZATION, struktury, GROUP, RELATION, ROLE, PERMISSION, SCOPE/CONTEXT. |
| CORE-C i mechaniki | Uniwersalne obiekty i zachowania, m.in. EVENT/ACTIVITY, RESOURCE, REGISTRATION, ENTITLEMENT, PAYMENT oraz mechaniki potwierdzone do dalszego projektu w E5. |
| Zasady i funkcje platformy | Audyt, relacje w czasie, izolacja danych, MERGE, CLONE, wersjonowanie, import/eksport oraz rozdzielenie konfiguracji od wykonania. |
| Profil i scenariusz | Sposób wykorzystania Core dla danej klasy zastosowań i konkretnego procesu. Scenariusz może składać się z podscenariuszy. |
| Konfiguracja | Konkretne wartości, formularze, reguły, limity, ceny, statusy i ustawienia organizatora. |
| Integracja/moduł specjalistyczny | Ciężka praca branżowa wykonywana poza Core, przy zachowaniu danych, zdarzeń i reguł odpowiedzialności po stronie UDZIO. |

## CZĘŚĆ I. MODEL FUNDAMENTU A5

### 1. Osoba, konto, kontakt i działanie w imieniu

#### 1.1. PERSON / OSOBA

PERSON reprezentuje rzeczywistą osobę niezależnie od wydarzenia, organizacji, roli i scenariusza. Zmiana kontekstu nie tworzy nowej osoby. Dane kontekstowe nie mogą wymuszać duplikowania PERSON.

Osoba może istnieć bez konta. Późniejsze utworzenie ACCOUNT powinno bezpiecznie dołączyć dostęp do właściwej PERSON, bez utraty wcześniejszej historii.

Globalna tożsamość nie daje administratorom globalnej widoczności. Dane przypisane do organizacji i scenariusza są udostępniane wyłącznie w dozwolonym SCOPE/CONTEXT.

#### 1.2. ACCOUNT / KONTO

ACCOUNT służy uwierzytelnieniu i dostępowi, ale nie zastępuje PERSON. Jedna PERSON ma maksymalnie jedno konto globalne UDZIO, które może korzystać z wielu metod uwierzytelnienia.

Konto uczestnika pozostaje opcjonalne dla prostego zapisu, ale jest wymagane dla trwałej historii użytkownika, obserwowania, relacji rodzinnych/opiekuńczych, dostępu do wielu scenariuszy oraz zarządzania własnymi zgodami i uprawnieniami.

#### 1.3. CONTACT i dane identyfikacyjne

CONTACT jest sposobem komunikacji, nie osobą. E-mail lub telefon mogą być użyte do zapisu i rozpoznania, ale nie są samodzielną tożsamością. Kontakt używany w sprawie dziecka może należeć do opiekuna.

#### 1.4. ACTOR ≠ SUBJECT

ACTOR oznacza osobę lub proces wykonujący czynność. SUBJECT oznacza osobę, podmiot albo obiekt, którego czynność dotyczy. Każda istotna operacja powinna pozwalać odtworzyć oba znaczenia.

- Rodzic jako ACTOR zapisuje dziecko będące SUBJECT.
- Operator jako ACTOR dokonuje korekty dotyczącej uczestnika będącego SUBJECT.
- Integracja może być ACTOR-em technicznym, a zewnętrzny płatnik lub zamówienie — SUBJECT-em biznesowym.

Działanie w imieniu wynika z relacji reprezentacji oraz jawnego zakresu dostępu. Sama reprezentacja nie oznacza pełnego dostępu do wszystkich danych reprezentowanego.

### 2. Organizacja, struktura, grupa, relacja i dostęp

#### 2.1. ORGANIZATION i struktura

ORGANIZATION jest odrębna od PERSON. Jednostki organizacyjne korzystają z tego samego modelu organizacji lub węzła struktury i mogą tworzyć hierarchię, np. firma → oddział → dział albo centrala → okręg → koło.

Struktura organizacyjna nie jest tym samym co dowolna sieć relacji. Struktura określa odpowiedzialność i zakres, natomiast RELATION/NETWORK może łączyć dowolne wspierane obiekty.

#### 2.2. GROUP / GRUPA

GROUP jest neutralnym, trwałym lub czasowym zbiorem osób albo podmiotów. Może reprezentować rodzinę, firmę, wolontariuszy, wydawców, drużynę lub grupę zajęciową. Nazwa domenowa nie tworzy nowego typu Core.

GROUP nie jest EVENT-em ani jednostką organizacyjną, choć może pozostawać z nimi w relacji. Szczegółowy model wspólnych pul imiennych i nieimiennych pozostaje do aktualizacji E4.

#### 2.3. RELATION / ROLE w czasie

Uniwersalny wzorzec: PERSON lub ORGANIZATION → RELATION/ROLE → OBJECT wraz z kontekstem, kierunkiem, okresem obowiązywania, statusem i historią.

Relacja nie może być sprowadzona do bieżącej etykiety. Przeniesienie, zawieszenie, zakończenie lub zmiana funkcji nie usuwa wcześniejszego stanu.

#### 2.4. ROLE, PERMISSION i SCOPE/CONTEXT

| Pojęcie | Znaczenie |
|---|---|
| RELATION ROLE | Znaczenie osoby lub podmiotu wobec konkretnego obiektu, np. uczestnik, reprezentant, opiekun. |
| ACCESS ROLE | Zestaw praw dostępowych przypisany użytkownikowi w określonym kontekście. |
| PERMISSION | Prawo do konkretnej operacji, np. odczyt, edycja, publikacja, skanowanie, finanse. |
| SCOPE/CONTEXT | Zakres obiektów i danych, w którym dana rola lub permission obowiązuje. |
| ENTITLEMENT | Prawo beneficjenta do świadczenia; nie jest uprawnieniem administracyjnym. |

Dziedziczenie widoczności i uprawnień działa tylko według jawnej polityki. Nie wolno zakładać automatycznego dziedziczenia we wszystkich kierunkach.

### 3. EVENT, ACTIVITY, program i czas

#### 3.1. EVENT / WYDARZENIE

Core posiada jedno hierarchiczne pojęcie EVENT. Cykl, termin, pokaz, warsztat, sesja, atrakcja i podwydarzenie są znaczeniami scenariusza. EVENT może posiadać zdarzenia podrzędne dowolnej potrzebnej głębokości.

Pozycja programu staje się EVENT-em tylko wtedy, gdy potrzebuje własnych relacji, zapisów, uprawnień, zasobów, limitów, obecności lub innego samodzielnego zachowania Core.

#### 3.2. ACTIVITY / AKTYWNOŚĆ

ACTIVITY opisuje wykonane działanie lub istotny fakt aktywności, który może być raportowany albo uruchamiać reguły. Nie należy utożsamiać ACTIVITY z EVENT-em: wydarzenie jest kontekstem lub planowanym obiektem, a aktywność jest faktem wykonania.

#### 3.3. Czas, dostępność i cykliczność

Czas jest przekrojową cechą wydarzeń, relacji, dostępności, uprawnień, rezerwacji i reguł. RECURRENCE/PERIOD jest potwierdzoną mechaniką docelową E5; A5 nie projektuje jeszcze jej pełnego cyklu życia.

### 4. Zgłoszenie, uczestnictwo, prawo, użycie i obecność

| Pojęcie | Znaczenie modelowe |
|---|---|
| REGISTRATION | Zgłoszenie osoby lub osób w kontekście; nie przesądza udziału, prawa ani obecności. |
| PARTICIPATION | Relacja uczestnictwa PERSON/ORGANIZATION z EVENT-em lub jego zakresem. |
| ENTITLEMENT | Prawo beneficjenta do wejścia, usługi, miejsca, przejazdu, posiłku, atrakcji albo innego świadczenia. |
| USAGE | Fakt wykorzystania ENTITLEMENT lub jego części. |
| ATTENDANCE | Faktyczny udział rozpoznanej osoby w określonym zakresie EVENT-u. |
| ACTIVITY | Szerszy fakt wykonanej aktywności; może istnieć także poza uczestnictwem w wydarzeniu. |

Uczestnictwo może powstać bez wcześniejszego REGISTRATION. Zgłoszenie może zostać anulowane, odrzucone albo nigdy nie doprowadzić do uczestnictwa. USAGE nie jest automatycznie ATTENDANCE.

Jeżeli osoba na bramce nie zostaje jednoznacznie rozpoznana, system może zapisać anonimowy licznik lub użycie, ale nie powinien tworzyć fikcyjnej PERSON ani indywidualnej ATTENDANCE.

No-show może być wynikiem reguły lub raportu opartego na faktach; nie musi być odrębnym bytem Core.

### 5. IDENTIFIER, QR i kanał obsługi

IDENTIFIER wskazuje kontekst, relację, uprawnienie lub zestaw danych potrzebny do procesu. QR jest jedną z jego reprezentacji. Format, zabezpieczenie, liczba kodów i reakcja na skan należą do scenariusza lub implementacji.

IDENTIFIER może obsługiwać częściowe wykorzystanie prawa oraz rozróżniać rodzaj operacji, jeżeli wymaga tego konfiguracja. Liczba i sposób reprezentacji identyfikatorów nie są własnością globalnego modelu Core.

### 6. RESOURCE, LAYOUT, RESERVATION i ASSIGNMENT

RESOURCE reprezentuje coś, czym system może dysponować, co może rezerwować, przydzielać lub ograniczać: pomieszczenie, miejsce, powierzchnię, pojazd, sprzęt albo pulę ilościową. RESOURCE może być hierarchiczny, jednostkowy lub ilościowy.

LAYOUT opisuje plan lub geometrię. Zasób jest odrębny od jego położenia na konkretnej wersji planu.

RESERVATION oznacza czasowe zablokowanie dostępności, a ASSIGNMENT — przypisanie zasobu, osoby albo roli do kontekstu. Jedna rezerwacja może obejmować zestaw kilku zasobów wymaganych łącznie.

Sprzedanego lub skutecznie przydzielonego miejsca nie usuwa się przez zwykłe skasowanie konfiguracji. Sprzedaż może usuwać kolidującą rezerwację zgodnie z regułą scenariusza, z zachowaniem historii.

### 7. Oferta, zamówienie i role ekonomiczne

#### 7.1. OFFERING a OFFER

OFFERING jest pozycją katalogu: produktem, usługą, pakietem, opłatą lub świadczeniem dostępnym na określonych warunkach.

OFFER jest osobną mechaniką Core: ma autora, odbiorcę, przedmiot, warunki, wartość, termin ważności i status oraz może obsługiwać kontrofertę. OFFER nie jest tym samym co pozycja katalogu ani zamówienie.

Pełny cykl życia OFFER zostanie zaprojektowany w E5. A5 jedynie zabezpiecza granice modelu, aby późniejsze dodanie oferty nie wymagało przebudowy tożsamości, relacji i audytu.

#### 7.2. ORDER i niezależne role

| Rola | Znaczenie |
|---|---|
| BUYER | Nabywca zawierający zamówienie lub nabywający pozycję oferty. |
| PAYER | Osoba lub podmiot faktycznie dokonujący płatności. |
| PARTICIPANT | Osoba lub podmiot posiadający relację uczestnictwa. |
| BENEFICIARY | Osoba lub podmiot, na rzecz którego istnieje świadczenie lub ENTITLEMENT. |
| ACTOR | Wykonawca operacji w systemie; może być inny niż wszystkie powyższe role. |

### 8. Należność, płatność, wartość i historia rozliczeń

| Pojęcie | Minimalne znaczenie |
|---|---|
| ORDER / ORDER ITEM | Zamówienie i jego pozycje: przedmiot, ilość, cena, beneficjent i kontekst. |
| OBLIGATION / RECEIVABLE | Zobowiązanie lub należność: kto, wobec kogo, za co, ile i do kiedy; może być wykonane częściowo. |
| PAYMENT | Rzeczywista wpłata lub rozliczenie; nie jest należnością. |
| ALLOCATION / SETTLEMENT | Powiązanie wartości lub płatności z jednym albo wieloma zobowiązaniami. |
| ADJUSTMENT | Korekta wartości z zachowaniem powodu i historii. |
| REFUND | Oddanie rozliczonej wartości; pojęciowo odrębne od korekty należności. |
| LEDGER / TRANSACTION | Historia zdarzeń wartości, z której można odtworzyć saldo i przebieg. |

UDZ.io i Udziały korzystają docelowo z VALUE/POINTS/LEDGER, ale pełny model ekosystemowy należy do późniejszego etapu. UDZ.io nie daje prawa wypłaty w PLN. Historia musi przechowywać zastosowaną wartość, regułę i sposób rozliczenia.

### 9. FORM, dokument, zgoda, sprawa i zadanie

FORM jest definicją zbierania danych, a FORM RESPONSE osobnym wynikiem wykonania. Wersja formularza użyta do odpowiedzi musi być możliwa do odtworzenia.

DOCUMENT i CONSENT są powiązane, ale odrębne. Zgoda zapisuje świadomy akt wobec określonej treści i wersji; wycofanie nie usuwa wcześniejszych faktów biznesowych.

CASE/SPRAWA reprezentuje problem, wniosek, usterkę lub proces wymagający obsługi. TASK jest konkretną czynnością z odpowiedzialnym, terminem, statusem i historią; może być powiązany ze sprawą albo innym obiektem.

### 10. Konfiguracja ≠ wykonanie

A5 przyjmuje jako zasadę nadrzędną rozdzielenie przepisu od danych, które według niego powstały. Zmiana definicji nie może po cichu zmienić znaczenia historii.

| Definicja / konfiguracja | Dane wykonania |
|---|---|
| FORM | FORM RESPONSE |
| WORKFLOW | konkretna instancja procesu / SPRAWA |
| RULE i jej wersja | wynik zastosowania reguły / decyzja |
| typ biletu lub ENTITLEMENT | konkretne uprawnienie beneficjenta |
| szablon komunikatu | wysłana wiadomość i status dostarczenia |
| rodzaj OBLIGATION | zobowiązanie konkretnego podmiotu |
| PRICING POLICY | naliczona cena wraz z podstawą |
| scenariusz | uczestnicy, płatności, skany i pozostałe dane operacyjne |

Kopiowanie wydarzenia lub scenariusza kopiuje właściwą konfigurację, ale nie uczestników, płatności, użycia, obecności, starych zobowiązań ani historii operacyjnej.

### 11. Historia, audyt, MERGE i CLONE

Ważnych zdarzeń nie nadpisuje się bez śladu. Audyt zapisuje co najmniej ACTOR, czas, czynność, SUBJECT/kontekst, wynik oraz — gdy potrzebne — powód i wersję reguły.

MERGE jest funkcją platformy do scalania duplikatów kartotek z zachowaniem pochodzenia, historii i możliwością bezpiecznego naprawienia błędnego scalenia. Nie jest mechaniką biznesową scenariusza.

CLONE kopiuje definicję lub konfigurację w kontrolowanym zakresie. Nie kopiuje danych wykonania, chyba że jawnie wskazany, bezpieczny typ szablonu przewiduje inaczej.

### 12. SECURITY / PRIVACY CORE

Minimalna warstwa bezpieczeństwa i prywatności należy do fundamentu modelu. Późniejsze utwardzenie, monitoring i testy rozwijają ochronę, a nie dodają jej po raz pierwszy.

- Bezpieczne sesje, odzyskiwanie dostępu, ograniczenie prób i MFA dla administratorów.
- Centralne ROLE + PERMISSION + SCOPE/CONTEXT oraz negatywne testy uprawnień.
- Domyślna izolacja danych organizacji i scenariuszy.
- Klasyfikacja danych, np. PUBLIC, INTERNAL, RESTRICTED, SECRET i SPECIAL CATEGORY.
- Audyt odczytu i zmian w zakresie adekwatnym do ryzyka.
- Retencja, anonimizacja i usuwanie bez niszczenia wymaganej historii rozliczeniowej i raportowej.
- Szyfrowanie transportu i danych wrażliwych, zarządzanie sekretami oraz bezpieczne API.

Scenariusz określa klasyfikację konkretnych danych, a Core wymusza odpowiadające jej polityki. Przykładowo członkostwo polityczne może wymagać klasy SPECIAL CATEGORY, a tajny głos — SECRET.

### 13. Komunikacja, reguły i automatyzacje

COMMUNICATION obejmuje wiadomość, odbiorcę, kanał, szablon/treść, status i historię. Fizyczne dostarczenie SMS lub e-mail może realizować operator zewnętrzny.

Podstawowy wzorzec automatyzacji: TRIGGER → opcjonalny CONDITION/RULE → ACTION. Pełny silnik musi później uwzględnić ponowienia, idempotencję, harmonogram, eskalacje i audyt.

A5 nie wymaga natychmiastowego wdrożenia pełnego RULE/WORKFLOW. Wymaga natomiast, by obecna logika nie była zaszyta wyłącznie w ekranach i by zdarzenia mogły zostać odtworzone.

### 14. Raportowanie, import, eksport i integracje

REPORT DEFINITION opisuje sposób zestawienia danych. Wynik raportu co do zasady jest wyliczany z danych Core, a historyczny snapshot zapisuje się tylko wtedy, gdy wymaga tego scenariusz lub audyt.

Każdy eksport konfiguracji zawiera schema_version. Platforma obsługuje pełny eksport oraz pakiet zmian delta. Import podlega walidacji i migracji wersji bez kopiowania danych operacyjnych.

Integracje korzystają z warstwy usług/API i zdarzeń. UDZIO ma znać odpowiedzialność, kontekst, wynik i reguły, lecz nie musi odtwarzać pełnej księgowości, ERP/MRP, zaawansowanego magazynu, GPS ani każdego ciężkiego algorytmu branżowego.

## CZĘŚĆ II. GRANICA A5 WOBEC E5 I SCENARIUSZY

### 15. Katalog mechanik docelowego Core

Poniższy katalog wyznacza docelowe granice Core. Umieszczenie mechaniki na liście nie oznacza, że A5 nakazuje już jej pełną implementację. Projekt danych, cykl życia i kryteria mechanik zostaną zamrożone w E5.

| Mechanika | Granica znaczenia |
|---|---|
| VALUE / POINTS / LEDGER | Wartości, punkty, transakcje i odtwarzalne salda. |
| RULE | Warunek i skutek wraz z zakresem, czasem, priorytetem i wyjątkami. |
| WORKFLOW | Stany, przejścia, akceptacje i odpowiedzialność. |
| FORM | Wersjonowana definicja zbierania danych; odpowiedzi są wykonaniem. |
| RESERVATION / ASSIGNMENT | Rezerwacja i przydział, także zestawu kilku zasobów. |
| PRICING / BILLING POLICY | Reguły cen i opłat bez pełnej księgowości. |
| LIMIT / CAPACITY / QUOTA | Limity miejsc, osób, zasobów, mandatów i pul. |
| QUEUE / WAITLIST / PRIORITY | Kolejki i pierwszeństwo ustalane również przez RULE. |
| RECURRENCE / PERIOD | Cykle, okresy i powtarzalność. |
| RELATION / NETWORK | Typowane, kierunkowe relacje z kontekstem, czasem i historią. |
| OBLIGATION | Zobowiązanie lub należność, także wykonywane częściowo. |
| ENTITLEMENT | Prawo do świadczenia, odrębne od PERMISSION. |
| TASK | Konkretna czynność do wykonania przez osobę lub rolę. |
| ACTIVITY | Fakt wykonanej aktywności lub zdarzenie uruchamiające reguły. |
| DOCUMENT / CONSENT | Dokument i świadoma zgoda jako powiązane, lecz odrębne fakty. |
| VOTE | Głosowanie: uprawnieni, opcje, sposób liczenia i wynik. |
| ELECTION | Wybory: kandydowanie, okręg, mandat, uprawnienie wyborcze i wynik. |
| PROJECT | Organizacja działań wokół celu; CAMPAIGN może być konfiguracją PROJECT. |
| BUDGET | Lekki budżet i alokacja limitów, nie księgowość. |
| OFFER | Oferta, kontroferta, akceptacja, odrzucenie, wycofanie i wygaśnięcie. |

### 16. Czego nie tworzymy jako osobnego Core

AUCTION, przetarg, MLM_ENGINE, RENTAL, HOTEL, RESTAURANT, SCHOOL, SPORT, FRANCHISE, REAL_ESTATE, CAR_SERVICE, CROWDFUNDING, AFFILIATE, REWARD i PENALTY nie stają się osobnymi silnikami Core tylko z powodu nazwy branżowej. Powstają jako scenariusze, złożenia mechanik albo wyspecjalizowane moduły.

Nowa mechanika jest uzasadniona dopiero wtedy, gdy ma własne uniwersalne dane, reguły i cykl życia oraz powtarza się w niezależnych klasach problemów.

AGGREGATION/CALCULATION może zapewniać typowe liczenie po zbiorach, relacjach, filtrach i okresach. Bardzo ciężkie obliczenia mogą wykonywać moduły specjalistyczne przy zachowaniu wersji reguł, wejść i wyniku w UDZIO.

### 17. Niezmienne zasady modelu A5

| ID | Niezmienna zasada | Wymaganie modelowe |
|---|---|---|
| A5-01 | PERSON pozostaje niezależna od liczby kontekstów organizacyjnych. | Tożsamość nie jest duplikowana, a widoczność danych jest ograniczana przez SCOPE/CONTEXT. |
| A5-02 | ACCOUNT jest opcjonalną warstwą dostępu do PERSON. | ACCOUNT można powiązać później bez zmiany tożsamości PERSON i bez utraty jej historii. |
| A5-03 | ACTOR i SUBJECT są niezależnymi rolami każdej istotnej operacji. | Audyt zachowuje ACTOR-a i SUBJECT, a dane kontaktowe nie zastępują tożsamości PERSON. |
| A5-04 | Zmiana istotnego stanu zachowuje wykonawcę, przedmiot, powód oraz poprzednią i nową wartość. | Audyt pozwala odtworzyć przebieg operacji bez nadpisywania wcześniejszego stanu bez śladu. |
| A5-05 | RELATION i ROLE posiadają okres obowiązywania, status i historię. | Zmiana relacji nie usuwa jej wcześniejszego znaczenia ani okresu obowiązywania. |
| A5-06 | EVENT tworzy hierarchię o dowolnej potrzebnej liczbie elementów i głębokości. | Nazwy domenowe elementów hierarchii nie tworzą osobnych typów Core. |
| A5-07 | REGISTRATION, PARTICIPATION i ATTENDANCE są odrębnymi faktami. | Powstanie jednego z tych faktów nie wymusza sztucznego utworzenia pozostałych. |
| A5-08 | ENTITLEMENT może być ilościowe i wykorzystywane częściowo. | Niewykorzystana część prawa pozostaje odtwarzalna, a USAGE nie jest utożsamiane z ATTENDANCE. |
| A5-09 | RESERVATION może obejmować atomowo zestaw powiązanych zasobów. | Konflikt dowolnego wymaganego składnika jest rozpatrywany zgodnie z jedną polityką całego zestawu. |
| A5-10 | Role ekonomiczne i operacyjne są od siebie niezależne. | BUYER, PAYER, PARTICIPANT i BENEFICIARY mogą wskazywać te same albo różne PERSON lub ORGANIZATION. |
| A5-11 | Definicja i dane wykonania są rozdzielone oraz wersjonowane. | Każdy wynik wykonania wskazuje dokładną wersję definicji, według której powstał. |
| A5-12 | CLONE kopiuje wyłącznie jawnie wybrany zakres definicji lub konfiguracji. | Dane wykonania i historia operacyjna nie są kopiowane domyślnie. |
| A5-13 | MERGE łączy duplikaty bez utraty pochodzenia danych. | Scalenie nie nadpisuje pochodzenia i pozwala na kontrolowaną korektę błędnej decyzji. |
| A5-14 | Dostęp wymaga jednocześnie właściwego PERMISSION oraz SCOPE/CONTEXT. | Operacja poza zakresem jest odrzucana i audytowana adekwatnie do ryzyka. |
| A5-15 | Techniczny ACTOR integracji jest niezależny od biznesowego SUBJECT-u operacji. | Zdarzenie integracyjne zachowuje kontekst biznesowy, wynik i możliwość uruchomienia właściwej reguły. |
| A5-16 | Wynik wykonania wskazuje wersję zastosowanej reguły lub definicji. | Późniejsza zmiana reguły nie zmienia znaczenia wcześniej zapisanego wyniku. |
| A5-17 | Nazwa branżowa nie tworzy nowego typu Core bez własnego uniwersalnego modelu danych, reguł i cyklu życia. | Różne zastosowania korzystają ze wspólnych pojęć Core i własnych konfiguracji. |

### 18. Poza zakresem modelu A5

- Pełny projekt i implementacja wszystkich mechanik katalogu E5.
- Szczegółowe projekty bazy danych, API, ekranów i harmonogramów implementacji.
- Szczegółowe modele wspólnych pól, konfiguracji i zachowań należą do późniejszych specyfikacji.
- Gotowe konfiguracje i demonstracyjne scenariusze branżowe.
- Budowa pełnej księgowości, ERP/MRP, magazynu, telematyki lub dowolnie ciężkiego silnika obliczeniowego.
- Pełny silnik scenariuszy E6, struktury E7, automatyzacje E8 oraz ekosystem UDZ.io/Udziały późniejszych etapów.

### 19. Reguła dla dalszych specyfikacji

Każda implementacja oparta na A5 powinna realizować potrzebną funkcję użytkową przy użyciu pojęć i granic tego modelu.

Jeżeli przypadek można obsłużyć przez istniejące pojęcie lub mechanikę, nie tworzy się nowego bytu dla nazwy branżowej. Jeżeli ciężka praca jest specjalistyczna, projektuje się jawny kontrakt integracji lub modułu.

Kolejne specyfikacje rozwijają mechaniki, konfiguracje i warstwy wykonawcze. Nie mogą ponownie łączyć pojęć rozdzielonych w A5 ani tworzyć branżowych bytów Core bez wykazania ich uniwersalnego modelu danych, reguł i cyklu życia.

*Koniec dokumentu UDZIO Core A5*
