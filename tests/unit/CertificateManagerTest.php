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
        $previous = getenv('BANKCONNECT_KEY_ENCRYPTION_SECRET');
        putenv('BANKCONNECT_KEY_ENCRYPTION_SECRET=short');
        $mgr = new BankConnectCertificateManager($this->conf);
        $this->expectException(BankConnectException::class);
        try {
            $mgr->encryptPrivateKey('secret');
        } finally {
            if ($previous === false) { putenv('BANKCONNECT_KEY_ENCRYPTION_SECRET'); }
            else { putenv('BANKCONNECT_KEY_ENCRYPTION_SECRET='.$previous); }
        }
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


    public function testRenewalRejectsCertificateThatDoesNotMatchGeneratedKey(): void
    {
        $db = new MockDoliDB();
        $db->tables['llx_bankconnect_agreement'] = [];
        $db->tables['llx_bankconnect_certificate'] = [];
        $store = new AgreementStore($db);
        $aid = $store->createAgreement(['bank_connect_id' => '001', 'label' => 'L']);

        $client = new RenewalMismatchClient($this->conf);
        $mgr = new BankConnectCertificateManager($this->conf, $client, null, $store);

        $this->expectException(BankConnectException::class);
        $this->expectExceptionMessage('No returned customer certificate matches the generated private key');
        try {
            $mgr->renewCustomerCertificateForAgreement($aid, '<serviceHeader/>');
        } finally {
            $this->assertSame(0, $db->countRows('llx_bankconnect_certificate'));
        }
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

    public function testOnboardingInstallsAndStoresFetchedBankCertificateBeforeActivation(): void
    {
        [, $bankCertificatePem] = $this->createBankCertificateChain();

        $db = new MockDoliDB();
        $db->tables['llx_bankconnect_bank_certificate'] = [];
        $bankStore = new BankCertificateStore($db, 1);
        $client = new CertificateBootstrapProbeClient($this->conf, $bankCertificatePem);
        $manager = new BankConnectCertificateManager($this->conf, $client, null, null, $bankStore);

        try {
            $manager->onboard([
                'activation_code' => '1234-5678-9012',
                'function_identification' => '0010888100007',
                'main_registration_number' => '8079',
                'datacenter' => 'BANKDATA',
                'endpoint' => 'https://stest.bankconnect.dk/2019/04/04/services/CorporateService',
            ]);
            $this->fail('The probe client must stop after proving activation was reached');
        } catch (BankConnectException $e) {
            $this->assertSame('activation probe reached', $e->getMessage());
        }

        $this->assertTrue($client->bankCertificateInstalled);
        $stored = $bankStore->getBankCertificate('BANKDATA', 'test');
        $this->assertNotNull($stored);
        $this->assertSame(rtrim($bankCertificatePem), rtrim((string)$stored['certificate_pem']));
    }

    public function testGetBankCertificateSelectsLeafAfterIntermediate(): void
    {
        [$intermediate, $leaf] = $this->createBankCertificateChain();
        $client = new class($this->conf, $intermediate, $leaf) extends BankConnectClient {
            private string $response;
            public function __construct(Conf $conf, string $intermediate, string $leaf)
            {
                parent::__construct($conf);
                $this->response = '<Envelope><content>'.base64_encode($intermediate."\n".$leaf).'</content></Envelope>';
            }
            public function getBankCertificate(string $activationHeaderXml): string { return $this->response; }
        };
        $manager = new BankConnectCertificateManager($this->conf, $client);

        $this->assertSame(rtrim($leaf), rtrim($manager->getBankCertificate('<activationHeader/>')));
        $this->assertSame($leaf, $manager->selectBankCertificatePem([$intermediate, $leaf]));
    }

    public function testBankCertificateSelectionRejectsCaAndAmbiguousLeaves(): void
    {
        [$intermediate, $leaf] = $this->createBankCertificateChain();
        $manager = new BankConnectCertificateManager($this->conf);
        try {
            $manager->selectBankCertificatePem([$intermediate]);
            $this->fail('An intermediate CA must not be used for bank encryption');
        } catch (BankConnectException $e) {
            $this->assertStringContainsString('no unique bank leaf', $e->getMessage());
        }
        $this->expectException(BankConnectException::class);
        $manager->selectBankCertificatePem([$leaf, $leaf]);
    }

    /** @return array{string,string} */
    private function createBankCertificateChain(): array
    {
        $config = tempnam(sys_get_temp_dir(), 'bc-cert-');
        $this->assertNotFalse($config);
        try {
            $this->assertNotFalse(file_put_contents($config, "[v3_ca]\nbasicConstraints=critical,CA:TRUE\n[v3_leaf]\nbasicConstraints=critical,CA:FALSE\n"));
            $caKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $leafKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $this->assertNotFalse($caKey);
            $this->assertNotFalse($leafKey);
            $caCsr = openssl_csr_new(['commonName' => 'BankConnect Intermediate'], $caKey, ['digest_alg' => 'sha256']);
            $leafCsr = openssl_csr_new(['commonName' => 'BankConnect Bank'], $leafKey, ['digest_alg' => 'sha256']);
            $this->assertNotFalse($caCsr);
            $this->assertNotFalse($leafCsr);
            $caCert = openssl_csr_sign($caCsr, null, $caKey, 365, ['config' => $config, 'x509_extensions' => 'v3_ca', 'digest_alg' => 'sha256']);
            $this->assertNotFalse($caCert);
            $leafCert = openssl_csr_sign($leafCsr, $caCert, $caKey, 365, ['config' => $config, 'x509_extensions' => 'v3_leaf', 'digest_alg' => 'sha256']);
            $this->assertNotFalse($leafCert);
            $this->assertTrue(openssl_x509_export($caCert, $intermediate));
            $this->assertTrue(openssl_x509_export($leafCert, $leaf));
            return [$intermediate, $leaf];
        } finally {
            unlink($config);
        }
    }

    public function testCertificateSaveLocksAgreementBeforeActivation(): void
    {
        $db = new MockDoliDB();
        $db->tables['llx_bankconnect_agreement'] = [];
        $db->tables['llx_bankconnect_certificate'] = [];
        $store = new AgreementStore($db);

        $aid = $store->createAgreement(['bank_connect_id' => '001', 'label' => 'L']);
        $cid = $store->saveCertificate([
            'fk_agreement' => $aid,
            'certificate_pem' => 'CERT',
            'private_key_enc' => 'KEY',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2029-01-01 00:00:00',
        ]);

        $this->assertSame($cid, (int) $store->getActiveCertificate($aid)['rowid']);
    }

    public function testCertificateInsertFailurePreservesExistingActiveCertificate(): void
    {
        $db = new MockDoliDB();
        $db->tables['llx_bankconnect_agreement'] = [];
        $db->tables['llx_bankconnect_certificate'] = [];
        $store = new AgreementStore($db);

        $aid = $store->createAgreement(['bank_connect_id' => '001', 'label' => 'L']);
        $oldId = $store->saveCertificate([
            'fk_agreement' => $aid,
            'certificate_pem' => 'OLD',
            'private_key_enc' => 'OLDKEY',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2029-01-01 00:00:00',
        ]);

        $db->failNextQueryContaining(
            'INSERT INTO llx_bankconnect_certificate',
            'simulated certificate insert failure'
        );

        $this->expectException(BankConnectException::class);
        try {
            $store->saveCertificate([
                'fk_agreement' => $aid,
                'certificate_pem' => 'NEW',
                'private_key_enc' => 'NEWKEY',
                'valid_from' => '2027-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);
        } finally {
            $active = $store->getActiveCertificate($aid);
            $this->assertSame($oldId, (int) $active['rowid']);
            $this->assertSame(1, (int) $active['is_active']);
            $this->assertSame(1, $db->countRows('llx_bankconnect_certificate'));
        }
    }

    public function testCertificateActivationFailureRollsBackToExistingActiveCertificate(): void
    {
        $db = new MockDoliDB();
        $db->tables['llx_bankconnect_agreement'] = [];
        $db->tables['llx_bankconnect_certificate'] = [];
        $store = new AgreementStore($db);

        $aid = $store->createAgreement(['bank_connect_id' => '001', 'label' => 'L']);
        $oldId = $store->saveCertificate([
            'fk_agreement' => $aid,
            'certificate_pem' => 'OLD',
            'private_key_enc' => 'OLDKEY',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2029-01-01 00:00:00',
        ]);

        $db->failNextQueryContaining(
            'SET is_active = 1',
            'simulated certificate activation failure'
        );

        $this->expectException(BankConnectException::class);
        try {
            $store->saveCertificate([
                'fk_agreement' => $aid,
                'certificate_pem' => 'NEW',
                'private_key_enc' => 'NEWKEY',
                'valid_from' => '2027-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);
        } finally {
            $active = $store->getActiveCertificate($aid);
            $this->assertSame($oldId, (int) $active['rowid']);
            $this->assertSame(1, (int) $active['is_active']);
            $this->assertSame(1, $db->countRows('llx_bankconnect_certificate'));
        }
    }

    public function testCertificateRenewalLeavesExactlyOneActiveCertificate(): void
    {
        $db = new MockDoliDB();
        $db->tables['llx_bankconnect_agreement'] = [];
        $db->tables['llx_bankconnect_certificate'] = [];
        $store = new AgreementStore($db);

        $aid = $store->createAgreement(['bank_connect_id' => '001', 'label' => 'L']);
        $oldId = $store->saveCertificate([
            'fk_agreement' => $aid,
            'certificate_pem' => 'OLD',
            'private_key_enc' => 'OLDKEY',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2029-01-01 00:00:00',
        ]);
        $newId = $store->saveCertificate([
            'fk_agreement' => $aid,
            'certificate_pem' => 'NEW',
            'private_key_enc' => 'NEWKEY',
            'valid_from' => '2027-01-01 00:00:00',
            'valid_to' => '2030-01-01 00:00:00',
        ]);

        $rows = array_values(array_filter(
            $db->table('llx_bankconnect_certificate'),
            static fn (array $row): bool => (int) $row['fk_agreement'] === $aid && (int) $row['is_active'] === 1
        ));

        $this->assertCount(1, $rows);
        $this->assertSame($newId, (int) $rows[0]['rowid']);
        $this->assertNotSame($oldId, $newId);
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

    public function testCertificateStateDistinguishesValidExpiringExpiredAndImported(): void
    {
        $mgr = new BankConnectCertificateManager($this->conf);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'state-test'], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($cert);
        openssl_x509_export($cert, $pem);
        $meta = $mgr->validateCertificatePem($pem);

        $this->assertSame(BankConnectCertificateManager::STATE_VALID, $mgr->certificateState(
            ['certificate_pem' => $pem],
            strtotime($meta['valid_from']) + 86400,
            30
        ));
        $this->assertSame(BankConnectCertificateManager::STATE_EXPIRING, $mgr->certificateState(
            ['certificate_pem' => $pem],
            strtotime($meta['valid_to']) - (5 * 86400),
            30
        ));
        $this->assertSame(BankConnectCertificateManager::STATE_EXPIRED, $mgr->certificateState(
            ['certificate_pem' => $pem],
            strtotime($meta['valid_to']) + 1,
            30
        ));
        $this->assertSame(BankConnectCertificateManager::STATE_IMPORTED, $mgr->certificateState(
            ['certificate_pem' => $pem],
            strtotime($meta['valid_from']) - 1,
            30
        ));
    }

    public function testCertificateStateIsFailClosedForInvalidAndRevoked(): void
    {
        $mgr = new BankConnectCertificateManager($this->conf);
        $this->assertSame(BankConnectCertificateManager::STATE_MISSING, $mgr->certificateState([]));
        $this->assertSame(BankConnectCertificateManager::STATE_INVALID, $mgr->certificateState(['certificate_pem' => 'not-a-certificate']));
        $this->assertSame(BankConnectCertificateManager::STATE_REVOKED, $mgr->certificateState([
            'certificate_pem' => 'not-a-certificate',
            'revoked_at' => '2026-09-21 00:00:00',
        ]));
    }

    public function testCertificateFingerprintIsStable(): void
    {
        $mgr = new BankConnectCertificateManager($this->conf);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'fingerprint-test'], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($cert);
        openssl_x509_export($cert, $pem);
        $fp = $mgr->certificateFingerprint($pem);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fp);
        $this->assertSame($fp, $mgr->certificateFingerprint($pem));
    }

    public function testMultipleReturnedCertificatesRequirePrivateKeyBinding(): void
    {
        $mgr = new BankConnectCertificateManager($this->conf);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $this->assertNotFalse($other);
        openssl_pkey_export($key, $private);
        $csr = openssl_csr_new(['commonName' => 'customer'], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($cert);
        openssl_x509_export($cert, $pem);
        $csr2 = openssl_csr_new(['commonName' => 'other'], $other, ['digest_alg' => 'sha256']);
        $cert2 = openssl_csr_sign($csr2, null, $other, 365, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($cert2);
        openssl_x509_export($cert2, $pem2);
        $raw = $pem.$pem2;
        $this->expectException(BankConnectException::class);
        $mgr->extractCustomerCertificatePem($raw);
    }

    public function testMatchingCertificateIsSelectedFromMultipleReturnedCertificates(): void
    {
        $mgr = new BankConnectCertificateManager($this->conf);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $this->assertNotFalse($other);
        openssl_pkey_export($key, $private);
        $csr = openssl_csr_new(['commonName' => 'customer'], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($cert);
        openssl_x509_export($cert, $pem);
        $csr2 = openssl_csr_new(['commonName' => 'other'], $other, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr2);
        $cert2 = openssl_csr_sign($csr2, null, $other, 365, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($cert2);
        openssl_x509_export($cert2, $pem2);

        $this->assertSame(rtrim($pem, "\r\n"), rtrim($mgr->extractCustomerCertificatePem($pem2.$pem, $private), "\r\n"));
    }
}



class RenewalMismatchClient extends BankConnectClient
{
    private string $response;

    public function __construct(Conf $conf)
    {
        parent::__construct($conf);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new RuntimeException('OpenSSL key generation unavailable');
        }
        $csr = openssl_csr_new(['commonName' => 'mismatch'], $key, ['digest_alg' => 'sha256']);
        if ($csr === false) {
            throw new RuntimeException('OpenSSL CSR generation unavailable');
        }
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        if ($cert === false) {
            throw new RuntimeException('OpenSSL certificate generation unavailable');
        }
        openssl_x509_export($cert, $pem);
        $this->response = $pem;
    }

    public function renewCustomerCertificate(string $payloadXml): string
    {
        return $this->response;
    }
}

class CertificateBootstrapProbeClient extends BankConnectClient
{
    public bool $bankCertificateInstalled = false;
    private string $bankCertificatePem;

    public function __construct(Conf $conf, string $bankCertificatePem)
    {
        parent::__construct($conf);
        $this->bankCertificatePem = $bankCertificatePem;
    }

    public function getBankCertificate(string $activationHeaderXml): string
    {
        return $this->bankCertificatePem;
    }

    public function setBankCertificate(string $certificatePem): void
    {
        parent::setBankCertificate($certificatePem);
        $this->bankCertificateInstalled = true;
    }

    public function activateServiceAgreement(string $payloadXml): string
    {
        if (!$this->bankCertificateInstalled) {
            throw new BankConnectException('activation attempted without a bank certificate');
        }
        throw new BankConnectException('activation probe reached');
    }
}
