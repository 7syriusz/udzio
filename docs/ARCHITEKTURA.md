# Architektura i konwencje

Specyfikacja nadrzędna: [E1E2E3A5Skalik](specifications/E1E2E3A5Skalik.md). Założenia: [ZALOZENIA.md](ZALOZENIA.md).

## 1. Jedna aplikacja, moduły domenowe

Udzio jest jedną aplikacją Laravel podzieloną na moduły odpowiadające pojęciom A5. Kod modułu leży w `app/Domain/<Moduł>/`:

| Moduł | Pojęcia A5 | Etap |
|---|---|---|
| `Platform` | ACTOR/SUBJECT, audyt, relacje w czasie, wersjonowanie, klasyfikacja danych, idempotencja | E1 |
| `Identity` | PERSON, ACCOUNT, CONTACT, reprezentacja | E2 |
| `Organization` | ORGANIZATION, struktura, członkostwo, ROLE, PERMISSION, SCOPE | E3 |
| `Event` | EVENT, program, GROUP | E4 |
| `Participation` | REGISTRATION, PARTICIPATION, ENTITLEMENT, USAGE, IDENTIFIER, ATTENDANCE | E5–E6 |
| `Resource` | RESOURCE, LAYOUT, RESERVATION, ASSIGNMENT | E7 |
| `Finance` | OFFERING, ORDER, OBLIGATION, PAYMENT, ALLOCATION, ADJUSTMENT, REFUND, LEDGER | E8 |
| `Form` | FORM, FORM RESPONSE, DOCUMENT, CONSENT | E9 |
| `Communication`, `Reporting` | COMMUNICATION, REPORT DEFINITION | E10 |

Katalog modułu powstaje wraz z pierwszym kodem modułu. Nazwa branżowa nie tworzy modułu (A5-17).

**Układ modułu:**
- `Models/` — modele Eloquent;
- `Actions/` — operacje biznesowe (jedna klasa = jedna operacja, np. `RegisterPerson`);
- `Policies/`, `Enums/`, `Events/`.

**Przepływ:**
- Kontrolery w `app/Http` są cienkie: walidują żądanie i wywołują akcje modułu. Logika nie siedzi w ekranach ani kontrolerach (A5 §13).
- Moduł korzysta z innego modułu przez jego akcje i modele. Nie zapisuje bezpośrednio do cudzych tabel.

## 2. Konwencje danych

| Obszar | Konwencja |
|---|---|
| Klucze | Wewnętrznie `bigint` auto-increment. Obiekty widoczne na zewnątrz (URL, QR, API) mają dodatkowo publiczny identyfikator ULID — nigdy sekwencyjne `id` w adresie. |
| Kwoty | Liczby całkowite w najmniejszej jednostce (`*_minor`, np. grosze) + kod waluty ISO 4217. Nigdy `float`/`double`. |
| Czas | UTC w bazie i w aplikacji (Z-004). Prezentacja w strefie użytkownika. |
| Statusy i typy | PHP `enum` z wartością tekstową, w bazie `string` (bez `ENUM` MySQL). Nowa wartość nie wymaga migracji schematu. |
| Usuwanie | Fakty biznesowe nie są kasowane ani nadpisywane bez śladu (A5 §11). Zmiana stanu to nowy status albo nowy wpis historii. `SoftDeletes` tylko tam, gdzie wynika to z modelu. |
| Klucze obce | Domyślnie `restrictOnDelete` dla danych z historią; `cascade` tylko dla danych technicznych. |
| Nazewnictwo | Kod, tabele i kolumny po angielsku, nazwy pojęć zgodne z A5 (`person`, `registration`, `entitlement`). Interfejs i komunikaty po polsku. |
| Konfiguracja ≠ wykonanie | Definicje wersjonowane; każdy wynik wskazuje wersję definicji (A5-11, A5-16). Wzorzec powstaje w E1. |

## 3. Testy

