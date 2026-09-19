<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/MatchResult.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankTransaction.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/Candidate.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectLogger.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/MistralMatcher.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/ReconciliationEngine.php';

// Reuse helper functions from MistralMatcherTest (loaded by PHPUnit separately,
// but declared here for standalone runs).
if (!function_exists('bcMakeTx')) {
    require_once __DIR__.'/MistralMatcherTest.php';
}

class ReconciliationEngineTest extends TestCase
{
    private function engine(?MistralMatcher $ai): ReconciliationEngine
    {
        return new ReconciliationEngine(bcMatcherConf(), $ai);
    }

    public function testExactMatchByReferenceAndAmount(): void
    {
        $engine = $this->engine(null);
        $r = $engine->reconcile(bcMakeTx(), bcMakeCandidates());
        $this->assertSame('exact', $r->matchType);
        $this->assertSame('rule', $r->source);
        $this->assertSame(1842, $r->suggested[0]['id']);
    }

    public function testPartialWhenReferenceMatchesButAmountDiffers(): void
    {
        $engine = $this->engine(null);
        $r = $engine->reconcile(bcMakeTx(['amount' => -10000.00, 'reference' => 'FA240891']), bcMakeCandidates());
        $this->assertSame('partial', $r->matchType);
        $this->assertSame(0.75, $r->confidence);
        $this->assertSame(1842, $r->suggested[0]['id']);
    }

    public function testMultipleWhenSameRefOnTwoInvoices(): void
    {
        $candidates = bcMakeCandidates();
        $candidates[0]->ref = 'SAME';
        $candidates[1]->ref = 'SAME';
        $engine = $this->engine(null);
        $r = $engine->reconcile(bcMakeTx(['reference' => 'SAME']), $candidates);
        $this->assertSame('multiple', $r->matchType);
        $this->assertCount(2, $r->suggested);
    }

    public function testExactMatchByAmountAndDateWhenNoRef(): void
    {
        $tx = bcMakeTx(['reference' => '', 'amount' => -8200.00, 'text' => 'INDBETALING ABC A/S', 'date' => '2026-09-09']);
        $engine = $this->engine(null);
        $r = $engine->reconcile($tx, bcMakeCandidates());
        $this->assertSame('exact', $r->matchType);
        $this->assertSame(1855, $r->suggested[0]['id']);
        $this->assertSame(0.97, $r->confidence);
    }

    public function testFallsBackToAiWhenRulesNotConfident(): void
    {
        $tx = bcMakeTx(['reference' => 'XYZ', 'amount' => -1.00, 'text' => 'GEBYR']);
        $fake = new MistralMatcher(bcMatcherConf());
        $fake->setTransport(fn () => ['status' => 200, 'body' => bcApiBody([
            'match_type' => 'partial', 'confidence' => 0.55,
            'suggested'  => [['id' => 1831, 'type' => 'supplier_invoice', 'amount' => 1.00]],
            'reason'     => 'Gebyr svarende til faktura FA240875',
        ])]);
        $engine = $this->engine($fake);
        $r = $engine->reconcile($tx, bcMakeCandidates());
        $this->assertSame('partial', $r->matchType);
        $this->assertSame('ai', $r->source);
        $this->assertSame(1831, $r->suggested[0]['id']);
    }

    public function testNoAiNoRulesReturnsNone(): void
    {
        $engine = $this->engine(null);
        $r = $engine->reconcile(bcMakeTx(['reference' => 'ZZZ', 'amount' => -99999.00]), bcMakeCandidates());
        $this->assertSame('none', $r->matchType);
    }

    public function testBatchMixesRuleAndAiResults(): void
    {
        $ai = new MistralMatcher(bcMatcherConf());
        $ai->setTransport(fn () => ['status' => 200, 'body' => bcApiBody([
            ['index' => 1, 'match_type' => 'none', 'confidence' => 0.0, 'suggested' => [], 'reason' => 'Ingen match'],
        ])]);

        $items = [
            ['tx' => bcMakeTx(), 'candidates' => bcMakeCandidates()],
            ['tx' => bcMakeTx(['reference' => 'QQQ', 'amount' => -77.77]), 'candidates' => bcMakeCandidates()],
        ];

        $results = $this->engine($ai)->reconcileBatch($items);
        $this->assertSame('exact', $results[0]->matchType);
        $this->assertSame('rule', $results[0]->source);
        $this->assertSame('none', $results[1]->matchType);
        $this->assertSame('ai', $results[1]->source);
    }
}
