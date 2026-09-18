<?php

/**
 * ReconciliationEngine - rule-based reconciliation with AI fallback.
 *
 * Flow:
 *   1. Rule layer: payment reference -> exact amount + date window.
 *   2. If not confident: hand to MistralMatcher (AI) unless absent.
 *   3. Always returns a MatchResult; never throws.
 */

require_once __DIR__.'/MatchResult.php';
require_once __DIR__.'/BankTransaction.php';
require_once __DIR__.'/Candidate.php';
require_once __DIR__.'/MistralMatcher.php';

if (!class_exists('Conf')) {
    class Conf
    {
        /** @var array<string,mixed> */
        public $global = [];
    }
}

class ReconciliationEngine
{
    private Conf $conf;
    private ?MistralMatcher $ai;
    private int $dateWindowDays = 30;
    private float $amountTolerance = 0.05;

    public function __construct(Conf $conf, ?MistralMatcher $ai = null)
    {
        $this->conf = $conf;
        $this->ai = $ai;

        $g = $conf->global ?? [];
        if (isset($g['BANKCONNECT_RULE_DATE_WINDOW'])) {
            $this->dateWindowDays = max(1, (int) $g['BANKCONNECT_RULE_DATE_WINDOW']);
        }
        if (isset($g['BANKCONNECT_RULE_AMOUNT_TOLERANCE'])) {
            $this->amountTolerance = max(0.0, (float) $g['BANKCONNECT_RULE_AMOUNT_TOLERANCE']);
        }
    }

    public function reconcile(BankTransaction $tx, array $candidates): MatchResult
    {
        $ruleResult = $this->ruleMatch($tx, $candidates);
        if ($ruleResult !== null) {
            return $ruleResult;
        }
        if ($this->ai !== null) {
            return $this->ai->match($tx, $candidates);
        }
        return MatchResult::none('Ingen match, AI deaktiveret');
    }

    /**
     * Batch reconciliation. AI calls are batched together.
     *
     * @param array $items array of ['tx'=>BankTransaction,'candidates'=>Candidate[]]
     * @return MatchResult[] indexed like input
     */
    public function reconcileBatch(array $items): array
    {
        $results = [];
        $aiItems = [];
        $aiIndexes = [];

        foreach (array_values($items) as $i => $item) {
            $ruleResult = $this->ruleMatch($item['tx'], $item['candidates']);
            if ($ruleResult !== null) {
                $results[$i] = $ruleResult;
            } else {
                $aiItems[$i] = $item;
                $aiIndexes[] = $i;
            }
        }

        if (empty($aiItems)) {
            ksort($results);
            return $results;
        }

        if ($this->ai === null) {
            foreach ($aiIndexes as $i) {
                $results[$i] = MatchResult::none('Ingen match, AI deaktiveret');
            }
            ksort($results);
            return $results;
        }

        $aiResults = $this->ai->matchBatch($aiItems);
        foreach ($aiResults as $aiKey => $r) {
            $results[$aiKey] = $r;
        }

        ksort($results);
        return $results;
    }

    /* -----------------------------------------------------------------
     * Rule layer
     * ----------------------------------------------------------------- */

    /**
     * @return MatchResult|null null = rules not confident, try AI.
     */
    private function ruleMatch(BankTransaction $tx, array $candidates): ?MatchResult
    {
        // 1a. Reference match (strongest signal)
        $refMatches = [];
        foreach ($candidates as $c) {
            if ($c->ref !== '' && $tx->reference !== ''
                && strtolower($tx->reference) === strtolower($c->ref)) {
                $refMatches[] = $c;
            }
        }
        if (count($refMatches) === 1) {
            $c = $refMatches[0];
            $amt = abs($tx->amount);
            if (abs($c->remaining - $amt) <= $this->amountTolerance) {
                $r = new MatchResult();
                $r->matchType = 'exact';
                $r->confidence = 0.98;
                $r->suggested = [['id' => $c->id, 'type' => $c->type, 'amount' => $amt]];
                $r->reason = 'Reference og beløb matcher';
                $r->source = 'rule';
                return $r;
            }
            $r = new MatchResult();
            $r->matchType = 'partial';
            $r->confidence = 0.7;
            $r->suggested = [['id' => $c->id, 'type' => $c->type, 'amount' => $amt]];
            $r->reason = 'Reference matcher, beløb afviger';
            $r->source = 'rule';
            return $r;
        }
        if (count($refMatches) > 1) {
            $r = new MatchResult();
            $r->matchType = 'multiple';
            $r->confidence = 0.6;
            $r->suggested = [];
            foreach ($refMatches as $c) {
                $r->suggested[] = ['id' => $c->id, 'type' => $c->type, 'amount' => $c->remaining];
            }
            $r->reason = 'Flere fakturaer matcher samme reference';
            $r->source = 'rule';
            return $r;
        }

        // 1b. Exact amount + date window
        $amountMatches = [];
        foreach ($candidates as $c) {
            if (abs($c->remaining - abs($tx->amount)) <= $this->amountTolerance
                && $this->withinDateWindow($tx->date, $c->date)) {
                $amountMatches[] = $c;
            }
        }
        if (count($amountMatches) === 1) {
            $c = $amountMatches[0];
            $r = new MatchResult();
            $r->matchType = 'exact';
            $r->confidence = 0.90;
            $r->suggested = [['id' => $c->id, 'type' => $c->type, 'amount' => abs($tx->amount)]];
            $r->reason = 'Unikt beløb inden for datovindue';
            $r->source = 'rule';
            return $r;
        }
        if (count($amountMatches) > 1) {
            $r = new MatchResult();
            $r->matchType = 'multiple';
            $r->confidence = 0.50;
            $r->suggested = [];
            foreach ($amountMatches as $c) {
                $r->suggested[] = ['id' => $c->id, 'type' => $c->type, 'amount' => $c->remaining];
            }
            $r->reason = 'Flere kandidater med samme beløb i datovinduet';
            $r->source = 'rule';
            return $r;
        }

        return null;
    }

    private function withinDateWindow(string $txDate, string $candDate): bool
    {
        $t = strtotime($txDate);
        $c = strtotime($candDate);
        if ($t === false || $c === false) {
            return false;
        }
        return abs($t - $c) <= $this->dateWindowDays * 86400;
    }
}
