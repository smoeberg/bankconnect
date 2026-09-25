<?php
/**
 * PaymentBatchService – payment persistence, submission and status reconciliation.
 */
require_once __DIR__.'/Pain001Builder.php';
require_once __DIR__.'/Pain002Parser.php';
require_once __DIR__.'/BankConnectClient.php';
require_once __DIR__.'/BankConnectException.php';
require_once __DIR__.'/BankConnectLogger.php';
require_once __DIR__.'/ServiceHeaderBuilder.php';
require_once __DIR__.'/PaymentStateMachine.php';
require_once __DIR__.'/BankConnectDatabasePrefix.php';

if (!class_exists('Conf')) {
    class Conf { public $global = []; }
}

class PaymentBatchService
{
    use BankConnectDatabasePrefix;

    private $db;
    private Conf $conf;
    private BankConnectLogger $logger;
    private ?BankConnectClient $client;

    public function __construct($db, Conf $conf, ?BankConnectClient $client = null, ?BankConnectLogger $logger = null, ?string $prefix = null)
    {
        $this->db = $db;
        $this->conf = $conf;
        $this->client = $client;
        $this->logger = $logger ?? new BankConnectLogger();
        $this->initializeDatabasePrefix($prefix);
    }

    public function createBatch(Pain001Builder $builder, int $fkAgreement, int $entity = 1): array
    {
        $xml = $builder->build();
        $msgId = $builder->getMsgId();
        $ctrlSum = $builder->getControlSum();
        $txs = $builder->getTransactions();
        $e2eMessageId = $this->generateEndToEndMessageId();

        $this->begin();
        try {
            $batchId = $this->insertBatch([
                'entity' => $entity,
                'fk_agreement' => $fkAgreement,
                'end_to_end_message_id' => $e2eMessageId,
                'msg_id' => $msgId,
                'status' => 'draft',
                'pain001_xml' => $xml,
                'control_sum' => $ctrlSum,
                'nb_of_txs' => count($txs),
            ]);
            foreach ($txs as $tx) {
                $this->insertBatchLine($batchId, $tx);
            }
            $this->commit();

            $this->logger->info('batch_created', [
                'batch_id' => $batchId,
                'e2e' => $e2eMessageId,
                'nb' => count($txs),
                'sum' => $ctrlSum,
            ]);

            return [
                'batch_id' => $batchId,
                'end_to_end_message_id' => $e2eMessageId,
                'msg_id' => $msgId,
                'nb_of_txs' => count($txs),
                'control_sum' => $ctrlSum,
            ];
        } catch (Throwable $e) {
            $this->rollback();
            throw new BankConnectException('Failed to create payment batch: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Submit only from a locally retry-safe state. UNKNOWN and terminal states
     * must be reconciled/resolved before another remote submission.
     */
    public function sendBatch(int $batchId): array
    {
        $batch = $this->fetchBatch($batchId);
        if (!$batch) {
            throw new BankConnectException("Batch {$batchId} not found");
        }
        $status = (string) ($batch['status'] ?? '');
        if (!in_array($status, [PaymentStateMachine::DRAFT, PaymentStateMachine::VALIDATED, PaymentStateMachine::PREPARED], true)) {
            throw new BankConnectException("Batch {$batchId} cannot be submitted from status {$status}");
        }
        if ($this->client === null) {
            throw new BankConnectException('BankConnectClient is required for payment submission');
        }

        try {
            if ($status === PaymentStateMachine::DRAFT) {
                $this->transitionBatchStatus($batchId, $status, PaymentStateMachine::VALIDATED, [
                    'message' => 'Payment batch validated before submission',
                ]);
                $status = PaymentStateMachine::VALIDATED;
            }

            if ($status === PaymentStateMachine::VALIDATED) {
                $this->transitionBatchStatus($batchId, $status, PaymentStateMachine::PREPARED, [
                    'message' => 'Payment payload prepared for BankConnect submission',
                ]);
                $status = PaymentStateMachine::PREPARED;
            }

            if ($status !== PaymentStateMachine::PREPARED) {
                throw new BankConnectException(
                    "Batch {$batchId} cannot be submitted from status {$status}"
                );
            }
        } catch (Throwable $e) {
            if ($e instanceof BankConnectException) {
                throw $e;
            }
            throw new BankConnectException('Payment state transition failed: '.$e->getMessage(), 0, $e);
        }

        // Claim the submission slot before performing network I/O. The
        // conditional UPDATE is the local idempotency boundary: only a batch
        // still in PREPARED may become SUBMITTING. A concurrent caller that
        // loses the race will observe the new state and fail closed.
        $claimed = $this->claimSubmission($batchId);
        if (!$claimed) {
            $current = $this->fetchBatch($batchId);
            $currentStatus = (string) ($current['status'] ?? '');
            throw new BankConnectException(
                "Batch {$batchId} cannot be submitted from status {$currentStatus}"
            );
        }

        try {
            require_once __DIR__.'/BankConnectXmlSecurity.php';
            $security = new BankConnectXmlSecurity($this->conf);
            $built = $security->buildTransferPayment($batch['pain001_xml'], $batch['end_to_end_message_id']);
            $paymentMessage = $built['xml'];
            $serviceHeader = $this->buildServiceHeaderForBatch($batch);
            $paymentMessage = preg_replace(
                '/^(<transferPayment\b[^>]*>)/',
                '$1'.$serviceHeader,
                $paymentMessage,
                1,
                $count
            );
            if ($count !== 1) {
                throw new BankConnectException('Failed to attach BankConnect serviceHeader to payment request');
            }
        } catch (Throwable $e) {
            $this->updateBatchStatus($batchId, 'rejected', ['message' => 'Payment preparation failed: '.$e->getMessage()]);
            throw new BankConnectException('Payment preparation failed: '.$e->getMessage(), 0, $e);
        }

        try {
            $response = $this->client->transferPayments($paymentMessage, $batch['end_to_end_message_id']);
        } catch (Throwable $e) {
            // The transport outcome is unknown. Never claim rejection or success.
            $this->transitionBatchStatus($batchId, PaymentStateMachine::SUBMITTING, PaymentStateMachine::UNKNOWN, [
                'message' => 'BankConnect transport outcome is unknown: '.$e->getMessage(),
                'date_status' => date('Y-m-d H:i:s'),
            ]);
            $this->logger->error('payment_unknown', ['batch_id' => $batchId, 'error_class' => get_class($e)]);
            throw new BankConnectException('BankConnect submission outcome is unknown; reconcile status before retry', 0, $e);
        }

        $correlationId = null;
        $responseCode = 'OK';
        if (preg_match('/<correlationId>([^<]+)<\/correlationId>/', $response, $m)) $correlationId = $m[1];
        if (preg_match('/<responseCode>([^<]+)<\/responseCode>/', $response, $m)) $responseCode = $m[1];

        $this->transitionBatchStatus($batchId, PaymentStateMachine::SUBMITTING, PaymentStateMachine::SUBMITTED, [
            'response_code' => $responseCode,
            'correlation_id' => $correlationId,
            'date_sent' => date('Y-m-d H:i:s'),
            'date_status' => date('Y-m-d H:i:s'),
            'message' => 'transferPayments accepted by transport layer; awaiting pain.002',
        ]);

        return ['status' => 'submitted', 'response_code' => $responseCode, 'correlation_id' => $correlationId];
    }

    /**
     * Fetch and apply a verified pain.002 status report.
     *
     * A supplied XML string is test-only input; live getStatus() already passes
     * through BankConnectResponseSecurity in BankConnectClient.
     */
    public function refreshStatus(int $batchId, ?string $serviceHeaderXml = null, ?string $pain002Xml = null): array
    {
        $batch = $this->fetchBatch($batchId);
        if (!$batch) throw new BankConnectException("Batch {$batchId} not found");

        if ($pain002Xml === null) {
            if ($this->client === null) throw new BankConnectException('No BankConnectClient and no pain.002 XML provided');
            if (!$serviceHeaderXml) throw new BankConnectException('ServiceHeader XML is required for getStatus');
            $pain002Xml = $this->client->getStatus($serviceHeaderXml);
        }

        $parsed = (new Pain002Parser())->parse($pain002Xml);

        $originalMessageId = trim((string)($parsed['original_msg_id'] ?? ''));
        $batchMsgId = trim((string)($batch['msg_id'] ?? ''));
        if ($originalMessageId !== '' && $batchMsgId !== '' && !hash_equals($batchMsgId, $originalMessageId)) {
            throw new BankConnectException('pain.002 does not belong to the requested payment batch');
        }

        $updated = 0;
        $unknown = 0;
        foreach ($parsed['transactions'] as $tx) {
            $internal = $tx['semantic_status'];
            if ($internal === Pain002Parser::INTERNAL_UNKNOWN) {
                $unknown++;
            }
            if ($tx['end_to_end_id'] === '') {
                continue;
            }

            if ($this->updateBatchLineByEndToEnd(
                $batchId,
                $tx['end_to_end_id'],
                $internal,
                $tx['status'],
                trim(($tx['reason_code'] ?? '').' '.($tx['reason_text'] ?? '')),
                $internal === Pain002Parser::INTERNAL_UNKNOWN
            )) {
                $updated++;
            }
        }

        $batchStatus = $this->deriveBatchStatus($parsed['group_status'], $parsed['transactions']);
        $this->updateBatchStatus($batchId, $batchStatus, [
            'date_status' => date('Y-m-d H:i:s'),
            'message' => 'Status refreshed from verified pain.002',
        ]);
        $this->logger->info('status_refreshed', [
            'batch_id' => $batchId,
            'group_status' => $parsed['group_status'],
            'updated' => $updated,
            'unknown' => $unknown,
        ]);

        return [
            'group_status' => $parsed['group_status'],
            'updated_lines' => $updated,
            'unknown_lines' => $unknown,
            'transactions' => $parsed['transactions'],
        ];
    }

    /**
     * Reconcile a batch whose submission outcome is unknown.
     *
     * This is the only supported recovery path for an unknown submission:
     * query the bank status and require the returned pain.002 to identify
     * this exact batch before changing its persisted state. No resubmission
     * is attempted by this method.
     *
     * @return array{status:string,reconciled:bool,group_status:?string,updated_lines:int,transactions:array}
     */
    public function resolveUnknownBatch(int $batchId, ?string $serviceHeaderXml = null, ?string $pain002Xml = null): array
    {
        $batch = $this->fetchBatch($batchId);
        if (!$batch) {
            throw new BankConnectException("Batch {$batchId} not found");
        }
        if ((string) ($batch['status'] ?? '') !== 'unknown') {
            throw new BankConnectException("Batch {$batchId} is not in unknown status");
        }

        if ($pain002Xml === null) {
            if ($this->client === null) {
                throw new BankConnectException('BankConnectClient is required to reconcile an unknown batch');
            }
            if (!$serviceHeaderXml) {
                throw new BankConnectException('ServiceHeader XML is required to reconcile an unknown batch');
            }
            try {
                $pain002Xml = $this->client->getStatus($serviceHeaderXml);
            } catch (Throwable $e) {
                // Keep UNKNOWN: a failed status lookup provides no evidence about the remote payment.
                $this->logger->error('unknown_batch_reconciliation_failed', [
                    'batch_id' => $batchId,
                    'error' => $e->getMessage(),
                ]);
                throw new BankConnectException('Unable to reconcile unknown batch; remote outcome remains unknown', 0, $e);
            }
        }

        $parsed = (new Pain002Parser())->parse($pain002Xml);
        $expectedMsgId = trim((string) ($batch['msg_id'] ?? ''));
        $reportedMsgId = trim((string) ($parsed['original_msg_id'] ?? ''));

        // Never mutate UNKNOWN unless the bank response is explicitly tied to this batch.
        if ($expectedMsgId === '' || $reportedMsgId === '' || !hash_equals($expectedMsgId, $reportedMsgId)) {
            $this->logger->error('unknown_batch_reconciliation_unmatched', [
                'batch_id' => $batchId,
                'expected_msg_id' => $expectedMsgId,
                'reported_msg_id' => $reportedMsgId,
            ]);
            throw new BankConnectException('Bank status response does not identify the unknown batch; status remains unknown');
        }

        $updated = 0;
        foreach ($parsed['transactions'] as $tx) {
            $endToEndId = trim((string) ($tx['end_to_end_id'] ?? ''));
            if ($endToEndId === '') {
                continue;
            }
            $internal = Pain002Parser::mapToInternalStatus($tx['status']);
            $reason = trim(($tx['reason_code'] ?? '').' '.($tx['reason_text'] ?? ''));
            if ($this->updateBatchLineByEndToEnd($batchId, $endToEndId, $internal, $tx['status'], $reason !== '' ? $reason : null)) {
                $updated++;
            }
        }

        // A matching original message id is necessary, but transaction evidence is also
        // required when the bank supplies transaction-level records. Do not manufacture
        // a successful state from an empty/unrelated transaction list.
        if ($updated === 0 && empty($parsed['transactions'])) {
            $this->logger->error('unknown_batch_reconciliation_no_transactions', ['batch_id' => $batchId]);
            throw new BankConnectException('Bank status response identifies the batch but contains no transaction status; status remains unknown');
        }

        $batchStatus = $this->deriveBatchStatus($parsed['group_status'], $parsed['transactions']);
        if ($batchStatus === 'unknown') {
            throw new BankConnectException('Bank status response contains no resolvable payment status; status remains unknown');
        }

        $this->updateBatchStatus($batchId, $batchStatus, [
            'date_status' => date('Y-m-d H:i:s'),
            'message' => 'Unknown submission reconciled from matching pain.002 status',
        ]);
        $this->logger->info('unknown_batch_reconciled', [
            'batch_id' => $batchId,
            'group_status' => $parsed['group_status'],
            'updated' => $updated,
            'status' => $batchStatus,
        ]);

        return [
            'status' => $batchStatus,
            'reconciled' => true,
            'group_status' => $parsed['group_status'],
            'updated_lines' => $updated,
            'transactions' => $parsed['transactions'],
        ];
    }

    private function buildServiceHeaderForBatch(array $batch): string
    {
        $agreementId = (int) ($batch['fk_agreement'] ?? 0);
        if ($agreementId <= 0) {
            throw new BankConnectException('Payment batch has no valid agreement');
        }

        $res = $this->prefixQuery(
            'SELECT bank_connect_id, main_registration_number '
            .'FROM llx_bankconnect_agreement WHERE rowid = '.$agreementId
        );
        if (!$res) {
            throw new BankConnectException('Failed to load BankConnect agreement for serviceHeader');
        }
        $agreement = $this->db->fetch_object($res);
        if (!$agreement) {
            throw new BankConnectException("BankConnect agreement {$agreementId} not found");
        }

        $mainReg = trim((string) ($agreement->main_registration_number ?? ''));
        $functionId = trim((string) ($agreement->bank_connect_id ?? ''));
        if ($mainReg === '' || $functionId === '') {
            throw new BankConnectException('BankConnect agreement is missing serviceHeader identity');
        }

        return (new ServiceHeaderBuilder())
            ->setOrganisation($mainReg, 'DK')
            ->setFunctionIdentification($functionId)
            ->setEndToEndMessageId((string) $batch['end_to_end_message_id'])
            ->build();
    }

    private function deriveBatchStatus(?string $groupStatus, array $transactions): string
    {
        $group = Pain002Parser::mapGroupToInternalStatus($groupStatus);
        if ($group !== Pain002Parser::INTERNAL_UNKNOWN) return $group;

        $statuses = array_unique(array_map(fn($t) => $t['semantic_status'], $transactions));
        if (count($statuses) === 1) return $statuses[0];
        if (in_array(Pain002Parser::INTERNAL_UNKNOWN, $statuses, true)) return Pain002Parser::INTERNAL_UNKNOWN;
        if (in_array(Pain002Parser::INTERNAL_PARTIAL, $statuses, true)) return Pain002Parser::INTERNAL_PARTIAL;
        if (in_array(Pain002Parser::INTERNAL_REJECTED, $statuses, true) && in_array(Pain002Parser::INTERNAL_ACCEPTED, $statuses, true)) return Pain002Parser::INTERNAL_PARTIAL;
        if (in_array(Pain002Parser::INTERNAL_PENDING, $statuses, true)) return Pain002Parser::INTERNAL_PENDING;
        return 'submitted';
    }

    private function updateBatchLineByEndToEnd(int $batchId, string $endToEndId, string $status, string $pain002Status, ?string $reason, bool $manualReview = false): bool
    {
        $sets = [
            "status = '".$this->db->escape($status)."'",
            "pain002_status = '".$this->db->escape($pain002Status)."'",
        ];
        if ($reason !== null && $reason !== '') $sets[] = "status_reason = '".$this->db->escape(substr($reason, 0, 255))."'";
        if ($manualReview) $sets[] = 'requires_manual_review = 1';

        $sql = 'UPDATE llx_bankconnect_batch_line SET '.implode(', ', $sets)
             .' WHERE fk_batch = '.(int)$batchId
             ." AND end_to_end_id = '".$this->db->escape($endToEndId)."'";
        return (bool)$this->prefixQuery($sql);
    }


    private function begin(): void { if (method_exists($this->db, 'begin')) $this->db->begin(); }
    private function commit(): void { if (method_exists($this->db, 'commit')) $this->db->commit(); }
    private function rollback(): void { if (method_exists($this->db, 'rollback')) $this->db->rollback(); }

    private function insertBatch(array $d): int
    {
        $sql = 'INSERT INTO llx_bankconnect_batch (entity,fk_agreement,end_to_end_message_id,msg_id,status,pain001_xml,control_sum,nb_of_txs,date_sent) VALUES ('
            .(int)$d['entity'].','.(int)$d['fk_agreement'] . ",'".$this->db->escape($d['end_to_end_message_id'])."','".$this->db->escape($d['msg_id'])."','".$this->db->escape($d['status'])."','".$this->db->escape($d['pain001_xml'])."',".(float)$d['control_sum'].','.(int)$d['nb_of_txs'].',NULL)';
        if (!$this->prefixQuery($sql)) throw new BankConnectException('INSERT batch failed: '.$this->db->lasterror());
        return (int)$this->prefixLastInsertId('llx_bankconnect_batch');
    }

    private function insertBatchLine(int $batchId, array $tx): void
    {
        $sql = 'INSERT INTO llx_bankconnect_batch_line (fk_batch,end_to_end_id,amount,currency,fk_facture_fourn,fk_facture,status) VALUES ('
            .$batchId.",'".$this->db->escape($tx['endToEndId'])."',".(float)$tx['amount'].",'".$this->db->escape($tx['currency'] ?? 'DKK')."',"
            .(isset($tx['fk_facture_fourn'])?(int)$tx['fk_facture_fourn']:'NULL').','.(isset($tx['fk_facture'])?(int)$tx['fk_facture']:'NULL').",'draft')";
        if (!$this->prefixQuery($sql)) throw new BankConnectException('INSERT batch_line failed: '.$this->db->lasterror());
    }

    private function fetchBatch(int $batchId): ?array
    {
        $res = $this->prefixQuery('SELECT * FROM llx_bankconnect_batch WHERE rowid = '.(int)$batchId);
        if (!$res) return null;
        $obj = $this->db->fetch_object($res);
        return $obj ? (array)$obj : null;
    }
    private function claimSubmission(int $batchId): bool
    {
        $sql = "UPDATE llx_bankconnect_batch SET status = '".PaymentStateMachine::SUBMITTING."'"
             . " WHERE rowid = ".(int)$batchId
             . " AND status = '".PaymentStateMachine::PREPARED."'";
        if (!$this->prefixQuery($sql)) {
            throw new BankConnectException('Failed to claim payment submission: '.$this->db->lasterror());
        }

        // DoliDB exposes affected_rows() on real database drivers. The
        // fallback SELECT keeps the unit-test DB deterministic while the
        // conditional UPDATE remains the production concurrency boundary.
        if (method_exists($this->db, 'affected_rows')) {
            return (int)$this->db->affected_rows() === 1;
        }

        $current = $this->fetchBatch($batchId);
        return (string)($current['status'] ?? '') === PaymentStateMachine::SUBMITTING;
    }

    private function transitionBatchStatus(int $batchId, string $from, string $to, array $extra = []): void
    {
        PaymentStateMachine::assertTransition($from, $to);
        $sets = ["status = '".$this->db->escape($to)."'"];
        foreach (['response_code','message','correlation_id','date_sent','date_status'] as $field) {
            if (isset($extra[$field])) {
                $sets[] = $field." = '".$this->db->escape($extra[$field])."'";
            }
        }
        $sql = 'UPDATE llx_bankconnect_batch SET '.implode(', ', $sets)
             .' WHERE rowid = '.(int)$batchId
             ." AND status = '".$this->db->escape($from)."'";
        if (!$this->prefixQuery($sql)) {
            throw new BankConnectException('Payment state transition failed: '.$this->db->lasterror());
        }

        if (method_exists($this->db, 'affected_rows')) {
            if ((int)$this->db->affected_rows() !== 1) {
                throw new BankConnectException("Payment batch {$batchId} state changed concurrently");
            }
            return;
        }

        $current = $this->fetchBatch($batchId);
        if ((string)($current['status'] ?? '') !== $to) {
            throw new BankConnectException("Payment batch {$batchId} state transition did not persist");
        }
    }

    private function updateBatchStatus(int $batchId, string $status, array $extra = []): void
    {
        $sets = ["status = '".$this->db->escape($status)."'"];
        foreach (['response_code','message','correlation_id','date_sent','date_status'] as $field) {
            if (isset($extra[$field])) $sets[] = $field." = '".$this->db->escape($extra[$field])."'";
        }
        $this->prefixQuery('UPDATE llx_bankconnect_batch SET '.implode(', ', $sets).' WHERE rowid = '.(int)$batchId);
    }

    private function generateEndToEndMessageId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
