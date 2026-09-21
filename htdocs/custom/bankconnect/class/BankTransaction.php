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
}
