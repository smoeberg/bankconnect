<?php
/**
 * Pain001Builder – builds ISO 20022 pain.001.001.03 for BankConnect.
 *
 * Supported payment types (first version):
 *  - Danish account transfer (overnight / same-day)
 *  - SEPA Credit Transfer
 *
 * Later: FI-71/73/75, salary, foreign, NemKonto.
 *
 * Input is an array of payment instructions derived from Dolibarr
 * supplier invoices / salary lines.
 */

require_once __DIR__.'/BankConnectException.php';

class Pain001Builder
{
    public const TYPE_DK_TRANSFER = 'dk_transfer';
    public const TYPE_SEPA        = 'sepa';

    private string $msgId = '';
    private string $initiatingPartyName = '';
    private string $debtorIban = '';
    private string $debtorBic = '';
    private string $debtorName = '';
    private DateTimeInterface $executionDate;
    private string $paymentType = self::TYPE_DK_TRANSFER;

    /** @var array<int,array<string,mixed>> */
    private array $transactions = [];

    public function __construct()
    {
        $this->msgId = $this->generateMsgId();
        $this->executionDate = new DateTimeImmutable('today');
    }

    public function setInitiatingParty(string $name): self
    {
        $this->initiatingPartyName = $name;
        return $this;
    }

    public function setDebtor(string $name, string $iban, string $bic = ''): self
    {
        $this->debtorName = $name;
        $this->debtorIban = $iban;
        $this->debtorBic  = $bic;
        return $this;
    }

    public function setExecutionDate(DateTimeInterface $date): self
    {
        $this->executionDate = $date;
        return $this;
    }

    public function setPaymentType(string $type): self
    {
        if (!in_array($type, [self::TYPE_DK_TRANSFER, self::TYPE_SEPA], true)) {
            throw new BankConnectException("Unsupported payment type: {$type}");
        }
        $this->paymentType = $type;
        return $this;
    }

    public function setMsgId(string $msgId): self
    {
        if (strlen($msgId) > 35) {
            throw new BankConnectException('MsgId must be <= 35 characters');
        }
        $this->msgId = $msgId;
        return $this;
    }

    /**
     * Add one credit transfer transaction.
     *
     * Required keys:
     *  - endToEndId (max 35)
     *  - amount (float)
     *  - currency (DKK|EUR|...)
     *  - creditorName
     *  - creditorIban
     * Optional:
     *  - creditorBic
     *  - remittance (unstructured or structured)
     *  - fk_facture_fourn / fk_facture (for later linking)
     */
    public function addTransaction(array $tx): self
    {
        foreach (['endToEndId', 'amount', 'currency', 'creditorName', 'creditorIban'] as $req) {
            if (!array_key_exists($req, $tx)
                || (empty($tx[$req]) && $tx[$req] !== 0 && $tx[$req] !== 0.0)) {
                throw new BankConnectException("Missing required transaction field: {$req}");
            }
        }
        if (strlen((string) $tx['endToEndId']) > 35) {
            throw new BankConnectException('endToEndId must be <= 35 characters');
        }
        $this->transactions[] = $tx;
        return $this;
    }

    /**
     * Build the full pain.001.001.03 XML document.
     */
    public function build(): string
    {
        if (empty($this->transactions)) {
            throw new BankConnectException('No transactions added');
        }
        if ($this->debtorIban === '' || $this->debtorName === '') {
            throw new BankConnectException('Debtor not set');
        }

        $nbOfTxs = count($this->transactions);
        $ctrlSum = 0.0;
        foreach ($this->transactions as $tx) {
            $ctrlSum += (float) $tx['amount'];
        }
        $ctrlSumFormatted = number_format($ctrlSum, 2, '.', '');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
             . '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03" '
             . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
             . '<CstmrCdtTrfInitn>'
             . $this->buildGroupHeader($nbOfTxs, $ctrlSumFormatted)
             . $this->buildPaymentInfo($nbOfTxs, $ctrlSumFormatted)
             . '</CstmrCdtTrfInitn>'
             . '</Document>';

        return $xml;
    }

    public function getMsgId(): string
    {
        return $this->msgId;
    }

