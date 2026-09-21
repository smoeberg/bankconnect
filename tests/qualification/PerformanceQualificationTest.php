<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/MatchResult.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankTransaction.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/Candidate.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/MistralMatcher.php';
require_once __DIR__.'/../../htdocs/bankconnect/class/ReconciliationEngine.php';

final class PerformanceQualificationTest extends TestCase
{
    private function engine(): ReconciliationEngine
    {
        $conf = new Conf();
        $conf->global['BANKCONNECT_RULE_WINDOW_1D'] = 1;
        $conf->global['BANKCONNECT_RULE_WINDOW_3D'] = 3;
        $conf->global['BANKCONNECT_RULE_AMOUNT_TOLERANCE'] = 0.05;
        return new ReconciliationEngine($conf);
    }

    private function candidate(int $id, float $amount, string $date): Candidate
    {
        $c = new Candidate();
        $c->id = $id;
        $c->type = 'supplier_invoice';
        $c->ref = 'INV-'.$id;
        $c->remaining = $amount;
        $c->amount = $amount;
        $c->date = $date;
        $c->thirdparty = 'Supplier '.$id;
        return $c;
    }

    private function transaction(int $id): BankTransaction
    {
        $tx = new BankTransaction();
        $tx->date = '2026-09-21';
        $tx->amount = -100.00;
        $tx->currency = 'DKK';
        $tx->reference = 'INV-'.$id;
        $tx->counterparty = 'Supplier '.$id;
        $tx->text = 'Invoice INV-'.$id;
        return $tx;
    }

    public function testDeterministicBatchThroughputAndBoundedSubsetSearch(): void
    {
        $engine = $this->engine();
        $items = [];

        for ($i = 1; $i <= 500; $i++) {
            $candidates = [];
            for ($j = 1; $j <= 12; $j++) {
                $candidates[] = $this->candidate(
                    ($i * 100) + $j,
                    $j === 1 ? 100.00 : (10.00 + $j),
                    '2026-09-21'
                );
            }
            $items[] = [
                'tx' => $this->transaction(($i * 100) + 1),
                'candidates' => $candidates,
            ];
        }

        $started = microtime(true);
        $results = $engine->reconcileBatch($items);
        $elapsedMs = (microtime(true) - $started) * 1000;

        $this->assertCount(500, $results);
        foreach ($results as $result) {
            $this->assertSame('exact', $result->matchType);
        }

        $maxMs = (int) (getenv('BANKCONNECT_QUALIFICATION_MAX_MS') ?: 5000);
        $this->assertLessThan(
            $maxMs,
            $elapsedMs,
            sprintf(
                'Deterministic reconciliation exceeded qualification budget: %.1f ms > %d ms',
                $elapsedMs,
                $maxMs
            )
        );

        fwrite(STDOUT, sprintf(
            "QUALIFICATION reconciliation: 500 tx x 12 candidates in %.1f ms (budget %d ms)%s",
            $elapsedMs,
            $maxMs,
            PHP_EOL
        ));
    }

    public function testSubsetSearchIsCappedAtTwelveCandidates(): void
    {
        $engine = $this->engine();
        $candidates = [];

        for ($i = 1; $i <= 50; $i++) {
            $candidates[] = $this->candidate($i, 1.00, '2026-09-21');
        }

        $tx = $this->transaction(999);
        $tx->reference = '';
        $tx->counterparty = '';
        $tx->amount = -999.99;
        $tx->text = '';

        $started = microtime(true);
        $result = $engine->reconcile($tx, $candidates);
        $elapsedMs = (microtime(true) - $started) * 1000;

        $this->assertSame('none', $result->matchType);
        $this->assertLessThan(1000.0, $elapsedMs, 'Subset search must remain bounded');
    }
}
