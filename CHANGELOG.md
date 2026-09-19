# Changelog

## 0.2.0 (2026-09-19)

### Added
- **ImportService** — fælles import-flow for camt-filer: `import($xml)` og
  `importFile($path)` returnerer nu præcise tællinger: `imported`, `duplicates`
  og `total`. Dedup på hash (dato, beløb, reference, modpart).
- **BankConnectStore::upsertTransactionDetailed()** — returnerer `rowid` +
  `duplicate`-flag. Gammel `upsertTransaction` delegerer for kompatibilitet.
- **reconcile.php** — manuel filupload bruger nu `ImportService`; feedback viser
  dubletter i UI'et.
- **Tests** — `ImportServiceTest` med 3 cases (nyt indhold, dedup-hit, blandet).
  30/30 grønne, 89 assertions.

### Changed
- **CamtParser** — ensartet exception-kontrakt: tom/malformert XML kaster nu
  altid `RuntimeException` (tidligere blanding med `InvalidArgumentException`).
- **Sprogfiler** — import-besked har nu plads til dublet-tælling (da_DK + en_US).
## 0.1.1 (2026-09-19)

### Fixed
- **MockDoliDB: katastrofal regex-backtracking** i WHERE-parseren på LIKE-forespørgsler
  forårsagede 2 GB memory-allokering (fatal). Erstattet med string-splitting på
  `ORDER BY`/`LIMIT`. (b14c3a5)
- **MockDoliDB: uendelig løkke** i `fetch_object` — resultatet blev taget *by value*,
  så `array_shift` aldrig udtømte det. Nu by-reference. (dc82277-familien)
- **MockDoliDB: `evalWhere` understøtter nu nøgne rowid-where** (uden `=`), f.eks.
  `WHERE rowid` alene. Tidligere matchede disse aldrig.
- **ApprovalPosting: guard-rækkefølge** — `state` tjekkes nu før `approved_by`, så
  rejected matcher giver korrekt fejlbesked i stedet for null-check-fejlen.
- **ApprovalPosting: `$n` initialiseret** i `postAllApproved` — var udefineret ved
  tom liste (null-coalescing).

### Tests
- 27/27 grønne (84 assertions), PHP 8.3 / PHPUnit 10.5, CI success (run 1122518).

## 0.1.0

- Første release: CamtParser (camt.053/054), ReconciliationEngine med Mistral AI-fallback,
  godkendelse før bokføring.
