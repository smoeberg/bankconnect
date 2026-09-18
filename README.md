# BankConnect - Dolibarr-modul til dansk bankafstemning

AI-understøttet bankafstemning for Dolibarr: automatisk match af bankposter
mod åbne fakturaer og lønposter, med Mistral AI som fallback, når
regelbaseret matching ikke er sikker nok.

## Arkitektur

```
Bank (PSD2/camt.053) -> CamtParser -> ReconciliationEngine
                                       |- Rule layer (ref, beløb, dato)
                                       '- AI fallback -> MistralMatcher
                                                            '- Mistral API
                                       v
                                  MatchResult -> godkendelse i UI
```

- **Rule layer**: payment reference -> beløb -> datovindue. Confidence 0.5-0.98.
- **AI fallback**: Mistral (cloud eller self-hosted) modtager kun saniterede
  data (CPR/regex-fjernet), returnerer altid gyldigt JSON eller `none` -
  aldrig exceptions til kalderen.
- **MatchResult**: `exact | partial | multiple | none` + confidence +
  forslag. Ingen automatisk bokføring uden godkendelse.

## Klasser

| Klasse | Ansvar |
|---|---|
| `CamtParser` | camt.053/054 XML -> `BankTransaction[]` |
| `ReconciliationEngine` | Regler + AI-koordinering, batch |
| `MistralMatcher` | AI-matching, PII-sanitering, logging |
| `MatchResult` | DTO: match_type, confidence, suggested, reason, source |
| `BankTransaction` / `Candidate` | DTO'er |
| `BankConnectLogger` | PII-safe logging (hash, metrics - ingen tekst) |

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
```

## Tests

```bash
php phpunit.phar --bootstrap tests/unit/bootstrap.php tests/unit
```

CI (`.github/workflows/test.yml`): syntax check + unit tests på push/PR.
