<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankJournalHandoffService.php';

class BankJournalHandoffServiceTest extends TestCase
{
	public function testReadyUsesTheExactRelationsConsumedByBankJournal(): void
	{
		$db = new BankJournalStatusDb();
		$status = (new BankJournalHandoffService($db))->status(7631, 4, 2);

		$this->assertSame('ready', $status['state']);
		$this->assertSame(3, $status['journal_id']);
		$this->assertSame('payment', $status['payment_type']);
		$this->assertSame(901, $status['payment_id']);
		$this->assertFalse($status['transferred']);
		$bookkeepingSql = implode("\n", $db->queries);
		$this->assertStringContainsString("entity=2 AND doc_type='bank' AND fk_doc=7631", $bookkeepingSql);
	}

	public function testDetectsCompleteBalancedStandardAccountingTransfer(): void
	{
		$db = new BankJournalStatusDb();
		$db->bookkeeping = [['line_count' => 2, 'debit_total' => '100.00', 'credit_total' => '100.00']];
		$status = (new BankJournalHandoffService($db))->status(7631, 4, 2);

		$this->assertSame('transferred', $status['state']);
		$this->assertTrue($status['transferred']);
	}

	public function testOneBookkeepingLineIsNotEnoughToDeclareTransfer(): void
	{
		$db = new BankJournalStatusDb();
		$db->bookkeeping = [['line_count' => 1, 'debit_total' => '100.00', 'credit_total' => '0.00']];
		$status = (new BankJournalHandoffService($db))->status(7631, 4, 2);

		$this->assertSame('incomplete_accounting', $status['state']);
		$this->assertFalse($status['transferred']);
	}

	public function testUnbalancedBookkeepingIsNotDeclaredTransferred(): void
	{
		$db = new BankJournalStatusDb();
		$db->bookkeeping = [['line_count' => 2, 'debit_total' => '100.00', 'credit_total' => '90.00']];
		$status = (new BankJournalHandoffService($db))->status(7631, 4, 2);

		$this->assertSame('incomplete_accounting', $status['state']);
		$this->assertFalse($status['transferred']);
	}

	public function testBalancedBookkeepingMustMatchBankAmount(): void
	{
		$db = new BankJournalStatusDb();
		$db->bookkeeping = [['line_count' => 2, 'debit_total' => '50.00', 'credit_total' => '50.00']];
		$status = (new BankJournalHandoffService($db))->status(7631, 4, 2);

		$this->assertSame('incomplete_accounting', $status['state']);
		$this->assertFalse($status['transferred']);
	}

	public function testTransferLifecycleFlipsFromReadyToTransferred(): void
	{
		$db = new BankJournalStatusDb();
		$service = new BankJournalHandoffService($db);
		$this->assertSame('ready', $service->status(7631, 4, 2)['state']);

		$db = new BankJournalStatusDb();
		$db->bookkeeping = [['line_count' => 2, 'debit_total' => '100.00', 'credit_total' => '100.00']];
		$status = (new BankJournalHandoffService($db))->status(7631, 4, 2);

		$this->assertTrue($status['transferred']);
		$this->assertSame(3, $status['journal_id']);
		$this->assertSame(901, $status['payment_id']);
	}

	public function testJournalIdComesFromTheBankAccountRelationForStandardJournal(): void
	{
		$db = new BankJournalStatusDb();
		(new BankJournalHandoffService($db))->status(7631, 4, 2);

		$this->assertCount(4, $db->queries);
		$this->assertStringContainsString('ba.fk_accountancy_journal', $db->queries[0]);
		$this->assertStringContainsString('JOIN llx_bank_account ba', $db->queries[0]);
		$this->assertStringContainsString('ba.entity=2', $db->queries[0]);
	}

	public function testMissingBankEntryReportsMissingState(): void
	{
		$db = new BankJournalStatusDb();
		$db->bank = [];
		$status = (new BankJournalHandoffService($db))->status(7631, 4, 2);

		$this->assertSame('missing_bank_entry', $status['state']);
		$this->assertFalse($status['transferred']);
		$this->assertCount(1, $db->queries);
	}

	public function testMissingPaymentLinkStopsBeforeBookkeepingQuery(): void
	{
		$db = new BankJournalStatusDb();
		$db->links = [];
		$status = (new BankJournalHandoffService($db))->status(7631, 4, 2);

		$this->assertSame('missing_payment_link', $status['state']);
		$this->assertCount(2, $db->queries);
	}

	public function testMissingJournalStopsBeforePaymentAndBookkeepingQueries(): void
	{
		$db = new BankJournalStatusDb();
		$db->bank[0]['fk_accountancy_journal'] = null;
		$status = (new BankJournalHandoffService($db))->status(7631, 4, 2);

		$this->assertSame('missing_journal', $status['state']);
		$this->assertCount(1, $db->queries);
	}

	public function testBrokenFkBankIsNotDeclaredReady(): void
	{
		$db = new BankJournalStatusDb();
		$db->payment = [];
		$status = (new BankJournalHandoffService($db))->status(7631, 4, 2);

		$this->assertSame('broken_payment_link', $status['state']);
	}
}

class BankJournalStatusDb
{
	public array $bank = [['rowid' => 7631, 'amount' => '100.00', 'amount_main_currency' => null, 'fk_accountancy_journal' => 3]];
	public array $links = [['type' => 'payment', 'url_id' => 901]];
	public array $payment = [['rowid' => 901]];
	public array $bookkeeping = [];
	public array $queries = [];

	public function query(string $sql): array
	{
		$this->queries[] = $sql;
		if (str_contains($sql, 'JOIN llx_bank_account')) return $this->bank;
		if (str_contains($sql, 'FROM llx_bank_url')) return $this->links;
		if (str_contains($sql, 'FROM llx_paiement ')) return $this->payment;
		if (str_contains($sql, 'FROM llx_accounting_bookkeeping')) return $this->bookkeeping;
		return [];
	}

	public function fetch_object(&$result)
	{
		$row = array_shift($result);
		return $row ? (object)$row : false;
	}
}
