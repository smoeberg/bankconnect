<?php
/**
 * PaymentBatchService – orchestrates pain.001 creation, persistence, transfer and status.
 */

require_once __DIR__.'/Pain001Builder.php';
require_once __DIR__.'/Pain002Parser.php';
require_once __DIR__.'/BankConnectClient.php';
require_once __DIR__.'/BankConnectException.php';
require_once __DIR__.'/BankConnectLogger.php';

if (!class_exists('Conf')) {
    class Conf
    {
        /** @var array<string,mixed> */
        public $global = [];
    }
}

class PaymentBatchService
{
    private const STATUS_DRAFT = 'draft';
    private const STATUS_PREPARED = 'prepared';
    private const STATUS_SUBMITTED = 'submitted';
    private const STATUS_UNKNOWN = 'unknown';
    private const STATUS_ACCEPTED = 'accepted';
    private const STATUS_PARTIAL = 'partial';
    private const STATUS_REJECTED = 'rejected';
    private const STATUS_PENDING = 'pending';

    private $db;
    private Conf $conf;
    private BankConnectLogger $logger;
    private ?BankConnectClient $client;

    public function __construct($db, Conf $conf, ?BankConnectClient $client = null, ?BankConnectLogger $logger = null)
    {
        $this->db     = $db;
        $this->conf   = $conf;
        $this->client = $client;
        $this->logger = $logger ?? new BankConnectLogger();
    }