- Testy działają wyłącznie na MySQL 8.4 i bazie `*_test`. Pilnują tego dwa strażniki (Z-002).
- Operacje ilościowe, rezerwacje, użycia i płatności mają testy współbieżności: `tests/Support/Concurrency/Race.php`, grupa `concurrency`.
- Każda operacja wymagająca uprawnień ma test pozytywny i negatywny (obca organizacja, brak uprawnienia) — A5 §12.
- Test architektury (`tests/Unit/ArchitectureTest.php`) sprawdza konwencje, które da się sprawdzić automatycznie.

## 4. Automatycznie sprawdzane reguły

| Reguła | Dlaczego |
|---|---|
| Brak `env()` poza `config/` | Zmienne środowiskowe czytane przez konfigurację (działa cache konfiguracji na produkcji). |
| Brak `dd`, `dump`, `var_dump`, `ray` w `app/` | Kod diagnostyczny nie trafia na produkcję. |
| Migracje bez `float()` / `double()` | Kwoty jako liczby całkowite (sekcja 2). |
| Brak `enum()` w migracjach | Statusy jako tekst + PHP `enum`. |
| Kod domenowy tylko w `app/Domain/<Moduł>/` z nazwą z listy modułów | Pilnuje granic A5 i zakazu modułów branżowych. |

## 5. Kontekst ACTOR (E1.1)

Wstrzykuj `App\Domain\Platform\ActorContext` i odczytuj `current()` w chwili operacji.
`Actor` jest niezmiennym obiektem wartości. `runAs(Actor::integration('identyfikator'), fn () => ...)`
ogranicza jawnego wykonawcę do jednego bloku i przywraca poprzedni kontekst również przy wyjątku.
Nie używaj danych wejściowych klienta do ustawienia wykonawcy. Kontekst nie jest mechanizmem autoryzacji
ani reprezentacji i nigdy nie wyznacza automatycznie SUBJECT-u.

`ResolveActor` otacza HTTP; `PlatformServiceProvider` obsługuje zdarzenia początku/końca komendy i próby
zadania. Powiązanie `scoped` zapobiega utrzymaniu kontekstu po resecie zakresu pracownika.
Metody `enter`/`leave` służą infrastrukturze cyklu życia; kod biznesowy używa `runAs`.
Zasada wykonawcy technicznego dla kolejki: Z-011.

## 6. Dopisywanie audytu (E1.2)

Wstrzykuj `App\Domain\Platform\Actions\RecordAudit`. `handle` przyjmuje kod czynności,
`subjectType`, `subjectId`, wynik oraz opcjonalnie organizację, powód i korelację. Zapis jest synchroniczny;
operacja biznesowa obejmuje go swoją transakcją. Nie wywołuj audytu po commit dla faktu, który ma być atomowy
z zapisem biznesowym. Historia nie zawiera relacji kasowanych kaskadowo ani `updated_at`.

Dopisywanie korekty tworzy nowy fakt, nie zmienia starego. Rejestrowanie odmów po wycofaniu transakcji
należy do E1.4; automatyczne wartości przed/po i redakcja do E1.3/E1.7. W E1.2 nie zapisujemy dowolnego
payloadu żądania. Szczegóły uprawnień migracji, granica ochrony SQL i wycofanie: Z-012.

## 7. Audyt zmian instancji modelu (E1.3)

Model korzystający z `AuditsChanges` deklaruje `auditSubjectType`, `auditOrganizationId`
i klasyfikację pól `dataClassification()` (od E1.7, §11). Powód zmiany obejmuje cały blok operacji:

```php
$reason->because('user requested profile correction', fn () => $user->update($validated));
```

`AuditReason` wstrzykuj do akcji. Nie wpisuj sekretów do tekstu powodu. Kontekst wykonawcy pochodzi
z E1.1. Audytowane modele korzystają z domyślnego połączenia biznesowego. Zapis audytu jest częścią
transakcji modelu, a zewnętrzna transakcja może wycofać całą operację. Nie używaj masowych zapisów
omijających instancję modelu bez jawnego audytu na tym samym połączeniu. Zakres i testy: Z-013.

## 8. Audyt odmów i odczytów danych chronionych (E1.4)

