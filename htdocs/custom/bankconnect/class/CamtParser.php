<?php

/**
 * CamtParser - parses camt.053/camt.054 (ISO 20022) into BankTransaction DTOs.
 */

require_once __DIR__.'/BankTransaction.php';

class CamtParser
{
    /**
     * @return BankTransaction[]
     */
    public function parse(string $xml): array
    {
        if (trim($xml) === '') {
            throw new InvalidArgumentException('Empty XML');
        }

        $prev = libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml);
        if ($root === false) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            throw new RuntimeException('Malformed camt XML');
        }
        libxml_use_internal_errors($prev);

        $txs = [];
        $entries = $root->xpath('//*[local-name()="Ntry"]');

        foreach ($entries as $ntry) {
            $amountEl = $ntry->xpath('./*[local-name()="Amt"]');
            $amount = (float) (string) ($amountEl[0] ?? 0);
            $ccy = (string) ($amountEl[0]['Ccy'] ?? 'DKK');

            $creditDebit = (string) ($ntry->xpath('./*[local-name()="CdtDbtInd"]')[0] ?? 'CRDT');
            $amount = strtoupper($creditDebit) === 'DBIT' ? -abs($amount) : abs($amount);

            $dateEl = $ntry->xpath('./*[local-name()="BookgDt"]/*[local-name()="Dt"]');
            $date = substr((string) ($dateEl[0] ?? ''), 0, 10);

            $text = '';
            foreach ($ntry->xpath('.//*[local-name()="Ustrd"]') as $u) {
                $text .= (string) $u.' ';
            }
            $info = $ntry->xpath('./*[local-name()="AddtlNtryInf"]');
            if (trim($text) === '' && $info) {
                $text = (string) $info[0];
            }
            $text = trim($text);

            $ref = '';
            if (preg_match('/\b(\d{4,15})\b/', $text, $m)) {
                $ref = $m[1];
            }

            $cp = '';
            $nm = $ntry->xpath('.//*[local-name()="Nm"]');
            if ($nm) {
                $cp = (string) $nm[0];
            }

            $tx = new BankTransaction();
            $tx->date = $date;
            $tx->amount = $amount;
            $tx->currency = $ccy !== '' ? $ccy : 'DKK';
            $tx->text = $text;
            $tx->reference = $ref;
            $tx->counterparty = $cp;
            $tx->hash = hash('sha256', $date.'|'.$amount.'|'.$ref.'|'.$text);
            $txs[] = $tx;
        }

        return $txs;
    }
}
