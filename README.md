# BankConnect — Dolibarr-modul til dansk bankafstemning og betalinger

AI-understøttet bankafstemning for Dolibarr: automatisk match af bankposter
mod åbne fakturaer og lønposter, med Mistral AI som fallback, når
regelbaseret matching ikke er sikker nok. Plus pain.001-betalingsbatches
via BankConnect SOAP (v3.7), camt-import, certifikat-håndtering og
en brugerflade til godkendelse. Den endelige overførsel til finans følger
Dolibarrs normale bank- og finanskladdeflow.

## Arkitektur

```text
BankConnect -> import/dedup -> Dolibarr-bankkonto og bankpost
                                      |
                                      v
                          matchforslag og godkendelse
                                      |
                                      v
                 Dolibarr standard bankfinanskladde -> finans
```

Modulets egne tabeller er sidecar-data til BankConnect-identitet, importstatus,
matchforslag og audit. De må ikke fungere som en parallel bankbog. `llx_bank`
er Dolibarrs bankpost, og `llx_accounting_bookkeeping` opdateres af Dolibarrs
standardflow.

- **Rule layer**: payment reference -> beløb -> datovindue. Confidence 0.5-0.98.
- **AI fallback**: Mistral (cloud eller self-hosted) modtager kun saniterede
  data (CPR/regex-fjernet), returnerer altid gyldigt JSON eller `none` -
  aldrig exceptions til kalderen.
- **MatchResult**: `exact | partial | multiple | none` + confidence + forslag.
- **Payment state machine**: batch livscyklus med idempotens-nøgle,
  ukendte betalinger recoveres sikkert, NONE kan aldrig blive et forslag.
- **Fail-closed**: ukendt datacenter, manglende secrets og ugyldige
  endpoints afvises før noget sendes.

## Klasser

| Klasse | Ansvar |
|---|---|
| `CamtParser` | camt.053/054 XML -> `BankTransaction[]` |
| `ReconciliationEngine` | Regler + AI-koordinering, batch |
| `MistralMatcher` | AI-matching, PII-sanitering, logging |
| `MatchResult` | DTO: match_type, confidence, suggested, reason, source |
| `ApprovalPosting` | Legacy-prototype; ikke eksponeret eller medtaget i installations-ZIP |
| `ImportService` | Fælles camt-import-flow med dedup-tælling |
| `ReconciliationService` | Orchestrering + confidence-audit |
| `PaymentBatchService` | Batch-livscyklus, idempotens, recover |
| `PaymentStateMachine` | Eksplicit batch-status-livscyklus |
| `Pain001Builder` | pain.001.001.03 (DK + SEPA) |
| `Pain002Parser` | pain.002 status-parser (verbatim bankstatus + semantisk mapping) |
| `ServiceHeaderBuilder` | SOAP serviceHeader |
| `BankConnectClient` | SOAP-klient for CorporateService |
| `BankConnectXmlSecurity` | XML-signering/kryptering (v3.7 rækkefølge) |
| `BankConnectResponseSecurity` | Verificeret respons-dekrypteringsgrænse |
| `BankConnectCertificateManager` | Keypair/CSR, AES-256-GCM nøglekryptering |
| `AgreementStore` | Bank-aftaler (datacenter, certifikater) |
| `BankConnectStore` | Transaktioner, upsert, batches |
| `BankConnectSecretStore` | Secrets fra miljøvariabler (fail-closed) |
| `BankConnectEndpointPolicy` | Endpoint-whitelisting/fail-closed |
| `BankConnectHealth` | Health/status-sjekk |
| `BankConnectLogger` | PII-safe logging (hash, metrics - ingen tekst) |
| `BankAccountMappingStore` | Atomisk bankkonto -> Dolibarr-konto-mapping |

## Modulstruktur

```
htdocs/custom/bankconnect/
├── admin/          bankconnect.php, certificates.php
├── class/          alle klasser ovenfor
├── core/modules/   modulbeskrivelse
├── langs/          da_DK, en_US
├── pages/          reconcile.php, payments.php
└── sql/            llx_bankconnect_{transaction,payment,account_mapping}.sql

tests/unit/         PHPUnit-tests + MockDoliDB
.github/workflows/  CI: php -l + unit tests
scripts/            byg installerbar Dolibarr-ZIP
```

