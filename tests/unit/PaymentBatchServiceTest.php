<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/MockDoliDB.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectException.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/Pain001Builder.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/PaymentBatchService.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectLogger.php';

class PaymentBatchServiceTest extends TestCase
{
    private MockDoliDB $db;
    private Conf $conf;
    private PaymentBatchService $svc;

    protected function setUp(): void
    {
        $this->db = new MockDoliDB();
        $this->conf = new Conf();
        $this->svc = new PaymentBatchService($this->db, $this->conf, null); // no live client

        // Ensure tables exist in mock
        $this->db->query("CREATE TABLE llx_bankconnect_batch (
            rowid INTEGER PRIMARY KEY AUTOINCREMENT,
            entity INTEGER, fk_agreement INTEGER,
            end_to_end_message_id TEXT, msg_id TEXT, correlation_id TEXT,
            status TEXT, pain001_xml TEXT, response_code TEXT, message TEXT,
            control_sum REAL, nb_of_txs INTEGER, date_sent TEXT, date_status TEXT
        )");
        $this->db->query("CREATE TABLE llx_bankconnect_batch_line (
            rowid INTEGER PRIMARY KEY AUTOINCREMENT,
            fk_batch INTEGER, end_to_end_id TEXT, amount REAL, currency TEXT,
            fk_facture_fourn INTEGER, fk_facture INTEGER, fk_paiement INTEGER,
            status TEXT, pain002_status TEXT, status_reason TEXT
        )");
    }

    private function sampleBuilder(): Pain001Builder
    {
        return (new Pain001Builder())
            ->setInitiatingParty('Test ApS')
            ->setDebtor('Test ApS', 'DK5089000000012345', 'SXPYDKKK')
            ->setExecutionDate(new DateTimeImmutable('2026-09-20'))
            ->addTransaction([
                'endToEndId'       => 'E2E-FA240891',
                'amount'           => 12450.00,
                'currency'         => 'DKK',
                'creditorName'     => 'ABC A/S',
                'creditorIban'     => 'DK0689000000054321',
                'fk_facture_fourn' => 42,
            ]);
    }

    public function testCreateBatchPersistsHeaderAndLines(): void
    {
        $result = $this->svc->createBatch($this->sampleBuilder(), 1, 1);

        $this->assertArrayHasKey('batch_id', $result);
        $this->assertGreaterThan(0, $result['batch_id']);
        $this->assertSame(1, $result['nb_of_txs']);
        $this->assertEqualsWithDelta(12450.00, $result['control_sum'], 0.001);
        $this->assertLessThanOrEqual(35, strlen($result['end_to_end_message_id']));
        $this->assertLessThanOrEqual(35, strlen($result['msg_id']));

        // Verify line
        $res = $this->db->query('SELECT * FROM llx_bankconnect_batch_line WHERE fk_batch = '.$result['batch_id']);
        $line = $this->db->fetch_object($res);
        $this->assertNotNull($line);
        $this->assertSame('E2E-FA240891', $line->end_to_end_id);
        $this->assertEqualsWithDelta(12450.00, (float) $line->amount, 0.001);
        $this->assertSame(42, (int) $line->fk_facture_fourn);
    }

    public function testSendBatchStubMarksSent(): void
    {
        $created = $this->svc->createBatch($this->sampleBuilder(), 1);
        $sent = $this->svc->sendBatch($created['batch_id']);

        $this->assertSame('sent', $sent['status']);
        $this->assertSame('STUB', $sent['response_code']);

        $res = $this->db->query('SELECT status, response_code FROM llx_bankconnect_batch WHERE rowid = '.$created['batch_id']);
        $row = $this->db->fetch_object($res);
        $this->assertSame('sent', $row->status);
        $this->assertSame('STUB', $row->response_code);
    }

    public function testSendNonDraftThrows(): void
    {
        $created = $this->svc->createBatch($this->sampleBuilder(), 1);
        $this->svc->sendBatch($created['batch_id']); // now status=sent

        $this->expectException(BankConnectException::class);
        $this->svc->sendBatch($created['batch_id']);
    }

    public function testSendUnknownBatchThrows(): void
    {
        $this->expectException(BankConnectException::class);
        $this->svc->sendBatch(99999);
    }
}
