# BankConnect qualification suite

This directory contains release-gate tests that are intentionally separate from
the ordinary unit suite.

## Scope

- transport failure injection and UNKNOWN-state safety
- duplicate-import idempotency
- deterministic reconciliation throughput
- bounded subset-search behavior

The qualification suite must not contact a real BankConnect endpoint or Mistral.
All transport behavior is injected in-process.

## Run locally

php phpunit.phar --bootstrap tests/unit/bootstrap.php tests/qualification

The performance budget defaults to 5000 ms for 500 transactions with 12
candidates each. Override it with BANKCONNECT_QUALIFICATION_MAX_MS on slower
CI hosts rather than weakening the test or adding network calls.

A green qualification run is evidence for the tested failure and performance
properties; it is not a substitute for BankConnect system-test certification.
