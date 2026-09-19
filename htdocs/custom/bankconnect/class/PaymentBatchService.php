<?php
/**
 * PaymentBatchService – orchestrates pain.001 creation, persistence and transfer.
 *
 * Flow:
 *  1. Build pain.001 via Pain001Builder
 *  2. Insert llx_bankconnect_batch + batch_line
 *  3. (Later) Encrypt + sign + call BankConnectClient::transferPayments
 *  4. Store response / correlationId
 *
 * Currently the actual SOAP transfer is stubbed until XML-Signature/
 * Encryption is fully implemented.
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
    private $db; // DoliDB or MockDoliDB
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
     * Create a batch from a fully configured Pain001Builder.
     *
     * @param Pain001Builder $builder
     * @param int            $fkAgreement  llx_bankconnect_agreement.rowid
     * @param int            $entity
     * @return array{batch_id:int, end_to_end_message_id:string, msg_id:string, nb_of_txs:int, control_sum:float}
     * @throws BankConnectException
     */
    public function createBatch(Pain001Builder $builder, int $fkAgreement, int $entity = 1): array
    {
        $xml      = $builder->build();
        $msgId    = $builder->getMsgId();
        $ctrlSum  = $builder->getControlSum();
        $txs      = $builder->getTransactions();
        $nbOfTxs  = count($txs);

        // endToEndMessageId for ServiceHeader – unique per request, max 35
        $e2eMessageId = $this->generateEndToEndMessageId();

        $this->db->begin();

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

            $this->db->commit();

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
            $this->db->rollback();
            throw new BankConnectException('Failed to create payment batch: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Mark batch as sent and (when client is available) call transferPayments.
     *
     * @return array{status:string, response_code:?string, correlation_id:?string}
     */
    public function sendBatch(int $batchId): array
    {
        $batch = $this->fetchBatch($batchId);
        if (!$batch) {
            throw new BankConnectException("Batch {$batchId} not found");
        }
        if ($batch['status'] !== 'draft') {
            throw new BankConnectException("Batch {$batchId} is not in draft status");
        }

        // Stub: real implementation will encrypt payload, sign, and call client
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

        // Future path:
        // $encrypted = $this->encryptPayload($batch['pain001_xml']);
        // $signed    = $this->signRequest($encrypted, $batch['end_to_end_message_id']);
        // $response  = $this->client->transferPayments($signed, $batch['end_to_end_message_id']);
        // parse response → update status + correlation_id

        throw new BankConnectException('Live transferPayments not yet implemented');
    }

    // ------------------------------------------------------------------
    // Persistence helpers (work with both real DoliDB and MockDoliDB)
    // ------------------------------------------------------------------

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
