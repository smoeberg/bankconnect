# Changelog

## 0.3.0-dev (feature/pain001-soap-client)

### Added
- **BankConnectClient** — SOAP client skeleton for all CorporateService operations
  (getBankCertificate, activateServiceAgreement, renewCustomerCertificate,
  transferPayments, getStatus, getCustomerStatement, getCustomerAccountReport,
  getDebitCreditNotification, getAlternate).
- **BankConnectCertificateManager** — keypair/CSR generation, AES-256-GCM
  private-key encryption, activate + renew stubs.
- **Pain001Builder** — builds pain.001.001.03 for Danish account transfer and
  SEPA Credit Transfer (MsgId, PmtInf, CdtTrfTxInf).
- **PaymentBatchService** — createBatch (persist header + lines) + sendBatch
  (stub until signature/encryption is wired).
- **SQL** — `llx_bankconnect_agreement`, `llx_bankconnect_certificate`,
  `llx_bankconnect_batch`, `llx_bankconnect_batch_line`.
- **Tests** — `Pain001BuilderTest` (12 cases: MsgId, CtrlSum, XML structure,
  SEPA/DK service level, validation) and `PaymentBatchServiceTest` (create,
  send stub, status guards).

### Notes
- This is the foundation for phase A (outgoing payments).
- XML-Signature / XML-Encryption and full ActivateServiceAgreement payload
  still to be completed against the official BankConnect developer package.
- Branch: `feature/pain001-soap-client`.

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
