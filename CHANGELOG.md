# Changelog

## 0.3.0-dev (feature/pain001-soap-client)

### Added
- **BankConnectClient** — SOAP client skeleton for all CorporateService operations.
- **BankConnectCertificateManager** — keypair/CSR generation, AES-256-GCM private-key encryption.
- **Pain001Builder** — pain.001.001.03 for Danish account transfer and SEPA.
- **PaymentBatchService** — createBatch + sendBatch (stub or live via XmlSecurity).
- **BankConnectXmlSecurity** — encryptPayload / signRequest interface + stub mode;
  real crypto requires `robrichards/xmlseclibs` + bank/customer certs.
- **pages/payments.php** — UI to select unpaid supplier invoices → create/send batch.
- **SQL** — agreement, certificate, batch, batch_line tables.
- **Tests** — Pain001BuilderTest (12), PaymentBatchServiceTest (5) compatible with MockDoliDB.
- **Lang** — da_DK + en_US strings for payments UI.

### Notes
- Full XML-Signature / XML-Encryption still needs the official BankConnect
  developer package examples (`resources/xml/security/`).
- Branch: `feature/pain001-soap-client`.

## 0.2.0 (2026-09-19)

### Added
- **ImportService** — fælles import-flow for camt-filer med dedup-tælling.
- **BankConnectStore::upsertTransactionDetailed()**.
- **reconcile.php** — bruger ImportService; viser dubletter.
- **Tests** — ImportServiceTest (3 cases). 30/30 grønne.

### Changed
- **CamtParser** — ensartet RuntimeException ved tom/malformert XML.
- **Sprogfiler** — import-besked med dublet-tælling.

## 0.1.1 (2026-09-19)

### Fixed
- MockDoliDB regex-backtracking, fetch_object by-reference, bare rowid WHERE.
- ApprovalPosting guard-rækkefølge + $n init.

### Tests
- 27/27 grønne, CI success.

## 0.1.0

- CamtParser, ReconciliationEngine + Mistral fallback, godkendelse før bokføring.
