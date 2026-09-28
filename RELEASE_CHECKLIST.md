# BankConnect 1.2.0 release gates

Automated CI covers PHP syntax, unit tests, isolated failure/performance tests,
and ZIP layout. It does not demonstrate compatibility with a running Dolibarr
instance or a real BankConnect endpoint. Record the environment and evidence for
each gate before publishing the package for live use.

## Dolibarr 24 installation and upgrade

- [ ] Build `dist/module_bankconnect-1.2.0.zip` from the intended release commit.
- [ ] Install and enable it in a clean Dolibarr 24 instance with Banks and Cash.
      Confirm tables, menus, permissions, cron definition, and both language packs.
- [ ] Upgrade a copy of an existing installation. Confirm mappings, certificates,
      imported bank rows and approved matches remain intact; no import duplicates.
- [ ] Test read-only and write users in two entities. A user cannot select an
      account, agreement, invoice or payment batch from another entity.
- [ ] Check the normal Dolibarr payment and bank financial journal handoff;
      BankConnect must not write directly into the accounting ledger.

## BankConnect system test

- [ ] Configure the trusted CA from the official package in Bankdata system test.
      Activate an agreement, fetch/verify certificates, map an account, and run
      the signed read-only connection test.
- [ ] Import a CAMT statement twice (manual and scheduled); confirm one bank
      row per transaction, correct matching, approval, and audit trail.
- [ ] Create a partial-payment supplier invoice, build its batch using the
      remaining amount and mapped debtor account, submit once, and reconcile
      a matching pain.002 response.
- [ ] Induce a transport timeout after submission and verify UNKNOWN blocks
      repeat submission; resolve it by a bank status lookup. Confirm unrelated
      status reports cannot alter the batch.
- [ ] Exercise expired certificates, endpoint failures, and recovery. Capture
      logs with secrets removed. Confirm an interrupted SUBMITTING batch is
      handled operationally without an automatic repeat payment.
- [ ] Run the equivalent production-data-centre certificate and signature
      checks before using NBS/SDC or BEC; system test alone validates Bankdata.

## Release decision

- [ ] Reconcile README, INSTALL and changelog with the tested capabilities.
- [ ] Record the exact commit, Dolibarr/PHP/database versions, test evidence,
      operator and date. Document backup and rollback before enabling cron or
      submitting live payments.
- [ ] Tag 1.2.0 and publish the tested ZIP only after all applicable gates pass.
