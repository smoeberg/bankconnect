## 0.7.0 (unreleased)

### Added
- Direction-aware `DolibarrCandidateProvider` backed by Dolibarr's native
  customer invoices, supplier invoices, unlinked invoice payments and various
  payments.
- Remaining invoice amounts are calculated from Dolibarr payment-relation
  tables in company or invoice currency.
- Currency and entity boundaries are enforced before candidates reach the
  matching engine.

### Fixed
- The reconciliation action now constructs `BankTransaction` objects from
  stored rows and invokes `ReconciliationService` with real Dolibarr
  candidates instead of calling the engine without candidates.

## 0.6.0 (unreleased)

### Added
- Hourly, opt-in Dolibarr scheduled job for automatic BankConnect statement
  retrieval.
- Agreement-scoped client factory loading the active customer certificate and
  decrypted private key.
- Strict SOAP response parser for direct, base64 and gzip CAMT payloads.
- Automatic imports reuse the same `ImportService` and `Account::addline()`
  path as manual CAMT uploads.

### Fixed
- Support Dolibarr's object-shaped `$conf->global` in the BankConnect client
  and XML security boundary.

## 0.5.0 (unreleased)

### Added
- `DolibarrBankEntryService` creates imported movements through Dolibarr's
  `Account::addline()` domain API.
- Sidecar-to-`llx_bank` linkage with explicit pending/creating/linked/error
  states and an idempotency marker for crash-safe retries.
- Direct link from the reconciliation screen to the standard Dolibarr bank
  entry.

## 0.4.0 (2026-09-21)

### Added
- **PaymentStateMachine** — eksplicit batch-livscyklus med idempotens-nøgle.
- **UNKNOWN payment recovery** — sikker håndtering af ukendte betalinger.
- **Pain.002 status-livscyklus** — verbatim bankstatus + semantisk mapping.
- **CAMT import-livscyklus** — identitetsgrænser, dedup, provenance end-to-end.
- **BankConnectSecretStore** — secrets udelukkende fra miljøvariabler (fail-closed).
- **BankConnectEndpointPolicy** — endpoint-whitelisting, fail-closed.
- **BankAccountMappingStore** — atomisk bankkonto-mapping.
- **ReconciliationService confidence-audit** — sporbar confidence-grænse.
- **Certificate lifecycle hardening** — atomisk renewal, per-agreement lock.

### Security
- TransferPayment wire security aligned med developer package v3.7.
- Response decryption boundary verificeret før parsing.
- Log-redaction: ingen secrets/PII i logs.

## Unreleased

### Security
- Align TransferPayment transport signatures with developer package v3.7:
  `wsu:Id` references, exclusive C14N, `wsse:Security`, customer
  `BinarySecurityToken` and `SecurityTokenReference`.
- Apply the documented Bankdata/NBS versus BEC signing/encryption order.
- Reject unknown datacenter configuration before sending a payment.
- Remove the obsolete proprietary payload-package description; active XML
  Encryption uses the official AES-256-CBC IV+ciphertext representation.

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