    /**
     * @return array{batch_id:int, end_to_end_message_id:string, msg_id:string, nb_of_txs:int, control_sum:float}
     */
    public function createBatch(Pain001Builder $builder, int $fkAgreement, int $entity = 1): array
    {
        $xml     = $builder->build();
        $msgId   = $builder->getMsgId();
        $ctrlSum = $builder->getControlSum();
        $txs     = $builder->getTransactions();
        $nbOfTxs = count($txs);
        $e2eMessageId = $this->generateEndToEndMessageId();

        $this->begin();

        try {
            $batchId = $this->insertBatch([
                'entity'                => $entity,
                'fk_agreement'          => $fkAgreement,
                'end_to_end_message_id' => $e2eMessageId,
                'msg_id'                => $msgId,
                'status'                => self::STATUS_DRAFT,
                'pain001_xml'           => $xml,
                'control_sum'           => $ctrlSum,
                'nb_of_txs'             => $nbOfTxs,
            ]);

            foreach ($txs as $tx) {
                $this->insertBatchLine($batchId, $tx);
            }

            $this->commit();

            $this->logger->info('batch_created', [
                'batch_id' => $batchId,
                'e2e'      => $e2eMessageId,
                'nb'       => $nbOfTxs,
                'sum'      => $ctrlSum,
            ]);

            return [
                'batch_id'              => $batchId,
                'end_to_end_message_id' => $e2eMessageId,
                'msg_id'                => $msgId,
                'nb_of_txs'             => $nbOfTxs,
                'control_sum'           => $ctrlSum,
            ];
        } catch (Throwable $e) {
            $this->rollback();
            throw new BankConnectException('Failed to create payment batch: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Submit a draft payment batch to BankConnect.
     *
     * draft -> prepared -> submitted
     * prepared/submitted transport uncertainty -> unknown
     *
     * An absent client is an integration error and MUST NOT be represented as
     * a successful bank submission.
     *
     * @return array{status:string, response_code:?string, correlation_id:?string}
     */
    public function sendBatch(int $batchId): array
    {
        $batch = $this->fetchBatch($batchId);
        if (!$batch) {
            throw new BankConnectException("Batch {$batchId} not found");
        }
        if (($batch['status'] ?? '') !== self::STATUS_DRAFT) {
            throw new BankConnectException("Batch {$batchId} is not in draft status");
        }
        if ($this->client === null) {
            throw new BankConnectException('BankConnectClient is required to submit a payment batch');
        }

        $this->updateBatchStatus($batchId, self::STATUS_PREPARED, [
            'message' => 'Payment payload prepared for BankConnect submission',
        ]);

        try {
            require_once __DIR__.'/BankConnectXmlSecurity.php';
            $security = new BankConnectXmlSecurity($this->conf);

            $encrypted = $security->encryptPayload($batch['pain001_xml']);
            $paymentMessage = $security->buildPaymentMessage($encrypted, $batch['end_to_end_message_id']);
            $signed = $security->signRequest($paymentMessage);

            // Once handed to the transport, a timeout/failure cannot prove
            // whether the bank received it. Persist UNKNOWN rather than
            // allowing a caller to treat the operation as a safe retry.
            $this->updateBatchStatus($batchId, self::STATUS_SUBMITTED, [
                'message' => 'Payment request submitted to BankConnect transport',
            ]);

            $response = $this->client->transferPayments($signed, $batch['end_to_end_message_id']);

            $correlationId = null;
            $responseCode  = 'OK';
            if (preg_match('/<correlationId>([^<]+)<\/correlationId>/', $response, $m)) {
                $correlationId = $m[1];
            }
            if (preg_match('/<responseCode>([^<]+)<\/responseCode>/', $response, $m)) {
                $responseCode = $m[1];
            }

            $this->updateBatchStatus($batchId, self::STATUS_SUBMITTED, [
                'response_code'  => $responseCode,
                'correlation_id' => $correlationId,
                'date_sent'      => date('Y-m-d H:i:s'),
                'message'        => 'transferPayments accepted by transport',
            ]);

            return [
                'status'         => self::STATUS_SUBMITTED,
                'response_code'  => $responseCode,
                'correlation_id' => $correlationId,
            ];
        } catch (Throwable $e) {
            $this->updateBatchStatus($batchId, self::STATUS_UNKNOWN, [
                'message' => 'BankConnect submission outcome is unknown: '.$e->getMessage(),
            ]);
            $this->logger->error('payment_submission_unknown', [
                'batch_id' => $batchId,
                'error'    => $e->getMessage(),
            ]);
            throw new BankConnectException(
                'BankConnect submission outcome is unknown; do not retry blindly: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Fetch status via getStatus (pain.002) and update batch lines.
     *
     * @param string|null $serviceHeaderXml  Pre-built ServiceHeader; required for live calls
     * @param string|null $pain002Xml        Optional: inject XML for tests (skips SOAP)
     * @return array{group_status:?string, updated_lines:int, transactions:array}
     */
    public function refreshStatus(int $batchId, ?string $serviceHeaderXml = null, ?string $pain002Xml = null): array
    {
        $batch = $this->fetchBatch($batchId);
        if (!$batch) {
            throw new BankConnectException("Batch {$batchId} not found");
        }

        if ($pain002Xml === null) {
            if ($this->client === null) {
                throw new BankConnectException('No BankConnectClient and no pain.002 XML provided');
            }
            if ($serviceHeaderXml === null || $serviceHeaderXml === '') {
                throw new BankConnectException('ServiceHeader XML is required for getStatus');
            }
            $raw = $this->client->getStatus($serviceHeaderXml);
            $pain002Xml = $raw;
        }

        $parser = new Pain002Parser();
        $parsed = $parser->parse($pain002Xml);

        $updated = 0;
        foreach ($parsed['transactions'] as $tx) {
            $internal = Pain002Parser::mapToInternalStatus($tx['status']);
            $reason = trim(($tx['reason_code'] ?? '').' '.($tx['reason_text'] ?? ''));
            if ($this->updateBatchLineByEndToEnd(
                $batchId,
                $tx['end_to_end_id'],
                $internal,
                $tx['status'],
                $reason !== '' ? $reason : null
            )) {
                $updated++;
            }
        }

        $batchStatus = $this->deriveBatchStatus($parsed['group_status'], $parsed['transactions']);
        $this->updateBatchStatus($batchId, $batchStatus, [
            'date_status' => date('Y-m-d H:i:s'),
            'message'     => 'Status refreshed from pain.002',
        ]);

        $this->logger->info('status_refreshed', [
            'batch_id'     => $batchId,
            'group_status' => $parsed['group_status'],
            'updated'      => $updated,
        ]);

        return [
            'group_status'  => $parsed['group_status'],
            'updated_lines' => $updated,
            'transactions'  => $parsed['transactions'],
        ];
    }

    private function deriveBatchStatus(?string $groupStatus, array $transactions): string
    {
        if ($groupStatus === 'ACCP' || $groupStatus === 'ACSC') {
            return self::STATUS_ACCEPTED;
        }
        if ($groupStatus === 'RJCT') {
            return self::STATUS_REJECTED;
        }
        if ($groupStatus === 'PART') {
            return self::STATUS_PARTIAL;
        }

        $statuses = array_unique(array_map(
            fn($t) => Pain002Parser::mapToInternalStatus($t['status']),
            $transactions
        ));

        if (count($statuses) === 1) {
            return $statuses[0];
        }
        if (in_array(self::STATUS_REJECTED, $statuses, true) && in_array(self::STATUS_ACCEPTED, $statuses, true)) {
            return self::STATUS_PARTIAL;
        }
        if (in_array(self::STATUS_PENDING, $statuses, true)) {
            return self::STATUS_PENDING;
        }
        return self::STATUS_SUBMITTED;
    }

    private function updateBatchLineByEndToEnd(
        int $batchId,
        string $endToEndId,
        string $status,
        string $pain002Status,
        ?string $reason
    ): bool {
        $sets = [
            "status = '".$this->db->escape($status)."'",
            "pain002_status = '".$this->db->escape($pain002Status)."'",
        ];
        if ($reason !== null) {
            $sets[] = "status_reason = '".$this->db->escape(substr($reason, 0, 255))."'";
        }

        $sql = "UPDATE llx_bankconnect_batch_line SET ".implode(', ', $sets)
             . " WHERE fk_batch = ".(int) $batchId
             . " AND end_to_end_id = '".$this->db->escape($endToEndId)."'";

        return (bool) $this->db->query($sql);
    }

    private function begin(): void
    {
        if (method_exists($this->db, 'begin')) {
            $this->db->begin();
        }
    }

    private function commit(): void
    {
        if (method_exists($this->db, 'commit')) {
            $this->db->commit();
        }
    }

    private function rollback(): void
    {
        if (method_exists($this->db, 'rollback')) {
            $this->db->rollback();
        }
    }

    private function insertBatch(array $data): int
    {
        $sql = "INSERT INTO llx_bankconnect_batch"
             . " (entity, fk_agreement, end_to_end_message_id, msg_id, status,"
             . "  pain001_xml, control_sum, nb_of_txs, date_sent)"
             . " VALUES ("
             . (int) $data['entity'] . ", "
             . (int) $data['fk_agreement'] . ", "
             . "'".$this->db->escape($data['end_to_end_message_id'])."', "
             . "'".$this->db->escape($data['msg_id'])."', "
             . "'".$this->db->escape($data['status'])."', "
             . "'".$this->db->escape($data['pain001_xml'])."', "
             . (float) $data['control_sum'] . ", "
             . (int) $data['nb_of_txs'] . ", "
             . "NULL"
             . ")";

        $res = $this->db->query($sql);
        if (!$res) {
            throw new BankConnectException('INSERT batch failed: '.$this->db->lasterror());
        }
        return (int) $this->db->last_insert_id('llx_bankconnect_batch');
    }

    private function insertBatchLine(int $batchId, array $tx): void
    {
        $sql = "INSERT INTO llx_bankconnect_batch_line"
             . " (fk_batch, end_to_end_id, amount, currency, fk_facture_fourn, fk_facture, status)"
             . " VALUES ("
             . $batchId . ", "
             . "'".$this->db->escape($tx['endToEndId'])."', "
             . (float) $tx['amount'] . ", "
             . "'".$this->db->escape($tx['currency'] ?? 'DKK')."', "
             . (isset($tx['fk_facture_fourn']) ? (int) $tx['fk_facture_fourn'] : 'NULL') . ", "
             . (isset($tx['fk_facture']) ? (int) $tx['fk_facture'] : 'NULL') . ", "
             . "'draft'"
             . ")";

        $res = $this->db->query($sql);
        if (!$res) {
            throw new BankConnectException('INSERT batch_line failed: '.$this->db->lasterror());
        }
    }

    private function fetchBatch(int $batchId): ?array
    {
        $sql = "SELECT * FROM llx_bankconnect_batch WHERE rowid = ".(int) $batchId;
        $res = $this->db->query($sql);
        if (!$res) {
            return null;
        }
        $obj = $this->db->fetch_object($res);
        if (!$obj) {
            return null;
        }
        return (array) $obj;
    }

    private function updateBatchStatus(int $batchId, string $status, array $extra = []): void
    {
        $sets = ["status = '".$this->db->escape($status)."'"];
        if (isset($extra['response_code'])) {
            $sets[] = "response_code = '".$this->db->escape($extra['response_code'])."'";
        }
        if (isset($extra['message'])) {
            $sets[] = "message = '".$this->db->escape($extra['message'])."'";
        }
        if (isset($extra['correlation_id'])) {
            $sets[] = "correlation_id = '".$this->db->escape($extra['correlation_id'])."'";
        }
        if (isset($extra['date_sent'])) {
            $sets[] = "date_sent = '".$this->db->escape($extra['date_sent'])."'";
        }
        if (isset($extra['date_status'])) {
            $sets[] = "date_status = '".$this->db->escape($extra['date_status'])."'";
        }

        $sql = "UPDATE llx_bankconnect_batch SET ".implode(', ', $sets)
             . " WHERE rowid = ".(int) $batchId;
        $this->db->query($sql);
    }

    private function generateEndToEndMessageId(): string
    {
        return str_replace('-', '', sprintf(
            '%04x%04x%04x%04x%04x%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff),
            mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        ));
    }
}