Każda odmowa HTTP jest audytowana automatycznie (`App\Http\Exceptions\AuditAccessDenials`, podpięte w
`bootstrap/app.php`): odpowiedź 403 oraz odmowa autoryzacji renderowana jako 404. Klient dostaje zwykłą
odpowiedź. Błąd samego zapisu audytu jest logowany i nigdy nie zmienia 403/404 na 500.

Moduły odmawiają dostępu przez `App\Domain\Platform\Exceptions\AccessDenied`, podając SUBJECT
(`subjectType`, `subjectId`), organizację i zdolność. Dla rekordów obcej organizacji użyj
`->hideAsNotFound()`: klient widzi 404, a audyt zapisuje odmowę (A5-14).

Poza HTTP (komendy, kolejki) wywołuj jawnie `RecordAccessDenial::handle`. Odczyt danych chronionych zapisuje
`RecordProtectedRead::handle($subjectType, $subjectId, $fields, $organizationId, $purpose)` — tylko nazwy pól,
nigdy wartości. O tym, które pola tego wymagają, zdecyduje klasyfikacja danych (E1.7).

Oba zapisy idą osobnym połączeniem `audit` (ta sama baza), więc przetrwają wycofanie transakcji biznesowej.
Limit: 20 wpisów odmowy na minutę dla pary wykonawca–cel. Testy, które wywołują odmowę HTTP, muszą używać
transakcji testowej (`LazilyRefreshDatabase`) albo sprzątać po sobie. Szczegóły: Z-014.

## 9. Relacje w czasie (E1.5)

Relacja obowiązująca w okresie (członkostwo, reprezentacja, funkcja) to model z traitem
`App\Domain\Platform\Concerns\HasValidityPeriod`. Migracja dodaje kolumny przez
`ValidityColumns::add($table, ['person_id', 'organization_id'])`, a model zwraca te same kolumny w `validityKey()`.

```php
$membership = Membership::startPeriod([...], $from);                  // nowy okres, bez nakładania
$reason->because('elected treasurer', fn () => $membership->transition(RelationStatus::Active, $at, ['function' => 'treasurer']));
Membership::query()->where($key)->activeAt($day)->exists();          // stan na dzień
```

Nie zmieniaj `valid_from`, `valid_to` ani klucza bezpośrednio. Zamknięte okresy są tylko do odczytu.
Konflikt (nakładanie, drugi otwarty okres, przejście przed początkiem) zgłasza `ValidityConflict`. Szczegóły: Z-015.

## 10. Definicja → wersja → wynik (E1.6)

Definicja (formularz, reguła, typ biletu, polityka cen) używa `HasVersions` i `AuditsChanges`, a w
`versionSnapshot()` zwraca pełną treść decydującą o wyniku. Wynik używa `RecordsDefinitionVersion`
i ma kolumnę `definition_version_id` (klucz obcy do `definition_versions`, `restrictOnDelete`).

```php
$version = $form->publishVersion();                                  // zamrożona kopia, numer kolejny
FormResponse::create(['definition_version_id' => $version->id, ...]); // wynik według dokładnej wersji
$response->definitionVersion->content;                              // interpretacja zawsze przez wersję
```

Nie czytaj bieżącego szkicu definicji, żeby zinterpretować stary wynik. Szczegóły: Z-016.

## 11. Klasyfikacja danych (E1.7)

Każdy model audytowany implementuje `App\Domain\Platform\Classification\ClassifiesData`:
`dataClassification()` zwraca `pole => DataClass` (PUBLIC, INTERNAL, RESTRICTED, SECRET, SPECIAL CATEGORY).
Zapis pola bez klasy jest odrzucany (poza kluczem i znacznikami czasu). O skutkach klasy decyduje
`config/data_classification.php`, nie model:

