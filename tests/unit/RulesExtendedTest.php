<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/MatchResult.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankTransaction.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/Candidate.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectLogger.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/MistralMatcher.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/ReconciliationEngine.php';

if (!function_exists('rxMakeTx')) {
    require_once __DIR__.'/MistralMatcherTest.php';
}

class RulesExtendedTest extends TestCase
{
    private function engine(?MistralMatcher $ai = null): ReconciliationEngine
    {
        return new ReconciliationEngine(bcMatcherConf(), $ai);
    }

    /** Rule 2: amount + thirdparty + within 1 day -> 0.97 */
    public function testAmountThirdpartyOneDay(): void
    {
        $tx = bcMakeTx(['reference' => '', 'amount' => -12450.00, 'date' => '2026-09-11']);
        $r = $this->engine()->reconcile($tx, bcMakeCandidates());
        $this->assertSame('exact', $r->matchType);
        $this->assertSame(0.97, $r->confidence);
        $this->assertSame(1842, $r->suggested[0]['id']);
    }

    /** Rule 3 vs 4: 1-day beats 3-day window */
    public function testThreeDayWindowLowerConfidence(): void
    {
        $tx = bcMakeTx(['reference' => '', 'amount' => -12450.00, 'date' => '2026-09-13', 'counterparty' => '']);
        $r = $this->engine()->reconcile($tx, bcMakeCandidates());
        $this->assertSame('exact', $r->matchType);
        $this->assertSame(0.88, $r->confidence);
    }

    /** Rule 5: bank amount = sum of two invoices from same thirdparty */
    public function testMultiSumMatch(): void
    {
        $tx = bcMakeTx(['reference' => '', 'amount' => -20650.00, 'text' => 'BETALING ABC A/S', 'counterparty' => 'ABC A/S']);
        $r = $this->engine()->reconcile($tx, bcMakeCandidates());
        $this->assertSame('multiple', $r->matchType);
        $this->assertSame(0.90, $r->confidence);
        $this->assertCount(2, $r->suggested);
        $ids = array_column($r->suggested, 'id');
        $this->assertEqualsCanonicalizing([1842, 1855], $ids);
    }

    /** Rule 5 prefers same-thirdparty subsets over cross-party ones.
     *  Single candidates are dated outside the date windows so only rule 5 applies. */
    public function testMultiSumPrefersSameThirdparty(): void
    {
        // Cross-party subset: 10000 + 6650 also sums to 16650, but mixes parties.
        $d = new Candidate();
        $d->id = 1860; $d->type = 'supplier_invoice'; $d->ref = 'FA240910';
        $d->amount = 10000.00; $d->remaining = 10000.00;
        $d->date = '2026-09-01'; $d->thirdparty = 'XYZ ApS';
        $e = new Candidate();
        $e->id = 1861; $e->type = 'supplier_invoice'; $e->ref = 'FA240911';
        $e->amount = 10650.00; $e->remaining = 10650.00;
        $e->date = '2026-09-01'; $e->thirdparty = 'ZZZ Handelsby';

        $tx = bcMakeTx(['reference' => '', 'amount' => -20650.00, 'counterparty' => 'ABC A/S']);
        $r = $this->engine()->reconcile($tx, array_merge(bcMakeCandidates(), [$d, $e]));
        $this->assertSame('multiple', $r->matchType);
        $ids = array_column($r->suggested, 'id');
        $this->assertEqualsCanonicalizing([1842, 1855], $ids);
    }

    /** Rule 7: text similarity -> partial 0.60, requires manual confirmation */
    public function testTextSimilarityPartial(): void
    {
        $tx = bcMakeTx(['reference' => '', 'amount' => -1.00, 'text' => 'OVERFORELSE ABC A/S TAK', 'counterparty' => '']);
        $r = $this->engine()->reconcile($tx, bcMakeCandidates());
        $this->assertSame('partial', $r->matchType);
        $this->assertSame(0.60, $r->confidence);
        $this->assertSame(1842, $r->suggested[0]['id']);
    }

    /** Rule 7 below threshold falls through to AI */
    public function testTextBelowThresholdFallsToAi(): void
    {
        $fake = new MistralMatcher(bcMatcherConf());
        $fake->setTransport(fn () => ['status' => 200, 'body' => bcApiBody([
            'match_type' => 'none', 'confidence' => 0.0, 'suggested' => [], 'reason' => 'ok',
        ])]);
        $tx = bcMakeTx(['reference' => '', 'amount' => -1.00, 'text' => 'GEJYR KONTO', 'counterparty' => '']);
        $r = $this->engine($fake)->reconcile($tx, bcMakeCandidates());
        $this->assertSame('none', $r->matchType);
        $this->assertSame('ai', $r->source);
    }

    /** Ambiguous reference -> multiple, not partial */
    public function testAmbiguousReferenceMultiple(): void
    {
        $cands = bcMakeCandidates();
        $cands[0]->ref = '784512';
        $cands[1]->ref = '784512';
        $r = $this->engine()->reconcile(bcMakeTx(), $cands);
        $this->assertSame('multiple', $r->matchType);
        $this->assertCount(2, $r->suggested);
    }

    /** Amount tolerance: 1 ore off still matches exactly */
    public function testAmountTolerance(): void
    {
        // 4 øre diff still matches; confidence follows the date window (1 dag + modpart = 0.97)
        $tx = bcMakeTx(['amount' => -12450.04, 'date' => '2026-09-11']);
        $r = $this->engine()->reconcile($tx, bcMakeCandidates());
        $this->assertSame('exact', $r->matchType);
        $this->assertSame(0.97, $r->confidence);
    }
}
