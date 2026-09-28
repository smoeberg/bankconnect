<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/MockDoliDB.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/PaymentPageService.php';

final class PaymentPageServiceTest extends TestCase
{
    private MockDoliDB $db;
    private PaymentPageService $payments;

    protected function setUp(): void
    {
        $this->db = new class extends MockDoliDB {
            public function query($sql)
            {
                if (str_contains($sql, 'FROM llx_facture_fourn f JOIN llx_societe s')) {
                    if (!str_contains($sql, 'f.rowid=42') || !str_contains($sql, 'f.entity=1')) return [];
                    return [[
                        'rowid' => 42, 'ref' => 'FF-42', 'total_ttc' => 100.0,
                        'multicurrency_total_ttc' => 100.0, 'multicurrency_code' => 'DKK',
                        'paid_main' => 25.0, 'paid_multi' => 25.0,
                        'nom' => 'Supplier', 'iban' => 'DK0689000000054321', 'bic' => 'SXPYDKKK',
                    ]];
                }
                if (str_contains($sql, 'FROM llx_bankconnect_batch_line bl JOIN llx_bankconnect_batch b')) return [];
                return parent::query($sql);
            }
        };
        $this->db->tables['llx_bankconnect_agreement'] = [[
            'rowid' => 7, 'entity' => 1, 'status' => 'active', 'label' => 'Test agreement',
            'bank_connect_id' => 'AGREEMENT-7', 'main_registration_number' => '12345678',
        ]];
        $this->db->tables['llx_bankconnect_account_mapping'] = [[
            'rowid' => 8, 'entity' => 1, 'fk_agreement' => 7, 'fk_bank_account' => 9,
        ]];
        $this->db->tables['llx_bank_account'] = [[
            'rowid' => 9, 'entity' => 1, 'clos' => 0, 'label' => 'Operating',
            'iban_prefix' => 'DK50', 'number' => '89000000012345', 'bic' => 'SXPYDKKK',
        ]];
        $conf = new Conf();
        $conf->entity = 1;
        $conf->currency = 'DKK';
        $this->payments = new PaymentPageService($this->db, $conf);
    }

    public function testCreatesBatchWithMappedDebtorAndRemainingInvoiceBalance(): void
    {
        $result = $this->payments->create(1, 7, [42], 'Test ApS', (new DateTimeImmutable('+1 day'))->format('Y-m-d'), 'dk_transfer', false);
        $batch = $this->db->findFirst('llx_bankconnect_batch', 'rowid', $result['batch_id']);

        $this->assertSame(75.0, $result['control_sum']);
        $this->assertSame(7, $batch['fk_agreement']);
        $this->assertStringContainsString('<IBAN>DK5089000000012345</IBAN>', $batch['pain001_xml']);
        $this->assertStringContainsString('75.00</InstdAmt>', $batch['pain001_xml']);
    }

    public function testCannotCreateBatchFromAnotherEntityInvoice(): void
    {
        try {
            $this->payments->create(1, 7, [43], 'Test ApS', null, 'dk_transfer', false);
            $this->fail('Invoice from another entity must be rejected');
        } catch (BankConnectException $e) {
            $this->assertStringContainsString('unavailable', $e->getMessage());
        }
        $this->assertSame(0, $this->db->countRows('llx_bankconnect_batch'));
    }

    public function testSendNowChecksCertificateBeforeCreatingBatch(): void
    {
        $this->expectException(BankConnectException::class);
        try {
            $this->payments->create(1, 7, [42], 'Test ApS', null, 'dk_transfer', true);
        } finally {
            $this->assertSame(0, $this->db->countRows('llx_bankconnect_batch'));
        }
    }

    public function testCannotActOnAnotherEntityPaymentBatch(): void
    {
        $created = $this->payments->create(1, 7, [42], 'Test ApS', null, 'dk_transfer', false);
        $this->expectException(BankConnectException::class);
        $this->expectExceptionMessage('not found in this entity');
        $this->payments->send(2, $created['batch_id']);
    }
}
