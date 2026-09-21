<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../unit/MockDoliDB.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectException.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/Pain001Builder.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/PaymentBatchService.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectClient.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectStore.php';

final class FailureInjectionQualificationTest extends TestCase
{
    private MockDoliDB $db;
    private Conf $conf;

    protected function setUp(): void
    {
        $this->db = new MockDoliDB();
        $this->conf = new Conf();
        $this->db->tables['llx_bankconnect_batch'] = [];
        $this->db->tables['llx_bankconnect_batch_line'] = [];
        $this->db->tables['llx_bankconnect_agreement'] = [[
            'rowid' => 1,
            'bank_connect_id' => 'AGREEMENT-1',
            'main_registration_number' => '12345678',
        ]];
    }

    private function builder(): Pain001Builder
    {
        return (new Pain001Builder())
            ->setInitiatingParty('Qualification Test ApS')
            ->setDebtor('Qualification Test ApS', 'DK5089000000012345', 'SXPYDKKK')
            ->setExecutionDate(new DateTimeImmutable('2026-09-20'))
            ->addTransaction([
                'endToEndId' => 'E2E-QUAL-001',
                'amount' => 100.00,
                'currency' => 'DKK',
                'creditorName' => 'Qualification Supplier',
                'creditorIban' => 'DK0689000000054321',
                'fk_facture_fourn' => 42,
            ]);
    }

    private function credentials(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);

        $private = '';
        $this->assertTrue(openssl_pkey_export($key, $private));

        $csr = openssl_csr_new(
            ['commonName' => 'bankconnect-qualification'],
            $key,
            ['digest_alg' => 'sha256']
        );
        $this->assertNotFalse($csr);

        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($cert);

        $certificate = '';
        $this->assertTrue(openssl_x509_export($cert, $certificate));

        $this->conf->global['BANKCONNECT_CUSTOMER_PRIVATE_KEY'] = $private;
        $this->conf->global['BANKCONNECT_CUSTOMER_CERTIFICATE'] = $certificate;
    }

    public function testTransportFailureProducesUnknownAndNeverRetries(): void
    {
        $this->credentials();

        $client = new class($this->conf) extends BankConnectClient {
            public int $calls = 0;

            public function transferPayments(string $paymentMessageXml, string $endToEndMessageId): string
            {
                $this->calls++;
                throw new RuntimeException('injected transport timeout');
            }
        };

        $service = new PaymentBatchService($this->db, $this->conf, $client);
        $created = $service->createBatch($this->builder(), 1);

        try {
            $service->sendBatch($created['batch_id']);
            $this->fail('Transport failure must not be reported as successful');
        } catch (BankConnectException $e) {
            $this->assertStringContainsString('outcome is unknown', $e->getMessage());
        }

        $row = $this->db->findFirst('llx_bankconnect_batch', 'rowid', $created['batch_id']);
        $this->assertSame('unknown', $row['status']);

        try {
            $service->sendBatch($created['batch_id']);
            $this->fail('UNKNOWN must never be blindly retried');
        } catch (BankConnectException $e) {
            $this->assertStringContainsString('cannot be submitted from status unknown', $e->getMessage());
        }

        $this->assertSame(1, $client->calls);
    }

    public function testInjectedSuccessfulTransportIsCalledExactlyOnceForSubmittedBatch(): void
    {
        $this->credentials();

        $client = new class($this->conf) extends BankConnectClient {
            public int $calls = 0;

            public function transferPayments(string $paymentMessageXml, string $endToEndMessageId): string
            {
                $this->calls++;
                return '<response><responseCode>OK</responseCode></response>';
            }
        };

        $service = new PaymentBatchService($this->db, $this->conf, $client);
        $created = $service->createBatch($this->builder(), 1);

        $result = $service->sendBatch($created['batch_id']);
        $this->assertSame('submitted', $result['status']);
        $this->assertSame(1, $client->calls);

        try {
            $service->sendBatch($created['batch_id']);
            $this->fail('A submitted batch must not be sent twice');
        } catch (BankConnectException $e) {
            $this->assertStringContainsString('cannot be submitted from status submitted', $e->getMessage());
        }

        $this->assertSame(1, $client->calls);
    }

    public function testDuplicateImportFailureInjectionRemainsIdempotent(): void
    {
        $tx = [
            'date' => '2026-09-21',
            'amount' => -100.00,
            'currency' => 'DKK',
            'reference' => 'QUAL-001',
            'counterparty' => 'Qualification Supplier',
            'text' => 'Qualification import',
            'acctSvcrRef' => 'BANK-QUAL-001',
        ];

        $store = new BankConnectStore($this->db);
        $first = $store->upsertTransactionDetailed($tx, 1, 'first.xml');
        $second = $store->upsertTransactionDetailed($tx, 1, 'second.xml');

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['rowid'], $second['rowid']);
        $this->assertSame(1, $this->db->countRows('llx_bankconnect_transaction'));
    }
}
