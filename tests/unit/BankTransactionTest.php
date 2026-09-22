<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankTransaction.php';

class BankTransactionTest extends TestCase
{
	public function testBuildsTransactionFromStoredDatabaseRow(): void
	{
		$transaction = BankTransaction::fromArray([
			'tx_date' => '2026-09-22', 'amount' => '-125.50', 'currency' => 'DKK',
			'reference' => 'RF71', 'counterparty' => 'Supplier', 'acct_svcr_ref' => 'ASR-1',
			'is_reversal' => 1, 'requires_manual_review' => 1,
			'statement_id' => 'STMT-1', 'transaction_id' => 'TX-1',
		]);

		$this->assertSame('2026-09-22', $transaction->date);
		$this->assertSame(-125.5, $transaction->amount);
		$this->assertSame('ASR-1', $transaction->acctSvcrRef);
		$this->assertTrue($transaction->isReversal);
		$this->assertTrue($transaction->requiresManualReview);
		$this->assertSame('STMT-1', $transaction->statementId);
	}
}