| Klasa | Wartość w audycie zmian | Audyt odczytu | Eksport | Retencja | Po retencji |
|---|---|---|---|---|---|
| PUBLIC, INTERNAL | tak | nie | wartość | dopóki istnieje rekord | zostaje |
| RESTRICTED | `[REDACTED]` | nie | wartość | 730 dni | anonimizacja |
| SPECIAL CATEGORY | `[REDACTED]` | tak | `[REDACTED]` | 365 dni | usunięcie |
| SECRET | `[REDACTED]` | tak | pominięte | do końca celu | usunięcie |

Eksport przepuszcza wartości przez `ClassifiedData::forExport($model, $values)`. Po wyświetleniu pól
wywołaj `RecordProtectedRead::forModel($model, $fields, $cel)` — zapisze odczyt, jeśli klasa tego wymaga.
Scenariusz może podnieść klasę pola (np. członkostwo polityczne → SPECIAL CATEGORY). Szczegóły: Z-017.

## 12. Idempotencja (E1.8)

Operacje, które klient może ponowić (rejestracja, zamówienie, płatność, import), uruchamiaj przez
`App\Domain\Platform\Actions\RunIdempotently`:

```php
$outcome = $idempotent->handle('registration.create', $request->header('Idempotency-Key'), $validated,
    fn () => ['registration' => $createRegistration->handle($validated)->public_id]);
$outcome->value;     // ten sam wynik przy każdym ponowieniu
$outcome->replayed;  // true, gdy operacja nie była wykonana ponownie
```

Wywołuj ją poza własną transakcją (ponawia się po zakleszczeniu). Zwracaj wynik zgodny z JSON, najlepiej
publiczne identyfikatory. Szczegóły: Z-018.

## 13. Lista kontrolna nowego modelu domenowego (po E1)

1. Model biznesowy: `AuditsChanges` + `ClassifiesData` — każde pole sklasyfikowane (§7, §11).
2. Zmiany w akcjach pod `AuditReason::because(...)`; wykonawca pochodzi z `ActorContext` (§5).
3. Relacja obowiązująca w czasie: `HasValidityPeriod` + `ValidityColumns` (§9).
4. Konfiguracja, według której powstają wyniki: `HasVersions`; wynik: `RecordsDefinitionVersion` (§10).
5. Operacja ponawiana przez klienta: `RunIdempotently` (§12).
6. Odmowa dostępu: `AccessDenied` (dla obcej organizacji `->hideAsNotFound()`); odczyt danych chronionych:
   `RecordProtectedRead::forModel` (§8).
7. Operacja ilościowa lub na wspólnym zasobie: test współbieżności `Race` (§3).
8. Kryterium A5: wpis w [A5-POKRYCIE.md](A5-POKRYCIE.md).

## 14. Tożsamość (E2, moduł `Identity`)

| Pojęcie A5 | Kod | Uwagi |
|---|---|---|
| PERSON | `Identity\Models\Person`, `RegisterPerson`, `UpdatePersonDetails` | globalna, publiczny ULID; bez automatycznego dopasowania (Z-019) |
| CONTACT | `Contact`, `AddContact`, `RemoveContact`, `RequestContactVerification`, `VerifyContact`, kontrakt `ContactCodeSender` | kanał należący do osoby; nie łączy osób; kanał bez dostawcy nie jest weryfikowalny (Z-020) |
| ACCOUNT | `App\Models\User` + Fortify, `LinkAccountToPerson`, `ResolveAccountPerson`, `PersonLinkReview`, `ResolvePersonLinkReview` | konto dołącza do PERSON po weryfikacji e-maila; niejednoznaczność → procedura naprawcza (Z-021, Z-022) |
| REPRESENTATION | `Representation`, `RepresentationRules`, `GrantRepresentation`, `ChangeRepresentationScopes`, `EndRepresentation`, `ActOnBehalf` | relacja w czasie z zakresem, sposobem ustanowienia, podstawą i ACTOR-em; bez typu „rodzic–dziecko” (Z-025) |

Operacja dotycząca innej osoby: `ActOnBehalf::handle($account, $subject, RepresentationScope::…, fn () => …)`.
Zasoby cudzych osób na ekranach: `AccessDenied(...)->hideAsNotFound()`. Zapisy konta wykonywane przez Fortify
i framework mają jawne powody (audytowane podklasy akcji, `AuditedUserProvider`). Middleware `mfa` wymaga
potwierdzonego MFA — dla tras administracyjnych od E3.

