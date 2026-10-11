<?php

class BankTransaction
{
    public string $date = '';
    public float $amount = 0.0;
    public string $currency = 'DKK';
    public string $text = '';
    public string $reference = '';
    public string $counterparty = '';
    public string $hash = '';

    /** ISO Refs/AcctSvcrRef or Ntry Ref when present – stabilises dedup */
    public string $acctSvcrRef = '';

    /** True when Ntry/RvslInd indicates a reversal/correction */
    public bool $isReversal = false;

    /** True when TxDtls cannot be safely split without inventing amounts. */
    public bool $requiresManualReview = false;

    /** Stable statement identity from CAMT Stmt/Rpt/Ntfctn. */
    public string $statementId = '';

    /** Stable transaction identity from bank references. */
    public string $transactionId = '';

    /** Detail belongs to an entry with multiple individually posted TxDtls. */
    public bool $isSplitDetail = false;

	/** @param array<string,mixed> $row */
	public static function fromArray(array $row): self
	{
		$transaction = new self();
		$transaction->date = (string)($row['date'] ?? $row['tx_date'] ?? '');
		$transaction->amount = (float)($row['amount'] ?? 0);
		$transaction->currency = (string)($row['currency'] ?? 'DKK');
		$transaction->text = (string)($row['text'] ?? $row['label'] ?? '');
		$transaction->reference = (string)($row['reference'] ?? '');
		$transaction->counterparty = (string)($row['counterparty'] ?? '');
		$transaction->hash = (string)($row['hash'] ?? '');
		$transaction->acctSvcrRef = (string)($row['acct_svcr_ref'] ?? $row['acctSvcrRef'] ?? '');
		$transaction->isReversal = !empty($row['is_reversal']) || !empty($row['isReversal']);
		$transaction->requiresManualReview = !empty($row['requires_manual_review']) || !empty($row['requiresManualReview']);
		$transaction->statementId = (string)($row['statement_id'] ?? '');
		$transaction->transactionId = (string)($row['transaction_id'] ?? '');
		$transaction->isSplitDetail = !empty($row['is_split_detail']) || !empty($row['isSplitDetail']);
		return $transaction;
	}
}
