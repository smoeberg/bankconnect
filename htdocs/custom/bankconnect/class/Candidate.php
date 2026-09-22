<?php

class Candidate
{
    /** @var int|string */
    public $id = 0;

    /** @var string supplier_invoice|customer_invoice|supplier_payment|customer_payment|various_payment */
    public string $type = 'supplier_invoice';

    public string $ref = '';
    public float $amount = 0.0;
    public float $remaining = 0.0;
    public string $date = '';
    public string $thirdparty = '';
    public string $currency = 'DKK';
}