## 15. Baza danych: brak danych przykładowych i ochrona przed skasowaniem

`DatabaseSeeder` jest pusty — nie dodawaj seederów demonstracyjnych. Polecenia kasujące bazę
(`migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`) działają tylko lokalnie
lub w testach i tylko na bazach `*_test` albo jawnie wskazanej w `DB_ALLOW_DESTRUCTIVE_ON`
(`DestructiveCommandGuard`, Z-027).

## 16. Organizacja (E3.1, moduł `Organization`)

`Organization` jest globalnym obiektem odrębnym od PERSON (A5 §2.1), identyfikowanym publicznie przez
niezmienny ULID. `OrganizationStatus`: `active` / `archived`. `CreateOrganization`, `RenameOrganization`
i `ArchiveOrganization` są punktami zapisu; korzystają z `AuditsChanges`, `AuditReason` i klasyfikacji pól.
Zmiany istniejącego rekordu są serializowane blokadą w transakcji, a powód trafia do audytu.
Historia wskazuje organizację przez jej ULID. Archiwizacja nie usuwa rekordu; powtórzenie jest bezskutkowe.

Nie ma jeszcze tras organizacji ani automatycznej roli założyciela: członkostwo, role, izolacja i ekrany
mają osobne podetapy E3. Hierarchia jest zakresem E3.2. Założenia i granice zabezpieczeń: Z-028.
Testy: `tests/Feature/Domain/Organization/OrganizationTest.php` (MySQL, również blokada DELETE i wycofanie
zmian po awarii audytu). Fabryka służy wyłącznie testom; migracja nie dodaje danych przykładowych.

## 17. Struktura organizacji (E3.2)

`OrganizationParent` to relacja rodzic–jednostka z `HasValidityPeriod`, `AuditsChanges` i publicznym ULID
każdego okresu. `MoveOrganization::handle($organization, $parentOrNull, $reason)` kontroluje aktywność,
cykle, blokady i atomowe zamknięcie/otwarcie relacji. `null` odłącza jednostkę jako korzeń.
`OrganizationHierarchy::ancestorsAt` zwraca przodków od najbliższego, a `descendantsAt` potomków poziomami;
obie operacje przyjmują moment i nie wywodzą z drzewa uprawnień. Nie filtrują historii przez aktualny status.

Dostęp do historii: `OrganizationParent::where('organization_id', $id)->orderBy('valid_from')->get()`.
Odczyt relacji w chwili: `activeAt($moment)`. Nazwy są aktualne; dawną nazwę odtwarza audyt E3.1.
Nie zapisuj struktury z pominięciem `MoveOrganization`. Zasady czasu, serializacji i wycofania: Z-029.
Testy: `OrganizationHierarchyTest` i `OrganizationHierarchyConcurrencyTest` (dwa procesy na MySQL).

## 18. Członkostwo (E3.3)

`Membership` (PERSON → ORGANIZATION) to relacja w czasie z funkcją i statusem. Zmieniaj ją wyłącznie akcjami
`AdmitMember`, `ChangeMembership`, `TransferMembership`, `EndMembership` — blokują osobę i pilnują aktywności
organizacji. Członkostwo nie nadaje uprawnień; do dostępu służą role (E3.4+). Szczegóły: Z-030.

## 19. Uprawnienia i role dostępowe (E3.4)

Nowa operacja wymagająca kontroli dostępu dostaje wartość w `Organization\Enums\Permission` (nazwa operacji,
nie branży). Role (`AccessRole`) są danymi organizacji — twórz i zmieniaj je akcjami `CreateAccessRole`,
`UpdateAccessRole`, `RetireAccessRole`. `AccessRole::grants()` zwraca `false` dla roli wycofanej. Samo
posiadanie roli nie wystarczy do dostępu — decyzję podejmie silnik E3.6 (uprawnienie + SCOPE). Szczegóły: Z-031.