## Installation

```bash
./scripts/build-module-package.sh
```

Upload derefter `dist/module_bankconnect-<version>.zip` via Dolibarrs side til
installation af eksterne moduler, og aktivér **BankConnect** under
**Moduler/Applikationer**. Se [INSTALL.md](INSTALL.md) for krav og detaljer.

Automatisk import installeres som et deaktiveret Dolibarr-schedulerjob med
interval på én time. Når BankConnect-aftale, aktivt certifikat og bankkonto-
mapping er konfigureret, aktiveres jobbet **Hent BankConnect-kontoudtog** under
Dolibarrs planlagte jobs. Det automatiske flow bruger samme importservice som
manuel CAMT-upload og er derfor underlagt samme dubletbeskyttelse.

## Opsætning

```php
// dolibarr/conf.php
$conf->global['BANKCONNECT_AI_ENABLED'] = 1;
$conf->global['BANKCONNECT_MISTRAL_API_KEY'] = '...';
$conf->global['BANKCONNECT_MISTRAL_MODEL'] = 'mistral-small-latest';
$conf->global['BANKCONNECT_AI_TEMPERATURE'] = 0.1;   // konservativ
$conf->global['BANKCONNECT_AI_TIMEOUT'] = 8;         // sekunder
$conf->global['BANKCONNECT_RULE_DATE_WINDOW'] = 30;  // dage
$conf->global['BANKCONNECT_RULE_AMOUNT_TOLERANCE'] = 0.05;

// Secrets: sæt som miljøvariabler (IKKE i conf.php) - fail-closed
// BANKCONNECT_SECRET_KEY, BANKCONNECT_MISTRAL_API_KEY_ENV m.fl.

// Required before live TransferPayment. Controls the normative v3.7
// transport-signature/encryption order: BANKDATA, NBS or BEC.
$conf->global['BANKCONNECT_DATACENTER'] = 'BANKDATA';
```

Bankdata systemtest uses `BANKDATA`. BEC production requires a different
security order and must therefore be configured explicitly as `BEC`; unknown
values are rejected before any payment request is sent.

## Livscyklus (payment batch)

```
draft -> validated -> approved -> sent -> accepted/rejected/unknown
                        \-> cancelled
```

- Idempotens-nøgle forhindrer dobbeltforsendelse.
- `unknown` recoveres sikkert via pain.002-opslag; status mappes semantisk,
  bankens verbatim-status bevares altid.
- CAMT-import: dedup via ImportService; provenance bevares end-to-end.

## Sikkerhed

- XML-Signature: `wsu:Id`-referencer, exclusive C14N, `wsse:Security`,
  customer `BinarySecurityToken` + `SecurityTokenReference`.
- Signering/krypteringsrækkefølge pr. datacenter (BANKDATA/NBS vs BEC).
- Certifikat-livscyklus: atomisk renewal, per-agreement serialisering,
  CSRF på onboarding.
- Secret-håndtering via miljøvariabler; ingen secrets i DB eller logs
  (redaction i logger).
- Respons-side: verificeret dekrypteringsgrænse før parsing.

## Tests

```bash
php phpunit.phar --bootstrap tests/unit/bootstrap.php tests/unit
```

CI (`.github/workflows/test.yml`): syntax check + unit tests på push/PR.

## Videre udvikling

- Opret eller genbrug `llx_bank`-poster ved BankConnect-import gennem Dolibarrs
  `Account`-domænelogik.
- Knyt godkendte match til Dolibarr-betalinger uden at oprette en dubletpost.
- Verificér hele flowet frem til Dolibarrs standard bankfinanskladde.
- Live XML crypto (kræver officiel BankConnect developer package).
- Issue #43: qualification/performance/failure-injection gate.
- OIOUBL/EAN-fakturering (dkmodul-dolibarr-repoet).