    public function getControlSum(): float
    {
        $sum = 0.0;
        foreach ($this->transactions as $tx) {
            $sum += (float) $tx['amount'];
        }
        return $sum;
    }

    public function getTransactions(): array
    {
        return $this->transactions;
    }

    // ------------------------------------------------------------------

    private function buildGroupHeader(int $nbOfTxs, string $ctrlSum): string
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Copenhagen')))->format('Y-m-d\TH:i:s');
        $initName = $this->initiatingPartyName !== '' ? $this->initiatingPartyName : $this->debtorName;

        return '<GrpHdr>'
             . '<MsgId>'. $this->e($this->msgId) .'</MsgId>'
             . '<CreDtTm>'. $now .'</CreDtTm>'
             . '<NbOfTxs>'. $nbOfTxs .'</NbOfTxs>'
             . '<CtrlSum>'. $ctrlSum .'</CtrlSum>'
             . '<InitgPty><Nm>'. $this->e($initName) .'</Nm></InitgPty>'
             . '</GrpHdr>';
    }

    private function buildPaymentInfo(int $nbOfTxs, string $ctrlSum): string
    {
        $pmtInfId = 'PMT'.substr($this->msgId, 0, 31);
        $reqdExctnDt = $this->executionDate->format('Y-m-d');

        $svcLvl = $this->paymentType === self::TYPE_SEPA
            ? '<SvcLvl><Cd>SEPA</Cd></SvcLvl>'
            : '<SvcLvl><Cd>NURG</Cd></SvcLvl>'; // Danish non-urgent default

        $xml = '<PmtInf>'
             . '<PmtInfId>'. $this->e($pmtInfId) .'</PmtInfId>'
             . '<PmtMtd>TRF</PmtMtd>'
             . '<BtchBookg>true</BtchBookg>'
             . '<NbOfTxs>'. $nbOfTxs .'</NbOfTxs>'
             . '<CtrlSum>'. $ctrlSum .'</CtrlSum>'
             . '<PmtTpInf>'. $svcLvl .'</PmtTpInf>'
             . '<ReqdExctnDt>'. $reqdExctnDt .'</ReqdExctnDt>'
             . '<Dbtr><Nm>'. $this->e($this->debtorName) .'</Nm></Dbtr>'
             . '<DbtrAcct><Id><IBAN>'. $this->e($this->debtorIban) .'</IBAN></Id></DbtrAcct>';

        if ($this->debtorBic !== '') {
            $xml .= '<DbtrAgt><FinInstnId><BIC>'. $this->e($this->debtorBic) .'</BIC></FinInstnId></DbtrAgt>';
        }

        foreach ($this->transactions as $tx) {
            $xml .= $this->buildCreditTransferTx($tx);
        }

        $xml .= '</PmtInf>';
        return $xml;
    }

    private function buildCreditTransferTx(array $tx): string
    {
        $amt = number_format((float) $tx['amount'], 2, '.', '');
        $ccy = $tx['currency'] ?? 'DKK';

        $xml = '<CdtTrfTxInf>'
             . '<PmtId>'
             . '<EndToEndId>'. $this->e($tx['endToEndId']) .'</EndToEndId>'
             . '</PmtId>'
             . '<Amt><InstdAmt Ccy="'. $this->e($ccy) .'">'. $amt .'</InstdAmt></Amt>'
             . '<Cdtr><Nm>'. $this->e($tx['creditorName']) .'</Nm></Cdtr>'
             . '<CdtrAcct><Id><IBAN>'. $this->e($tx['creditorIban']) .'</IBAN></Id></CdtrAcct>';

        if (!empty($tx['creditorBic'])) {
            $xml .= '<CdtrAgt><FinInstnId><BIC>'. $this->e($tx['creditorBic']) .'</BIC></FinInstnId></CdtrAgt>';
        }

        if (!empty($tx['remittance'])) {
            $xml .= '<RmtInf><Ustrd>'. $this->e($tx['remittance']) .'</Ustrd></RmtInf>';
        }

        $xml .= '</CdtTrfTxInf>';
        return $xml;
    }

    private function generateMsgId(): string
    {
        // UUID without dashes, max 35 chars
        return str_replace('-', '', sprintf(
            '%04x%04x%04x%04x%04x%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff),
            mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        ));
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
