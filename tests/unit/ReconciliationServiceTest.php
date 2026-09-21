<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/MockDoliDB.php';
require_once __DIR__.'/MistralMatcherTest.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/MatchResult.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankTransaction.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/Candidate.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/ReconciliationEngine.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectStore.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/ReconciliationService.php';

class ReconciliationServiceTest extends TestCase
{
    private MockDoliDB $db;
    private ReconciliationService $service;

    protected function setUp(): void
    {
        $this->db = new MockDoliDB();
        $this->db->tables['llx_bankconnect_transaction'] = [[
            'rowid' => 7,
            'state' => 'unmatched',
        ]];
        $this->db->tables['llx_bankconnect_match'] = [];
        $this->db->tables['llx_bankconnect_audit'] = [];

        $conf = bcMatcherConf();
        $this->service = new ReconciliationService(
            new ReconciliationEngine($conf),
            new BankConnectStore($this->db)
        );
    }

    public function testStrongDeterministicProposalPersistsConfidenceRuleAndAudit(): void
    {
        $result = $this->service->propose(
            7,
            42,
            bcMakeTx(['reference' => 'FA240891']),
            bcMakeCandidates()
        );

        $this->assertSame('exact', $result->matchType);
        $this->assertSame('rule', $result->source);
        $this->assertSame('reference_amount', $result->ruleName);
        $this->assertSame('strong', $result->confidenceBand());
        $this->assertSame(1.00, $result->confidence);

        $this->assertCount(1, $this->db->tables['llx_bankconnect_match']);
        $match = $this->db->tables['llx_bankconnect_match'][0];
        $this->assertSame('reference_amount', $match['rule_name']);
        $this->assertSame(1.0, (float) $match['score']);
        $this->assertSame('proposed', $this->db->tables['llx_bankconnect_transaction'][0]['state']);

        $this->assertCount(1, $this->db->tables['llx_bankconnect_audit']);
        $detail = json_decode($this->db->tables['llx_bankconnect_audit'][0]['detail'], true);
        $this->assertSame(7, $detail['tx']);
        $this->assertSame('rule', $detail['source']);
        $this->assertSame('reference_amount', $detail['rule']);
        $this->assertSame('strong', $detail['confidence_band']);
        $this->assertSame('1842', $detail['suggested'][0]['id']);
    }

    public function testNoMatchIsAuditedButCannotBecomeAProposal(): void
    {
        $result = $this->service->propose(
            7,
            42,
            bcMakeTx(['reference' => 'NO-MATCH', 'amount' => -99999.99, 'text' => '']),
            bcMakeCandidates()
        );

        $this->assertSame('none', $result->matchType);
        $this->assertSame('none', $result->confidenceBand());
        $this->assertCount(0, $this->db->tables['llx_bankconnect_match']);
        $this->assertSame('unmatched', $this->db->tables['llx_bankconnect_transaction'][0]['state']);

        $this->assertCount(1, $this->db->tables['llx_bankconnect_audit']);
        $detail = json_decode($this->db->tables['llx_bankconnect_audit'][0]['detail'], true);
        $this->assertSame('none', $detail['match_type']);
        $this->assertSame(0, $detail['confidence']);
    }

    public function testAiProposalIsAuditedAsSuggestionAndNeverApproved(): void
    {
        $conf = bcMatcherConf();
        $ai = new MistralMatcher($conf);
        $ai->setTransport(fn () => [
            'status' => 200,
            'body' => bcApiBody([
                'match_type' => 'partial',
                'confidence' => 0.55,
                'suggested' => [['id' => 1831, 'type' => 'supplier_invoice', 'amount' => 1.00]],
                'reason' => 'AI suggestion',
            ]),
        ]);

        $service = new ReconciliationService(
            new ReconciliationEngine($conf, $ai),
            new BankConnectStore($this->db)
        );

        $result = $service->propose(
            7,
            42,
            bcMakeTx(['reference' => 'NO-MATCH', 'amount' => -1.00, 'text' => 'GEBYR']),
            bcMakeCandidates()
        );

        $this->assertSame('ai', $result->source);
        $this->assertSame('ai_fallback', $result->ruleName);
        $this->assertSame('weak', $result->confidenceBand());
        $this->assertSame('proposed', $this->db->tables['llx_bankconnect_transaction'][0]['state']);

        $detail = json_decode($this->db->tables['llx_bankconnect_audit'][0]['detail'], true);
        $this->assertSame('ai', $detail['source']);
        $this->assertSame('ai_fallback', $detail['rule']);
        $this->assertSame('weak', $detail['confidence_band']);
    }
}
