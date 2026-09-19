<?php

/**
 * CamtParser – parses camt.053 / camt.054 (ISO 20022) into BankTransaction DTOs.
 *
 * Spec-aligned behaviour (BankConnect / CGI camt.053.001.02):
 *  - One BankTransaction per TxDtls when present (batch OCR/FIK, salary batches)
 *  - Structured reference priority:
 *      1. Refs/EndToEndId (2.148)
 *      2. RmtInf/Strd/CdtrRefInf/Ref (OCR/FIK, 2.262)
 *      3. RmtInf/Strd/RfrdDocInf/Nb (2.243)
 *      4. Fallback: first 4–15 digit token in unstructured text only if no structured ref
 *  - Counterparty: Cdtr/Nm on DBIT (outgoing), Dbtr/Nm on CRDT (incoming)
 *  - RvslInd → isReversal flag
 *  - Hash includes text + acctSvcrRef for stable dedup
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
            throw new RuntimeException('Empty XML');
        }

        $prev = libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml);
        if ($root === false) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            throw new RuntimeException('Malformed camt XML');
        }
        libxml_use_internal_errors($prev);

        $local = $root->getName();
        // Accept BankToCustomerStatement / AccountReport / DebitCreditNotification wrappers
        $okRoots = ['Document', 'BkToCstmrStmt', 'BkToCstmrAcctRpt', 'BkToCstmrDbtCdtNtfctn'];
        // Document is fine; check inner if present
        $inner = $root->xpath('/*[local-name()="Document"]/*') ?: [];
        if (!empty($inner)) {
            $innerName = $inner[0]->getName();
            $allowed = ['BkToCstmrStmt', 'BkToCstmrAcctRpt', 'BkToCstmrDbtCdtNtfctn'];
            // Soft check only – do not throw; empty result is possible for wrong payload
            if (!in_array($innerName, $allowed, true)
                && !in_array($local, $allowed, true)
                && $local !== 'Document') {
                // continue parsing Ntry nodes if any
            }
        }

        $txs = [];
        $entries = $root->xpath('//*[local-name()="Ntry"]') ?: [];

        foreach ($entries as $ntry) {
            $creditDebit = strtoupper((string) ($this->first($ntry, './*[local-name()="CdtDbtInd"]') ?? 'CRDT'));
            $isReversal = strtoupper((string) ($this->first($ntry, './*[local-name()="RvslInd"]') ?? '')) === 'TRUE'
                || strtoupper((string) ($this->first($ntry, './*[local-name()="RvslInd"]') ?? '')) === '1';

            $dateEl = $this->first($ntry, './*[local-name()="BookgDt"]/*[local-name()="Dt"]');
            if ($dateEl === null) {
                $dateEl = $this->first($ntry, './*[local-name()="ValDt"]/*[local-name()="Dt"]');
            }
            $date = substr((string) ($dateEl ?? ''), 0, 10);

            $ntryAmtEl = $this->first($ntry, './*[local-name()="Amt"]');
            $ntryAmount = abs((float) (string) ($ntryAmtEl ?? 0));
            $ntryCcy = (string) (($ntryAmtEl['Ccy'] ?? null) ?: 'DKK');

            $ntryAcctSvcrRef = (string) ($this->first($ntry, './*[local-name()="AcctSvcrRef"]') ?? '');
            if ($ntryAcctSvcrRef === '') {
                $ntryAcctSvcrRef = (string) ($this->first($ntry, './*[local-name()="NtryRef"]') ?? '');
            }

            $txDtlsList = $ntry->xpath('.//*[local-name()="TxDtls"]') ?: [];

            if (count($txDtlsList) > 0) {
                foreach ($txDtlsList as $txDtls) {
                    $txs[] = $this->buildFromTxDtls(
                        $txDtls,
                        $date,
                        $creditDebit,
                        $ntryAmount,
                        $ntryCcy,
                        $ntryAcctSvcrRef,
                        $isReversal,
                        $ntry
                    );
                }
            } else {
                // No TxDtls – one transaction from Ntry level
                $txs[] = $this->buildFromNtryOnly(
                    $ntry,
                    $date,
                    $creditDebit,
                    $ntryAmount,
                    $ntryCcy,
                    $ntryAcctSvcrRef,
                    $isReversal
                );
            }
        }

        return $txs;
    }

    private function buildFromTxDtls(
        $txDtls,
        string $date,
        string $creditDebit,
        float $ntryAmount,
        string $ntryCcy,
        string $ntryAcctSvcrRef,
        bool $isReversal,
        $ntry
    ): BankTransaction {
        // Per-tx amount if present, else share ntry amount (single TxDtls) or use ntry
        $amtEl = $this->first($txDtls, './/*[local-name()="Amt"]');
        if ($amtEl === null) {
            $amtEl = $this->first($txDtls, './/*[local-name()="InstdAmt"]');
        }
        $amount = $amtEl !== null ? abs((float) (string) $amtEl) : $ntryAmount;
        $ccy = $amtEl !== null ? (string) (($amtEl['Ccy'] ?? null) ?: $ntryCcy) : $ntryCcy;
        $signed = $creditDebit === 'DBIT' ? -abs($amount) : abs($amount);

        $text = $this->collectUnstructured($txDtls);
        if ($text === '') {
            $info = $this->first($ntry, './*[local-name()="AddtlNtryInf"]');
            $text = trim((string) ($info ?? ''));
        }

        $ref = $this->extractStructuredReference($txDtls);
        if ($ref === '') {
            $ref = $this->extractFallbackReference($text);
        }

        $cp = $this->extractCounterparty($txDtls, $creditDebit);
        if ($cp === '') {
            $cp = $this->extractCounterparty($ntry, $creditDebit);
        }

        $acctRef = (string) ($this->first($txDtls, './/*[local-name()="AcctSvcrRef"]') ?? '');
        if ($acctRef === '') {
            $acctRef = $ntryAcctSvcrRef;
        }
        $e2e = (string) ($this->first($txDtls, './/*[local-name()="EndToEndId"]') ?? '');
        if ($acctRef === '' && $e2e !== '') {
            $acctRef = $e2e;
        }

        return $this->makeTx($date, $signed, $ccy, $text, $ref, $cp, $acctRef, $isReversal);
    }

    private function buildFromNtryOnly(
        $ntry,
        string $date,
        string $creditDebit,
        float $ntryAmount,
        string $ntryCcy,
        string $ntryAcctSvcrRef,
        bool $isReversal
    ): BankTransaction {
        $signed = $creditDebit === 'DBIT' ? -abs($ntryAmount) : abs($ntryAmount);

        $text = $this->collectUnstructured($ntry);
        if ($text === '') {
            $info = $this->first($ntry, './*[local-name()="AddtlNtryInf"]');
            $text = trim((string) ($info ?? ''));
        }

        $ref = $this->extractStructuredReference($ntry);
        if ($ref === '') {
            $ref = $this->extractFallbackReference($text);
        }

        $cp = $this->extractCounterparty($ntry, $creditDebit);

        return $this->makeTx($date, $signed, $ntryCcy, $text, $ref, $cp, $ntryAcctSvcrRef, $isReversal);
    }

    private function makeTx(
        string $date,
        float $amount,
        string $currency,
        string $text,
        string $ref,
        string $cp,
        string $acctSvcrRef,
        bool $isReversal
    ): BankTransaction {
        $tx = new BankTransaction();
        $tx->date = $date;
        $tx->amount = $amount;
        $tx->currency = $currency !== '' ? $currency : 'DKK';
        $tx->text = $text;
        $tx->reference = $ref;
        $tx->counterparty = $cp;
        $tx->acctSvcrRef = $acctSvcrRef;
        $tx->isReversal = $isReversal;
        $tx->hash = hash(
            'sha256',
            implode('|', [$date, $amount, $ref, $cp, $text, $acctSvcrRef])
        );
        return $tx;
    }

    /**
     * Priority: EndToEndId → CdtrRefInf/Ref → RfrdDocInf/Nb
     */
    private function extractStructuredReference($ctx): string
    {
        $e2e = $this->first($ctx, './/*[local-name()="EndToEndId"]');
        if ($e2e !== null && trim((string) $e2e) !== '' && strtoupper((string) $e2e) !== 'NOTPROVIDED') {
            return trim((string) $e2e);
        }

        $cdtrRef = $this->first($ctx, './/*[local-name()="CdtrRefInf"]/*[local-name()="Ref"]');
        if ($cdtrRef !== null && trim((string) $cdtrRef) !== '') {
            return trim((string) $cdtrRef);
        }

        $docNb = $this->first($ctx, './/*[local-name()="RfrdDocInf"]/*[local-name()="Nb"]');
        if ($docNb !== null && trim((string) $docNb) !== '') {
            return trim((string) $docNb);
        }

        return '';
    }

    /** Last resort only – never preferred over structured fields */
    private function extractFallbackReference(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (preg_match('/\b(\d{4,15})\b/', $text, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * DBIT (money out) → creditor is the counterparty
     * CRDT (money in) → debtor is the counterparty
     */
    private function extractCounterparty($ctx, string $creditDebit): string
    {
        if ($creditDebit === 'DBIT') {
            $nm = $this->first($ctx, './/*[local-name()="Cdtr"]/*[local-name()="Nm"]');
            if ($nm !== null && trim((string) $nm) !== '') {
                return trim((string) $nm);
            }
            // RltdPties/Cdtr
            $nm = $this->first($ctx, './/*[local-name()="RltdPties"]/*[local-name()="Cdtr"]/*[local-name()="Nm"]');
            if ($nm !== null && trim((string) $nm) !== '') {
                return trim((string) $nm);
            }
        } else {
            $nm = $this->first($ctx, './/*[local-name()="Dbtr"]/*[local-name()="Nm"]');
            if ($nm !== null && trim((string) $nm) !== '') {
                return trim((string) $nm);
            }
            $nm = $this->first($ctx, './/*[local-name()="RltdPties"]/*[local-name()="Dbtr"]/*[local-name()="Nm"]');
            if ($nm !== null && trim((string) $nm) !== '') {
                return trim((string) $nm);
            }
        }

        // Last resort: any Nm under RltdPties (legacy samples)
        $nm = $this->first($ctx, './/*[local-name()="RltdPties"]//*[local-name()="Nm"]');
        if ($nm !== null && trim((string) $nm) !== '') {
            return trim((string) $nm);
        }

        return '';
    }

    private function collectUnstructured($ctx): string
    {
        $parts = [];
        foreach ($ctx->xpath('.//*[local-name()="Ustrd"]') ?: [] as $u) {
            $t = trim((string) $u);
            if ($t !== '') {
                $parts[] = $t;
            }
        }
        return trim(implode(' ', $parts));
    }

    /** @return \SimpleXMLElement|null */
    private function first($ctx, string $xpath)
    {
        $nodes = @$ctx->xpath($xpath);
        return (!empty($nodes)) ? $nodes[0] : null;
    }
}
