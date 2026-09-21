<?php
/**
 * PaymentBatchService – orchestrates pain.001 creation, persistence, transfer and status.
 */

require_once __DIR__.'/Pain001Builder.php';
require_once __DIR__.'/Pain002Parser.php';
require_once __DIR__.'/BankConnectClient.php';
require_once __DIR__.'/BankConnectException.php';
require_once __DIR__.'/BankConnectLogger.php';
require_once __DIR__.'/ServiceHeaderBuilder.php';

if (!class_exists('Conf')) {
    class Conf { public $global = []; }
}

class PaymentBatchService
{
    private $db;
    private Conf $conf;
    private BankConnectLogger $logger;
    private ?BankConnectClient $client;

    public function __construct($db, Conf $conf, ?BankConnectClient $client = null, ?BankConnectLogger $logger = null)
    {
        $this->db = $db;
        $this->conf = $conf;
        $this->client = $client;
        $this->logger = $logger ?? new BankConnectLogger();
    }

    /** @return array{batch_id:int,end_to_end_message_id:string,msg_id:string,nb_of_txs:int,control_sum:float} */
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
            $this->logger->info('batch_created', ['batch_id' => $batchId, 'e2e' => $e2eMessageId]);
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
     * Submit a prepared payment batch.
     *
     * A transport timeout after preparation is deliberately persisted as `unknown`.
     * It must never be retried blindly because the bank may already have accepted it.
     *
     * @return array{status:string,response_code:?string,correlation_id:?string}
     */
    public function sendBatch(int $batchId): array
    {
        $batch = $this->fetchBatch($batchId);
        if (!$batch) {
            throw new BankConnectException("Batch {$batchId} not found");
        }
        $status = (string) ($batch['status'] ?? '');
        if (!in_array($status, ['draft', 'prepared'], true)) {
            throw new BankConnectException("Batch {$batchId} cannot be submitted from status {$status}");
        }
        if ($this->client === null) {
            throw new BankConnectException('BankConnectClient is required for payment submission');
        }

        $this->updateBatchStatus($batchId, 'prepared', [
            'message' => 'Payment payload prepared for BankConnect submission',
        ]);

        try {
            require_once __DIR__.'/BankConnectXmlSecurity.php';
            $security = new BankConnectXmlSecurity($this->conf);
            $built = $security->buildTransferPayment(
                $batch['pain001_xml'],
                $batch['end_to_end_message_id']
            );
            $paymentMessage = $built['xml'];
            $serviceHeader = $this->buildServiceHeaderForBatch($batch);
            $paymentMessage = preg_replace(
                '/^(<transferPayment\\b[^>]*>)/',
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
            $this->updateBatchStatus($batchId, 'unknown', [
                'message' => 'BankConnect transport outcome is unknown: '.$e->getMessage(),
                'date_status' => date('Y-m-d H:i:s'),
            ]);
            throw new BankConnectException('BankConnect submission outcome is unknown; status must be reconciled before retry', 0, $e);
        }

        $correlationId = null;
        $responseCode = 'OK';
        if (preg_match('/<correlationId>([^<]+)<\/correlationId>/', $response, $m)) {
            $correlationId = $m[1];
        }
        if (preg_match('/<responseCode>([^<]+)<\/responseCode>/', $response, $m)) {
            $responseCode = $m[1];
        }

        $this->updateBatchStatus($batchId, 'submitted', [
            'response_code' => $responseCode,
            'correlation_id' => $correlationId,
            'date_sent' => date('Y-m-d H:i:s'),
            'date_status' => date('Y-m-d H:i:s'),
            'message' => 'transferPayments accepted by transport layer; awaiting pain.002',
        ]);

        return ['status' => 'submitted', 'response_code' => $responseCode, 'correlation_id' => $correlationId];
    }

    /** @return array{group_status:?string,updated_lines:int,transactions:array} */
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
        $updated = 0;
        foreach ($parsed['transactions'] as $tx) {
            $internal = Pain002Parser::mapToInternalStatus($tx['status']);
            $reason = trim(($tx['reason_code'] ?? '').' '.($tx['reason_text'] ?? ''));
            if ($this->updateBatchLineByEndToEnd($batchId, $tx['end_to_end_id'], $internal, $tx['status'], $reason !== '' ? $reason : null)) $updated++;
        }
        $batchStatus = $this->deriveBatchStatus($parsed['group_status'], $parsed['transactions']);
        $this->updateBatchStatus($batchId, $batchStatus, ['date_status' => date('Y-m-d H:i:s'), 'message' => 'Status refreshed from pain.002']);
        $this->logger->info('status_refreshed', ['batch_id' => $batchId, 'group_status' => $parsed['group_status'], 'updated' => $updated]);
        return ['group_status' => $parsed['group_status'], 'updated_lines' => $updated, 'transactions' => $parsed['transactions']];
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

        $res = $this->db->query(
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
        if ($groupStatus === 'ACCP' || $groupStatus === 'ACSC') return 'accepted';
        if ($groupStatus === 'RJCT') return 'rejected';
        if ($groupStatus === 'PART') return 'partial';
        $statuses = array_unique(array_map(fn($t) => Pain002Parser::mapToInternalStatus($t['status']), $transactions));
        if (count($statuses) === 1) return $statuses[0];
        if (in_array('rejected', $statuses, true) && in_array('accepted', $statuses, true)) return 'partial';
        if (in_array('pending', $statuses, true)) return 'pending';
        return 'submitted';
    }

    private function updateBatchLineByEndToEnd(int $batchId, string $endToEndId, string $status, string $pain002Status, ?string $reason): bool
    {
        $sets = ["status = '".$this->db->escape($status)."'", "pain002_status = '".$this->db->escape($pain002Status)."'"];
        if ($reason !== null) $sets[] = "status_reason = '".$this->db->escape(substr($reason, 0, 255))."'";
        return (bool) $this->db->query("UPDATE llx_bankconnect_batch_line SET ".implode(', ', $sets)." WHERE fk_batch = ".(int)$batchId." AND end_to_end_id = '".$this->db->escape($endToEndId)."'");
    }
    private function begin(): void { if (method_exists($this->db, 'begin')) $this->db->begin(); }
    private function commit(): void { if (method_exists($this->db, 'commit')) $this->db->commit(); }
    private function rollback(): void { if (method_exists($this->db, 'rollback')) $this->db->rollback(); }
    private function insertBatch(array $d): int
    {
        $sql = "INSERT INTO llx_bankconnect_batch (entity,fk_agreement,end_to_end_message_id,msg_id,status,pain001_xml,control_sum,nb_of_txs,date_sent) VALUES (".(int)$d['entity'].",".(int)$d['fk_agreement'].",'".$this->db->escape($d['end_to_end_message_id'])."','".$this->db->escape($d['msg_id'])."','".$this->db->escape($d['status'])."','".$this->db->escape($d['pain001_xml'])."',".(float)$d['control_sum'].",".(int)$d['nb_of_txs'].",NULL)";
        if (!$this->db->query($sql)) throw new BankConnectException('INSERT batch failed: '.$this->db->lasterror());
        return (int)$this->db->last_insert_id('llx_bankconnect_batch');
    }
    private function insertBatchLine(int $batchId, array $tx): void
    {
        $sql = "INSERT INTO llx_bankconnect_batch_line (fk_batch,end_to_end_id,amount,currency,fk_facture_fourn,fk_facture,status) VALUES (".$batchId.",'".$this->db->escape($tx['endToEndId'])."',".(float)$tx['amount'].",'".$this->db->escape($tx['currency'] ?? 'DKK')."',".(isset($tx['fk_facture_fourn'])?(int)$tx['fk_facture_fourn']:'NULL').",".(isset($tx['fk_facture'])?(int)$tx['fk_facture']:'NULL').",'draft')";
        if (!$this->db->query($sql)) throw new BankConnectException('INSERT batch_line failed: '.$this->db->lasterror());
    }
    private function fetchBatch(int $batchId): ?array
    {
        $res = $this->db->query('SELECT * FROM llx_bankconnect_batch WHERE rowid = '.(int)$batchId);
        if (!$res) return null;
        $obj = $this->db->fetch_object($res);
        return $obj ? (array)$obj : null;
    }
    private function updateBatchStatus(int $batchId, string $status, array $extra = []): void
    {
        $sets = ["status = '".$this->db->escape($status)."'"];
        foreach (['response_code','message','correlation_id','date_sent','date_status'] as $field) {
            if (isset($extra[$field])) $sets[] = $field." = '".$this->db->escape($extra[$field])."'";
        }
        $this->db->query('UPDATE llx_bankconnect_batch SET '.implode(', ', $sets).' WHERE rowid = '.(int)$batchId);
    }
    private function generateEndToEndMessageId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
