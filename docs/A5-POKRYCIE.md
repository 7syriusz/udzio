# Pokrycie kryteriów A5

Kryteria akceptacji z [E1E2E3A5Skalik §17](specifications/E1E2E3A5Skalik.md) i testy, które je sprawdzają.
Uzupełniane przy zamknięciu każdego etapu.

| Kryterium | Stan | Gdzie | Testy |
|---|---|---|---|
| A5-01 PERSON niezależna od kontekstów | E2: gotowe; widoczność przez SCOPE w E3 | `Person` (bez kolumny organizacji), relacje wskazują osobę | `E2AcceptanceTest`, `PersonTest` |
| A5-02 ACCOUNT opcjonalny, dołączany później | E2: gotowe | `LinkAccountToPerson`, `ResolveAccountPerson` (po weryfikacji e-maila) | `E2AcceptanceTest`, `AccountTest`, `AccountPersonLinkTest` |
| A5-03 ACTOR ≠ SUBJECT | E2: gotowe (reprezentacja, kontakt ≠ osoba) | `ActorContext`, `RecordAudit`, `ActOnBehalf`, `Contact` | `E1AcceptanceTest`, `E2AcceptanceTest`, `RepresentationTest`, `ContactTest` |
| A5-04 Wykonawca, przedmiot, powód, przed/po | E1: gotowe | `AuditsChanges`, `AuditReason`, klasyfikacja (E1.7) | `E1AcceptanceTest`, `AuditsChangesTest`, `DataClassificationTest` |
| A5-05 Relacja z okresem, statusem i historią | E1: wzorzec gotowy; ROLE w E3 | `HasValidityPeriod`, `ValidityColumns` | `E1AcceptanceTest`, `HasValidityPeriodTest`, `ValidityPeriodConcurrencyTest` |
| A5-11 Definicja i wykonanie rozdzielone, wersjonowane | E1: wzorzec gotowy | `HasVersions`, `RecordsDefinitionVersion`, `definition_versions` | `E1AcceptanceTest`, `DefinitionVersioningTest`, `DefinitionVersionConcurrencyTest` |
| A5-14 Odmowa poza zakresem audytowana | E1: audyt odmów gotowy; PERMISSION + SCOPE w E3 | `AccessDenied`, `AuditAccessDenials`, `RecordAccessDenial` | `AccessDenialAuditTest`, `AccessDenialRollbackTest` |
| A5-15 Techniczny ACTOR integracji ≠ SUBJECT | E1: kontekst gotowy; zdarzenia integracyjne później | `Actor::integration`, `ActorContext::runAs` | `E1AcceptanceTest`, `ActorContextTest` |
| A5-16 Wynik wskazuje wersję reguły | E1: gotowe | jak A5-11 | `E1AcceptanceTest`, `DefinitionVersioningTest` |
| A5-17 Nazwa branżowa nie tworzy typu Core | E0: sprawdzane automatycznie | `ArchitectureTest::MODULES` | `ArchitectureTest` |

Pozostałe kryteria (A5-06–10, 12, 13) należą do kolejnych etapów — patrz [PLAN-ETAPOW.md](PLAN-ETAPOW.md).
