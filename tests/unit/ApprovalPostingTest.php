<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/MockDoliDB.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectStore.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/ApprovalPosting.php';

class ApprovalPostingTest extends TestCase
{
	private $db;
	private $store;
	private $posting;

	protected function setUp(): void
	{
		$this->db = new MockDoliDB();
		$this->store = new BankConnectStore($this->db);
		$this->posting = new ApprovalPosting($this->db, $this->store);
	}

	public function testRefusesToPostUnapprovedMatch(): void
	{
		$txid = $this->db->seedTransaction('2026-09-01', -150.00, 'FA240891', 'Testkunden ApS');
		$mid  = $this->db->seedMatch($txid, 'ai', null, 0.92);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('unapproved');
		$this->posting->postApprovedMatch($mid, 7, 1);
	}

	public function testRefusesToPostRejectedMatch(): void
	{
		$txid = $this->db->seedTransaction('2026-09-01', -150.00, 'FA240891', 'Testkunden ApS');
		$mid  = $this->db->seedMatch($txid, 'ai', null, 0.92);
		$this->store->rejectMatch($mid, 7);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('rejected');
		$this->posting->postApprovedMatch($mid, 7, 1);
	}

	public function testPostsApprovedMatchOnce(): void
	{
		$txid = $this->db->seedTransaction('2026-09-01', -150.00, 'FA240891', 'Testkunden ApS');
		$mid  = $this->db->seedMatch($txid, 'ai', null, 0.92);
		$this->store->approveMatch($mid, 7);
		$entryId = $this->posting->postApprovedMatch($mid, 7, 1);
		$this->assertGreaterThan(0, $entryId);
		// idempotent: second call returns same entry, no duplicate bank row
		$again = $this->posting->postApprovedMatch($mid, 7, 1);
		$this->assertSame($entryId, $again);
		$this->assertSame(1, $this->db->countRows('llx_bank'));
	}

	public function testPostingWritesAuditChain(): void
	{
		$txid = $this->db->seedTransaction('2026-09-01', -150.00, 'FA240891', 'Testkunden ApS');
		$mid  = $this->db->seedMatch($txid, 'ai', null, 0.92);
		$this->store->approveMatch($mid, 7);
		$entryId = $this->posting->postApprovedMatch($mid, 7, 1);
		$types = array_column($this->db->table('llx_bankconnect_audit'), 'event_type');
		$this->assertContains('match_approved', $types);
		$this->assertContains('posted', $types);
		$posted = $this->db->findFirst('llx_bankconnect_audit', 'event_type', 'posted');
		$this->assertStringContainsString('match '.$mid, (string)$posted['detail']);
		$this->assertStringContainsString('bank entry '.$entryId, (string)$posted['detail']);
		$this->assertStringContainsString('-150', (string)$posted['detail']);
	}

	public function testPostAllApprovedOnlyPostsApprovedOnes(): void
	{
		$a = $this->db->seedTransaction('2026-09-01', -100.00, 'A1', 'Firma A');
		$b = $this->db->seedTransaction('2026-09-02', -200.00, 'B1', 'Firma B');
		$c = $this->db->seedTransaction('2026-09-03', -300.00, 'C1', 'Firma C');
		$ma = $this->db->seedMatch($a, 'rule_reference', 'ref', 1.0);
		$mb = $this->db->seedMatch($b, 'rule_reference', 'ref', 1.0);
		$mc = $this->db->seedMatch($c, 'ai', null, 0.90);
		$this->store->approveMatch($ma, 7);
		$this->store->approveMatch($mb, 7);
		// $mc stays approved_by = NULL — must NOT post
		$n = $this->posting->postAllApproved(7, 1);
		$this->assertSame(2, $n);
		$this->assertSame(2, $this->db->countRows('llx_bank'));
		$this->assertSame('posted', $this->db->txState($a));
		$this->assertSame('posted', $this->db->txState($b));
		$this->assertSame('proposed', $this->db->txState($c));
	}

	public function testAmountSignAndCentsPreserved(): void
	{
		$txid = $this->db->seedTransaction('2026-09-01', 250.75, 'IN+', 'Kunde indbetalte');
		$mid  = $this->db->seedMatch($txid, 'rule_reference', 'ref', 1.0);
		$this->store->approveMatch($mid, 7);
		$this->posting->postApprovedMatch($mid, 7, 1);
		$row = $this->db->findFirst('llx_bank', 'rowid', 1);
		$this->assertSame(25075, (int)$row['amountc']); // positive credit, cents
		$this->assertSame(250.75, (float)$row['amountc'] / 100);
	}
}
