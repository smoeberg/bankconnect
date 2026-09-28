<?php
/** Entity-scoped payment actions used by the Dolibarr payment page. */
require_once __DIR__.'/AgreementStore.php';
require_once __DIR__.'/BankAccountMappingStore.php';
require_once __DIR__.'/BankCertificateStore.php';
require_once __DIR__.'/BankConnectClientFactory.php';
require_once __DIR__.'/Pain001Builder.php';
require_once __DIR__.'/PaymentBatchService.php';

class PaymentPageService
{
    private $db;
    private Conf $conf;
    private AgreementStore $agreements;
    private BankAccountMappingStore $mappings;
    private BankConnectClientFactory $clients;
    private string $prefix;

    public function __construct($db, Conf $conf, ?string $prefix = null)
    {
        $this->db = $db;
        $this->conf = $conf;
        $this->prefix = $prefix ?? (defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_');
        $this->agreements = new AgreementStore($db, $this->prefix);
        $this->mappings = new BankAccountMappingStore($db, $this->prefix);
        $this->clients = new BankConnectClientFactory(
            $conf, $this->agreements, new BankCertificateStore($db, (int)$conf->entity, $this->prefix)
        );
    }

    /** @return list<array{agreement:array,account:array}> */
    public function availableAgreements(int $entity): array
    {
        $out = [];
        foreach ($this->mappings->listMappings($entity) as $mapping) {
            $agreement = $this->agreements->getAgreement((int)$mapping['fk_agreement']);
            if (!$agreement || (int)$agreement['entity'] !== $entity || $agreement['status'] !== 'active') {
                continue;
            }
            $account = $this->bankAccount($entity, (int)$mapping['fk_bank_account']);
            if ($account !== null && trim((string)$account['iban']) !== '') {
                $out[] = ['agreement' => $agreement, 'account' => $account];
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function create(int $entity, int $agreementId, array $invoiceIds, string $companyName, ?string $executionDate, string $paymentType, bool $sendNow): array
    {
        [$agreement, $account] = $this->configuredAgreement($entity, $agreementId);
        if (!$invoiceIds || count($invoiceIds) > 200 || count(array_unique($invoiceIds)) !== count($invoiceIds)) {
            throw new BankConnectException('Select between 1 and 200 distinct supplier invoices');
        }
        $builder = (new Pain001Builder())
            ->setInitiatingParty($companyName)
            ->setDebtor($companyName, $account['iban'], $account['bic']);
        if ($executionDate !== null && $executionDate !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $executionDate);
            if (!$date || $date->format('Y-m-d') !== $executionDate || $date < new DateTimeImmutable('today')) {
                throw new BankConnectException('Select a valid future execution date');
            }
            $builder->setExecutionDate($date);
        }
        $builder->setPaymentType($paymentType);

        foreach ($invoiceIds as $id) {
            if (!ctype_digit((string)$id) || (int)$id <= 0) {
                throw new BankConnectException('Invalid supplier invoice selection');
            }
            $invoice = $this->invoice($entity, (int)$id);
            if ($invoice === null || trim((string)$invoice->iban) === '') {
                throw new BankConnectException('Supplier invoice is unavailable or has no creditor IBAN');
            }
            $existing = $this->db->query('SELECT bl.rowid FROM '.$this->prefix.'bankconnect_batch_line bl'
                .' JOIN '.$this->prefix.'bankconnect_batch b ON b.rowid=bl.fk_batch'
                .' WHERE bl.fk_facture_fourn='.(int)$id.' AND b.entity='.(int)$entity
                ." AND b.status IN ('draft','validated','prepared','submitting','unknown','submitted','pending','accepted','partial') LIMIT 1");
            if ($existing === false) throw new BankConnectException('Unable to check existing payment batches');
            if ($this->db->fetch_object($existing)) {
                throw new BankConnectException('Supplier invoice already belongs to a payment batch');
            }
            $currency = strtoupper((string)($invoice->multicurrency_code ?: ($this->conf->currency ?? 'DKK')));
            if ($paymentType === Pain001Builder::TYPE_SEPA && $currency !== 'EUR') {
                throw new BankConnectException('SEPA payment requires a EUR invoice');
            }
            $baseCurrency = strtoupper((string)($this->conf->currency ?? 'DKK'));
            $multi = $currency !== $baseCurrency;
            $total = (float)($multi ? $invoice->multicurrency_total_ttc : $invoice->total_ttc);
            $paid = (float)($multi ? $invoice->paid_multi : $invoice->paid_main);
            $remaining = round($total - $paid, 2);
            if ($remaining <= 0) {
                throw new BankConnectException('Supplier invoice has no remaining amount to pay');
            }
            $builder->addTransaction([
                'endToEndId' => 'FF'.(int)$invoice->rowid,
                'amount' => $remaining,
                'currency' => $currency,
                'creditorName' => (string)$invoice->nom,
                'creditorIban' => (string)$invoice->iban,
                'creditorBic' => (string)($invoice->bic ?? ''),
                'remittance' => (string)$invoice->ref,
                'fk_facture_fourn' => (int)$invoice->rowid,
            ]);
        }

        // Resolve credentials before creating a send-now batch, so an invalid
        // certificate cannot leave a misleading unsent payment behind.
        $client = $sendNow ? $this->clients->create($agreement) : null;
        $svc = new PaymentBatchService($this->db, $this->conf, $client, null, $this->prefix);
        $result = $svc->createBatch($builder, $agreementId, $entity);
        if ($sendNow) {
            $svc->sendBatch($result['batch_id']);
        }
        return $result;
    }

    /** @return array{status:string,response_code?:string,correlation_id?:?string} */
    public function send(int $entity, int $batchId): array
    {
        return $this->serviceForBatch($entity, $batchId)->sendBatch($batchId);
    }

    public function refresh(int $entity, int $batchId): array
    {
        return $this->serviceForBatch($entity, $batchId)->refreshStatus($batchId);
    }

    public function resolveUnknown(int $entity, int $batchId): array
    {
        return $this->serviceForBatch($entity, $batchId)->resolveUnknownBatch($batchId);
    }

    private function serviceForBatch(int $entity, int $batchId): PaymentBatchService
    {
        $res = $this->db->query('SELECT fk_agreement FROM '.$this->prefix.'bankconnect_batch WHERE rowid='.(int)$batchId.' AND entity='.(int)$entity);
        if ($res === false) throw new BankConnectException('Unable to load payment batch');
        $batch = $this->db->fetch_object($res);
        if (!$batch) throw new BankConnectException('Payment batch not found in this entity');
        [$agreement] = $this->configuredAgreement($entity, (int)$batch->fk_agreement);
        return new PaymentBatchService($this->db, $this->conf, $this->clients->create($agreement), null, $this->prefix);
    }

    private function configuredAgreement(int $entity, int $agreementId): array
    {
        if ($agreementId <= 0) throw new BankConnectException('Select a BankConnect agreement');
        $agreement = $this->agreements->getAgreement($agreementId);
        if (!$agreement || (int)$agreement['entity'] !== $entity || $agreement['status'] !== 'active') {
            throw new BankConnectException('BankConnect agreement is not active in this entity');
        }
        $mapping = $this->mappings->findByAgreement($entity, $agreementId);
        if (!$mapping) throw new BankConnectException('BankConnect agreement has no mapped bank account');
        $account = $this->bankAccount($entity, (int)$mapping['fk_bank_account']);
        if (!$account || trim((string)$account['iban']) === '') {
            throw new BankConnectException('Mapped bank account is unavailable or has no IBAN');
        }
        return [$agreement, $account];
    }

    private function bankAccount(int $entity, int $id): ?array
    {
        $res = $this->db->query('SELECT rowid, label, iban_prefix, number, bic FROM '.$this->prefix.'bank_account'
            .' WHERE rowid='.(int)$id.' AND entity='.(int)$entity.' AND clos=0');
        if ($res === false) throw new BankConnectException('Unable to load mapped bank account');
        $row = $this->db->fetch_object($res);
        if (!$row) return null;
        return ['rowid' => (int)$row->rowid, 'label' => (string)$row->label,
            'iban' => (string)$row->iban_prefix.(string)$row->number, 'bic' => (string)($row->bic ?? '')];
    }

    private function invoice(int $entity, int $id)
    {
        $res = $this->db->query('SELECT f.rowid, f.ref, f.total_ttc, f.multicurrency_total_ttc, f.multicurrency_code,'
            .' s.nom, s.iban, s.bic,'
            .' COALESCE((SELECT SUM(pf.amount) FROM '.$this->prefix.'paiementfourn_facturefourn pf WHERE pf.fk_facturefourn=f.rowid),0) AS paid_main,'
            .' COALESCE((SELECT SUM(pf.multicurrency_amount) FROM '.$this->prefix.'paiementfourn_facturefourn pf WHERE pf.fk_facturefourn=f.rowid),0) AS paid_multi'
            .' FROM '.$this->prefix.'facture_fourn f JOIN '.$this->prefix.'societe s ON s.rowid=f.fk_soc'
            .' WHERE f.rowid='.(int)$id.' AND f.entity='.(int)$entity.' AND f.fk_statut=1 AND f.paye=0');
        if ($res === false) throw new BankConnectException('Unable to load supplier invoice');
        return $this->db->fetch_object($res);
    }
}
