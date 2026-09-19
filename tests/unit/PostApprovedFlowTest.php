<?php
/* Tests for the reconcile.php post action flow: only approved, unposted
 * matches get posted, in tx_date order, with correct feedback count. */

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/MockDoliDB.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectStore.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/ApprovalPosting.php';

class PostApprovedFlowTest extends TestCase
{
	private function make(): array
	{
		$db = new MockDoliDB();
		return [new BankConnectStore($db), $db];
	}

	private function seed(BankConnectStore $store, string $date, float $amount): int
	{
		return $store->upsertTransaction(
			['date' => $date, 'amount' => $amount, 'currency' => 'DKK', 'reference' => 'R'.$date.$amount, 'counterparty' => 'C'.$amount],
			1, 'test.xml'
		);
	}

	public function testNothingToPostWhenNoApproved(): void
	{
		[$store] = $this->make();
		$poster = new ApprovalPosting(new MockDoliDB(), $store);
		$this->assertSame(0, $poster->postAllApproved(9, 1));
	}

	public function testPostsOnlyApprovedMatches(): void
	{
		[$store, $db] = $this->make();
		$poster = new ApprovalPosting($db, $store);

		// tx1 approved, tx2 proposed (not yet approved), tx3 rejected
		$tx1 = $this->seed($store, '2026-09-01', 100.00);
		$tx2 = $this->seed($store, '2026-09-02', 200.00);
		$tx3 = $this->seed($store, '2026-09-03', 300.00);
		$store->saveMatch($tx1, 'exact', 'exact_ref', null, 1.0, 'exact ref');
		$store->saveMatch($tx2, 'exact', 'exact_ref', null, 1.0, 'exact ref');
		$store->saveMatch($tx3, 'exact', 'exact_ref', null, 1.0, 'exact ref');

		// find match rowids: MockDoliDB autoincrement — match 1..3
		$res = $db->query("SELECT rowid FROM llx_bankconnect_match ORDER BY rowid");
		$m = [];
		while ($o = $db->fetch_object($res)) { $m[] = (int)$o->rowid; }

		$store->approveMatch($m[0], 9);
		$store->rejectMatch($m[2], 9);

		// Post. Only tx1's match qualifies.
		$n = $poster->postAllApproved(9, 1);
		$this->assertSame(1, $n);
		$this->assertSame('posted', $store->transactionState($tx1));
		$this->assertSame('proposed', $store->transactionState($tx2));
		$this->assertSame('rejected', $store->transactionState($tx3));

		// Idempotent: second run posts nothing new
		$this->assertSame(0, $poster->postAllApproved(9, 1));
	}

	public function testPostsInDateOrderAndCountsBatch(): void
	{
		[$store, $db] = $this->make();
		$poster = new ApprovalPosting($db, $store);

		// seed newest-first so posting must reorder to tx_date ASC
		$txA = $this->seed($store, '2026-09-10', 50.00);
		$txB = $this->seed($store, '2026-09-01', 60.00);
		$store->saveMatch($txA, 'exact', 'exact_ref', null, 1.0, 'exact ref');
		$store->saveMatch($txB, 'exact', 'exact_ref', null, 1.0, 'exact ref');

		$res = $db->query("SELECT rowid FROM llx_bankconnect_match ORDER BY rowid");
		$m = [];
		while ($o = $db->fetch_object($res)) { $m[] = (int)$o->rowid; }
		$store->approveMatch($m[0], 9);
		$store->approveMatch($m[1], 9);

		$before = [];
		$res = $db->query("SELECT label FROM llx_bank ORDER BY rowid");
		while ($o = $db->fetch_object($res)) { $before[] = $o->label; }

		$n = $poster->postAllApproved(9, 1);
		$this->assertSame(2, $n);

		// audit batch event written once
		$res = $db->query("SELECT COUNT(*) AS c FROM llx_bankconnect_audit WHERE event_type = 'posted_batch'");
		$this->assertSame(1, (int)$db->fetch_object($res)->c);
	}
}
