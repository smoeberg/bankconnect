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
		$this->assertStringContainsString("doc_type='bank' AND fk_doc=7631", implode("\n", $db->queries));
	}

	public function testDetectsStandardAccountingTransfer(): void
	{
		$db = new BankJournalStatusDb();
		$db->bookkeeping = [['rowid' => 55]];
		$status = (new BankJournalHandoffService($db))->status(7631, 4, 2);

		$this->assertSame('transferred', $status['state']);
		$this->assertTrue($status['transferred']);
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
	public array $bank = [['rowid' => 7631, 'fk_accountancy_journal' => 3]];
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
