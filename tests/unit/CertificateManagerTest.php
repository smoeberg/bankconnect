<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/MockDoliDB.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectException.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectLogger.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/AgreementStore.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/ServiceHeaderBuilder.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectCertificateManager.php';

class CertificateManagerTest extends TestCase
{
    private Conf $conf;

    protected function setUp(): void
    {
        $this->conf = new Conf();
        $this->conf->global['BANKCONNECT_KEY_ENCRYPTION_SECRET'] = 'test-secret-not-for-production-use-32b';
    }

    public function testEncryptDecryptPrivateKey(): void
    {
        $mgr = new BankConnectCertificateManager($this->conf, null, null, null);
        $pem = "-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----\n";
        $enc = $mgr->encryptPrivateKey($pem);
        $this->assertNotSame($pem, $enc);
        $this->assertSame($pem, $mgr->decryptPrivateKey($enc));
    }

    public function testRejectsShortEncryptionSecret(): void
    {
        $this->conf->global['BANKCONNECT_KEY_ENCRYPTION_SECRET'] = 'short';
        $mgr = new BankConnectCertificateManager($this->conf);
        $this->expectException(BankConnectException::class);
        $mgr->encryptPrivateKey('secret');
    }

    public function testCertificateKeyBindingRejectsMismatchedKey(): void
    {
        $mgr = new BankConnectCertificateManager($this->conf);

        $keyA = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $keyB = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($keyA === false || $keyB === false) {
            $this->markTestSkipped('OpenSSL key generation unavailable');
        }

        openssl_pkey_export($keyA, $privateA);
        $csr = openssl_csr_new(['commonName' => 'bankconnect-test'], $keyA, ['digest_alg' => 'sha256']);
        if ($csr === false) {
            $this->markTestSkipped('OpenSSL CSR generation unavailable');
        }
        $cert = openssl_csr_sign($csr, null, $keyA, 365, ['digest_alg' => 'sha256']);
        if ($cert === false) {
            $this->markTestSkipped('OpenSSL certificate generation unavailable');
        }
        openssl_x509_export($cert, $certificatePem);

        $this->assertTrue($mgr->validateCertificateAndPrivateKey($certificatePem, $privateA));

        openssl_pkey_export($keyB, $privateB);
        $this->assertFalse($mgr->validateCertificateAndPrivateKey($certificatePem, $privateB));
    }

    public function testCertificateValidityWindowIsEnforced(): void
    {
        $mgr = new BankConnectCertificateManager($this->conf);
        $now = time();

        $this->assertTrue($mgr->isCertificateCurrentlyValid([
            'from' => date('Y-m-d H:i:s', $now - 60),
            'to' => date('Y-m-d H:i:s', $now + 60),
        ], $now));

        $this->assertFalse($mgr->isCertificateCurrentlyValid([
            'from' => date('Y-m-d H:i:s', $now - 120),
            'to' => date('Y-m-d H:i:s', $now - 60),
        ], $now));

        $this->assertFalse($mgr->isCertificateCurrentlyValid([
            'from' => date('Y-m-d H:i:s', $now + 60),
            'to' => date('Y-m-d H:i:s', $now + 120),
        ], $now));

        $this->assertFalse($mgr->isCertificateCurrentlyValid([
            'from' => null,
            'to' => date('Y-m-d H:i:s', $now + 60),
        ], $now));
    }

    public function testCsrToRequestBodyStripsHeaders(): void
    {
        $mgr = new BankConnectCertificateManager($this->conf);
        $pem = "-----BEGIN CERTIFICATE REQUEST-----\nABC\nDEF\n-----END CERTIFICATE REQUEST-----\n";
        $this->assertSame('ABCDEF', $mgr->csrToRequestBody($pem));
    }

    public function testOnboardDryRunPersistsAgreement(): void
    {
        $db = new MockDoliDB();
        $db->tables['llx_bankconnect_agreement'] = [];
        $db->tables['llx_bankconnect_certificate'] = [];
        $store = new AgreementStore($db);
        $mgr = new BankConnectCertificateManager($this->conf, null, null, $store);

        // May skip if openssl cannot generate keys on this host
        try {
            $result = $mgr->onboard([
                'activation_code'         => '1234-5678-9012',
                'function_identification' => '0010888100007',
                'main_registration_number'=> '8079',
                'label'                   => 'Test',
                'dry_run'                 => true,
            ]);
        } catch (BankConnectException $e) {
            if (str_contains($e->getMessage(), 'private key') || str_contains($e->getMessage(), 'CSR')) {
                $this->markTestSkipped($e->getMessage());
            }
            throw $e;
        }

        $this->assertGreaterThan(0, $result['agreement_id']);
        $this->assertSame('draft', $result['status']);
        $this->assertNull($result['certificate_id']);
        $this->assertNotEmpty($result['csr_b64']);
        $this->assertSame(base64_encode('123456789012'), $result['activation_code_b64']);

        $agr = $store->getAgreement($result['agreement_id']);
        $this->assertSame('0010888100007', $agr['bank_connect_id']);
    }

    public function testAgreementStoreSaveCertificate(): void
    {
        $db = new MockDoliDB();
        $db->tables['llx_bankconnect_agreement'] = [];
        $db->tables['llx_bankconnect_certificate'] = [];
        $store = new AgreementStore($db);

        $aid = $store->createAgreement([
            'bank_connect_id' => '001',
            'label' => 'L',
        ]);
        $cid = $store->saveCertificate([
            'fk_agreement'    => $aid,
            'certificate_pem' => "-----BEGIN CERTIFICATE-----\nX\n-----END CERTIFICATE-----",
            'private_key_enc' => 'encdata',
            'valid_from'      => '2026-01-01 00:00:00',
            'valid_to'        => '2029-01-01 00:00:00',
        ]);

        $this->assertGreaterThan(0, $cid);
        $active = $store->getActiveCertificate($aid);
        $this->assertSame($cid, (int) $active['rowid']);
        $this->assertSame(1, (int) $active['is_active']);
    }
}
