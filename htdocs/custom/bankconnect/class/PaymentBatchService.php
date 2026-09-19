<?php
/**
 * PaymentBatchService – orchestrates pain.001 creation, persistence and transfer.
 *
 * Flow:
 *  1. Build pain.001 via Pain001Builder
 *  2. Insert llx_bankconnect_batch + batch_line
 *  3. Encrypt + sign + call BankConnectClient::transferPayments (when wired)
 *  4. Store response / correlationId
 */

require_once __DIR__.'/Pain001Builder.php';
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
                'status'                => 'draft',
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
     * @return array{status:string, response_code:?string, correlation_id:?string}
     */
    public function sendBatch(int $batchId): array
    {
        $batch = $this->fetchBatch($batchId);
        if (!$batch) {
            throw new BankConnectException("Batch {$batchId} not found");
        }
        if (($batch['status'] ?? '') !== 'draft') {
            throw new BankConnectException("Batch {$batchId} is not in draft status");
        }

        if ($this->client === null) {
            $this->updateBatchStatus($batchId, 'sent', [
                'response_code' => 'STUB',
                'message'       => 'SOAP transfer not yet wired – marked sent for testing',
                'date_sent'     => date('Y-m-d H:i:s'),
            ]);

            return [
                'status'         => 'sent',
                'response_code'  => 'STUB',
                'correlation_id' => null,
            ];
        }

        // Live path (requires BankConnectXmlSecurity + certificates)
        require_once __DIR__.'/BankConnectXmlSecurity.php';
        $security = new BankConnectXmlSecurity($this->conf);

        $encrypted = $security->encryptPayload($batch['pain001_xml']);
        $paymentMessage = $security->buildPaymentMessage($encrypted, $batch['end_to_end_message_id']);
        $signed = $security->signRequest($paymentMessage);

        $response = $this->client->transferPayments($signed, $batch['end_to_end_message_id']);

        // Minimal parse – full PaymentResponse parsing later
        $correlationId = null;
        $responseCode  = 'OK';
        if (preg_match('/<correlationId>([^<]+)<\/correlationId>/', $response, $m)) {
            $correlationId = $m[1];
        }
        if (preg_match('/<responseCode>([^<]+)<\/responseCode>/', $response, $m)) {
            $responseCode = $m[1];
        }

        $this->updateBatchStatus($batchId, 'sent', [
            'response_code'  => $responseCode,
            'correlation_id' => $correlationId,
            'date_sent'      => date('Y-m-d H:i:s'),
            'message'        => 'transferPayments accepted',
        ]);

        return [
            'status'         => 'sent',
            'response_code'  => $responseCode,
            'correlation_id' => $correlationId,
        ];
    }

    // ------------------------------------------------------------------

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
