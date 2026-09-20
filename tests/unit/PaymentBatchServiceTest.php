<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/MockDoliDB.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectException.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/Pain001Builder.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/Pain002Parser.php';
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
        $this->svc = new PaymentBatchService($this->db, $this->conf, null);
        $this->db->tables['llx_bankconnect_batch'] = [];
        $this->db->tables['llx_bankconnect_batch_line'] = [];
    }

    private function sampleBuilder(): Pain001Builder
    {
        return (new Pain001Builder())
            ->setInitiatingParty('Test ApS')
            ->setDebtor('Test ApS', 'DK5089000000012345', 'SXPYDKKK')
            ->setExecutionDate(new DateTimeImmutable('2026-09-20'))
            ->addTransaction([
                'endToEndId' => 'E2E-FA240891', 'amount' => 12450.00, 'currency' => 'DKK',
                'creditorName' => 'ABC A/S', 'creditorIban' => 'DK0689000000054321', 'fk_facture_fourn' => 42,
            ]);
    }

    public function testCreateBatchPersistsHeaderAndLinesAsDraft(): void
    {
        $result = $this->svc->createBatch($this->sampleBuilder(), 1, 1);
        $this->assertGreaterThan(0, $result['batch_id']);
        $this->assertSame(1, $result['nb_of_txs']);
        $this->assertEqualsWithDelta(12450.00, $result['control_sum'], 0.001);
        $this->assertLessThanOrEqual(35, strlen($result['end_to_end_message_id']));
        $res = $this->db->query('SELECT status FROM llx_bankconnect_batch WHERE rowid = '.$result['batch_id']);
        $this->assertSame('draft', $this->db->fetch_object($res)->status);
        $res = $this->db->query('SELECT * FROM llx_bankconnect_batch_line WHERE fk_batch = '.$result['batch_id']);
        $line = $this->db->fetch_object($res);
        $this->assertSame('E2E-FA240891', $line->end_to_end_id);
        $this->assertSame('draft', $line->status);
    }

    public function testSendWithoutClientFailsClosedAndLeavesDraft(): void
    {
        $created = $this->svc->createBatch($this->sampleBuilder(), 1);
        try {
            $this->svc->sendBatch($created['batch_id']);
            $this->fail('Expected BankConnectClient requirement');
        } catch (BankConnectException $e) {
            $this->assertStringContainsString('BankConnectClient is required', $e->getMessage());
        }
        $res = $this->db->query('SELECT status, response_code FROM llx_bankconnect_batch WHERE rowid = '.$created['batch_id']);
        $row = $this->db->fetch_object($res);
        $this->assertSame('draft', $row->status);
        $this->assertNull($row->response_code);
    }

    public function testSendAttachesServiceHeaderToTransferPaymentRoot(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $private = '';
        $this->assertTrue(openssl_pkey_export($key, $private));
        $csr = openssl_csr_new(['commonName' => 'bankconnect-test'], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($cert);
        $certificate = '';
        $this->assertTrue(openssl_x509_export($cert, $certificate));

        $this->conf->global['BANKCONNECT_CUSTOMER_PRIVATE_KEY'] = $private;
        $this->conf->global['BANKCONNECT_CUSTOMER_CERTIFICATE'] = $certificate;
        $this->db->tables['llx_bankconnect_agreement'] = [[
            'rowid' => 1,
            'bank_connect_id' => 'AGREEMENT-1',
            'main_registration_number' => '12345678',
        ]];

        $client = new class($this->conf) extends BankConnectClient {
            public string $lastPayload = '';

            public function transferPayments(string $paymentMessageXml, string $endToEndMessageId): string
            {
                $this->lastPayload = $paymentMessageXml;
                return '<response><responseCode>OK</responseCode></response>';
            }
        };
        $svc = new PaymentBatchService($this->db, $this->conf, $client);
        $created = $svc->createBatch($this->sampleBuilder(), 1);

        $result = $svc->sendBatch($created['batch_id']);

        $this->assertSame('submitted', $result['status']);
        $this->assertStringContainsString('<transferPayment', $client->lastPayload);
        $this->assertStringContainsString('<serviceHeader', $client->lastPayload);

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($client->lastPayload));

        $root = $dom->documentElement;
        $this->assertSame('transferPayment', $root->localName);

        $serviceHeaders = $root->getElementsByTagNameNS('http://bankconnect.dk/schema/2014', 'serviceHeader');
        $this->assertSame(1, $serviceHeaders->length);
        $this->assertSame($root, $serviceHeaders->item(0)->parentNode);

        $paymentMessages = $root->getElementsByTagNameNS('http://bankconnect.dk/schema/2014', 'paymentMessage');
        $this->assertSame(1, $paymentMessages->length);
        $this->assertSame($root, $paymentMessages->item(0)->parentNode);
    }

    public function testSendUnknownBatchThrows(): void
    {
        $this->expectException(BankConnectException::class);
        $this->svc->sendBatch(99999);
    }

    public function testRefreshStatusUpdatesLines(): void
    {
        $created = $this->svc->createBatch($this->sampleBuilder(), 1);
        $pain002 = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.002.001.03">
  <CstmrPmtStsRpt><GrpHdr><MsgId>ST1</MsgId><CreDtTm>2026-09-19T12:00:00</CreDtTm></GrpHdr>
    <OrgnlGrpInfAndSts><OrgnlMsgId>{$created['msg_id']}</OrgnlMsgId><GrpSts>ACCP</GrpSts></OrgnlGrpInfAndSts>
    <OrgnlPmtInfAndSts><TxInfAndSts><OrgnlEndToEndId>E2E-FA240891</OrgnlEndToEndId><TxSts>ACCP</TxSts></TxInfAndSts></OrgnlPmtInfAndSts>
  </CstmrPmtStsRpt>
</Document>
XML;
        $result = $this->svc->refreshStatus($created['batch_id'], null, $pain002);
        $this->assertSame('ACCP', $result['group_status']);
        $this->assertSame(1, $result['updated_lines']);
        $res = $this->db->query('SELECT status, pain002_status FROM llx_bankconnect_batch_line WHERE fk_batch = '.$created['batch_id']);
        $line = $this->db->fetch_object($res);
        $this->assertSame('accepted', $line->status);
        $this->assertSame('ACCP', $line->pain002_status);
    }
}
