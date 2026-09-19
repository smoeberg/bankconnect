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
}
