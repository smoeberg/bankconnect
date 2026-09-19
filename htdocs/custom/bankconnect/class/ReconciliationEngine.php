<?php

/**
 * ReconciliationEngine - deterministic 7-rule matching with AI fallback.
 *
 * Rule ladder (highest confidence wins, first hit returns):
 *   1. Exact structured reference (FI71/OCR/EndToEndId) + amount     -> 1.00
 *   2. Amount + same thirdparty + date within +/-1 day                -> 0.97
 *   3. Amount + date within +/-1 day                                  -> 0.93
 *   4. Amount + date within +/-3 days                                 -> 0.88
 *   5. Multiple: bank amount = sum of 2-5 candidate invoices          -> 0.90
 *   6. Partial: reference matches, amount differs                     -> 0.75
 *   7. Text similarity as last resort                                 -> 0.60
 *   8. Nothing matched -> hand to MistralMatcher (AI), if configured.
 *
 * Always returns a MatchResult; never throws.
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

    /** Confidence levels from the specification. */
    public const CONF_REFERENCE = 1.00;
    public const CONF_AMOUNT_THIRDPARTY_1D = 0.97;
    public const CONF_AMOUNT_1D = 0.93;
    public const CONF_AMOUNT_3D = 0.88;
    public const CONF_MULTI_SUM = 0.90;
    public const CONF_PARTIAL = 0.75;
    public const CONF_TEXT = 0.60;

    private int $window1Day = 1;
    private int $window3Days = 3;
    private float $amountTolerance = 0.05;
    private float $textSimilarityMin = 0.60;

    public function __construct(Conf $conf, ?MistralMatcher $ai = null)
    {
        $this->conf = $conf;
        $this->ai = $ai;

        $g = $conf->global ?? [];
        if (isset($g['BANKCONNECT_RULE_WINDOW_1D'])) {
            $this->window1Day = max(1, (int) $g['BANKCONNECT_RULE_WINDOW_1D']);
        }
        if (isset($g['BANKCONNECT_RULE_WINDOW_3D'])) {
            $this->window3Days = max(1, (int) $g['BANKCONNECT_RULE_WINDOW_3D']);
        }
        if (isset($g['BANKCONNECT_RULE_AMOUNT_TOLERANCE'])) {
            $this->amountTolerance = max(0.0, (float) $g['BANKCONNECT_RULE_AMOUNT_TOLERANCE']);
        }
        if (isset($g['BANKCONNECT_RULE_TEXT_SIMILARITY'])) {
            $this->textSimilarityMin = max(0.0, min(1.0, (float) $g['BANKCONNECT_RULE_TEXT_SIMILARITY']));
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
        return MatchResult::none('Ingen regel matchede, AI deaktiveret');
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
                $results[$i] = MatchResult::none('Ingen regel matchede, AI deaktiveret');
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
     * Rule ladder
     * ----------------------------------------------------------------- */

    /**
     * @return MatchResult|null null = no rule confident, try AI.
     */
    private function ruleMatch(BankTransaction $tx, array $candidates): ?MatchResult
    {
        // Rule 1: exact structured reference (FI71/OCR/EndToEndId).
        $refMatches = [];
        foreach ($candidates as $c) {
            if ($c->ref !== '' && $tx->reference !== ''
                && $this->normRef($tx->reference) === $this->normRef($c->ref)) {
                $refMatches[] = $c;
            }
        }
        if (count($refMatches) === 1) {
            $c = $refMatches[0];
            $amt = abs($tx->amount);
            if (abs($c->remaining - $amt) <= $this->amountTolerance) {
                $r = new MatchResult();
                $r->matchType = 'exact';
                $r->confidence = self::CONF_REFERENCE;
                $r->suggested = [['id' => $c->id, 'type' => $c->type, 'amount' => $amt]];
                $r->reason = 'Prcis struktureret reference (FI71/OCR/EndToEndId) og belb matcher';
                $r->source = 'rule';
                return $r;
            }
            // Rule 6: reference matches, amount differs.
            return $this->partialResult($c, $amt);
        }
        if (count($refMatches) > 1) {
            $r = new MatchResult();
            $r->matchType = 'multiple';
            $r->confidence = 0.60;
            $r->suggested = [];
            foreach ($refMatches as $c) {
                $r->suggested[] = ['id' => $c->id, 'type' => $c->type, 'amount' => $c->remaining];
            }
            $r->reason = 'Flere fakturaer matcher samme reference';
            $r->source = 'rule';
            return $r;
        }

        // Rule 5 (before the single-amount rules only if the sum beats them? No):
        // ladder order is 2, 3, 4 first - a single strong candidate is preferred
        // over a multi-invoice sum. Rule 5 runs when no single amount matches.
        $result = $this->singleAmountRules($tx, $candidates);
        if ($result !== null) {
            return $result;
        }

        // Rule 5: bank amount equals the sum of 2-5 candidate invoices.
        $multi = $this->multiSumMatch($tx, $candidates);
        if ($multi !== null) {
            return $multi;
        }

        // Rule 7: text similarity as last resort before AI.
        return $this->textMatch($tx, $candidates);
    }

    /**
     * Rules 2-4: single candidate matched by amount and date window,
     * thirdparty agreement boosts confidence.
     */
    private function singleAmountRules(BankTransaction $tx, array $candidates): ?MatchResult
    {
        $amt = abs($tx->amount);

        $pools = [
            [self::CONF_AMOUNT_THIRDPARTY_1D, $this->window1Day, true],
            [self::CONF_AMOUNT_1D, $this->window1Day, false],
            [self::CONF_AMOUNT_3D, $this->window3Days, false],
        ];

        foreach ($pools as [$confidence, $days, $requireThirdparty]) {
            $matches = [];
            foreach ($candidates as $c) {
                if (abs($c->remaining - $amt) > $this->amountTolerance) {
                    continue;
                }
                if (!$this->withinDays($tx->date, $c->date, $days)) {
                    continue;
                }
                if ($requireThirdparty && !$this->thirdpartyAgrees($tx, $c)) {
                    continue;
                }
                $matches[] = $c;
            }
            if (count($matches) === 1) {
                $c = $matches[0];
                $r = new MatchResult();
                $r->matchType = 'exact';
                $r->confidence = $confidence;
                $r->suggested = [['id' => $c->id, 'type' => $c->type, 'amount' => $amt]];
                $r->reason = $requireThirdparty
                    ? 'Belb, modpart og dato (1 dg) matcher'
                    : ($days === 1
                        ? 'Belb og dato (1 dg) matcher'
                        : 'Belb matcher, dato inden for 3 dage');
                $r->source = 'rule';
                return $r;
            }
            if (count($matches) > 1) {
                $r = new MatchResult();
                $r->matchType = 'multiple';
                $r->confidence = 0.50;
                $r->suggested = [];
                foreach ($matches as $c) {
                    $r->suggested[] = ['id' => $c->id, 'type' => $c->type, 'amount' => $c->remaining];
                }
                $r->reason = 'Flere kandidater med samme belb i datovinduet';
                $r->source = 'rule';
                return $r;
            }
        }

        return null;
    }

    /**
     * Rule 5: find a subset of 2-5 candidates whose remaining amounts
     * sum to the bank amount. Prefers subsets from the same thirdparty.
     */
    private function multiSumMatch(BankTransaction $tx, array $candidates): ?MatchResult
    {
        $amt = abs($tx->amount);
        if (count($candidates) < 2) {
            return null;
        }

        foreach ([true, false] as $sameThirdpartyOnly) {
            $pool = array_values(array_filter($candidates, function (Candidate $c) use ($tx, $sameThirdpartyOnly) {
                return !$sameThirdpartyOnly || $this->thirdpartyAgrees($tx, $c);
            }));
            if (count($pool) < 2) {
                continue;
            }
            $found = $this->subsetSum($pool, $amt);
            if ($found !== null) {
                $r = new MatchResult();
                $r->matchType = 'multiple';
                $r->confidence = self::CONF_MULTI_SUM;
                $r->suggested = [];
                foreach ($found as $c) {
                    $r->suggested[] = ['id' => $c->id, 'type' => $c->type, 'amount' => $c->remaining];
                }
                $r->reason = $sameThirdpartyOnly
                    ? 'Belb er summen af flere fakturaer fra samme modpart'
                    : 'Belb er summen af flere fakturaer';
                $r->source = 'rule';
                return $r;
            }
        }

        return null;
    }

    /** @return Candidate[]|null */
    private function subsetSum(array $pool, float $target): ?array
    {
        $n = min(count($pool), 12); // cap for safety
        $pool = array_slice($pool, 0, $n);

        // Try subset sizes 2..5 via bitmask enumeration.
        for ($mask = 1; $mask < (1 << $n); $mask++) {
            $bits = substr_count(decbin($mask), '1');
            if ($bits < 2 || $bits > 5) {
                continue;
            }
            $sum = 0.0;
            $picked = [];
            foreach ($pool as $i => $c) {
                if ($mask & (1 << $i)) {
                    $sum += $c->remaining;
                    $picked[] = $c;
                }
            }
            if (abs($sum - $target) <= $this->amountTolerance) {
                return $picked;
            }
        }
        return null;
    }

    private function partialResult(Candidate $c, float $amt): MatchResult
    {
        $r = new MatchResult();
        $r->matchType = 'partial';
        $r->confidence = self::CONF_PARTIAL;
        $r->suggested = [['id' => $c->id, 'type' => $c->type, 'amount' => $amt]];
        $r->reason = 'Reference matcher, belb afviger';
        $r->source = 'rule';
        return $r;
    }

    /**
     * Rule 7: text similarity between bank text and candidate thirdparty/ref.
     * Returns a 0.60 result if the best similarity reaches the minimum,
     * otherwise null (fall through to AI).
     */
    private function textMatch(BankTransaction $tx, array $candidates): ?MatchResult
    {
        if ($tx->text === '') {
            return null;
        }
        $txTokens = $this->tokenize($tx->text);
        if (empty($txTokens)) {
            return null;
        }
        $txTextNorm = $this->normName($tx->text);

        $best = null;
        $bestScore = 0.0;
        foreach ($candidates as $c) {
            $candTokens = $this->tokenize($c->thirdparty.' '.$c->ref);
            if (empty($candTokens)) {
                continue;
            }
            $score = $this->jaccard($txTokens, $candTokens);
            // Strong signal: the thirdparty name appears in the bank text.
            $candName = $this->normName($c->thirdparty);
            if ($candName !== '' && str_contains($txTextNorm, $candName)) {
                $score = max($score, 0.90);
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $c;
            }
        }

        if ($best !== null && $bestScore >= $this->textSimilarityMin) {
            $r = new MatchResult();
            $r->matchType = 'partial';
            $r->confidence = self::CONF_TEXT;
            $r->suggested = [['id' => $best->id, 'type' => $best->type, 'amount' => $best->remaining]];
            $r->reason = 'Tekstlighed (svag indikator - krver manuel bekraftelse)';
            $r->source = 'rule';
            return $r;
        }

        return null;
    }

    /* -----------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------- */

    private function withinDays(string $txDate, string $candDate, int $days): bool
    {
        $t = strtotime($txDate);
        $c = strtotime($candDate);
        if ($t === false || $c === false) {
            return false;
        }
        return abs($t - $c) <= $days * 86400;
    }

    private function thirdpartyAgrees(BankTransaction $tx, Candidate $c): bool
    {
        if ($tx->counterparty === '' || $c->thirdparty === '') {
            return false;
        }
        return $this->normName($tx->counterparty) === $this->normName($c->thirdparty)
            || str_contains($this->normName($tx->counterparty), $this->normName($c->thirdparty))
            || str_contains($this->normName($c->thirdparty), $this->normName($tx->counterparty));
    }

    private function normRef(string $ref): string
    {
        return strtoupper(preg_replace('/[^0-9A-Z]/', '', $ref) ?? '');
    }

    private function normName(string $name): string
    {
        $n = mb_strtolower(trim($name), 'UTF-8');
        $n = preg_replace('/[^a-z0-9 ]/', ' ', $n) ?? '';
        $n = preg_replace('/\s+/', ' ', $n) ?? '';
        // strip common Danish company suffixes
        $n = preg_replace('/\s*(a\/?s|aps|ivs|a\/s|as)(\s|$)/', ' ', $n) ?? '';
        return trim($n);
    }

    /** @return string[] */
    private function tokenize(string $s): array
    {
        $n = mb_strtolower($s, 'UTF-8');
        $n = preg_replace('/[^a-z0-9 ]/', ' ', $n) ?? '';
        $n = preg_replace('/\s+/', ' ', $n) ?? '';
        $tokens = array_values(array_filter(explode(' ', trim($n)), fn ($t) => mb_strlen($t) >= 3));
        // drop company suffix tokens
        $stop = ['aps', 'ivs', 'as'];
        return array_values(array_filter($tokens, fn ($t) => !in_array($t, $stop, true)));
    }

    /** @param string[] $a @param string[] $b */
    private function jaccard(array $a, array $b): float
    {
        $ia = array_intersect($a, $b);
        $u = array_unique(array_merge($a, $b));
        return count($u) > 0 ? count($ia) / count($u) : 0.0;
    }
}
