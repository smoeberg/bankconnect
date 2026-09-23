<?php
/**
 * Unit tests for BankCertificateStore.
 */

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankCertificateStore.php';

class BankCertificateStoreTest extends PHPUnit\Framework\TestCase
{
    private $db;
    private BankCertificateStore $store;
    private string $testCertPem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new MockDoliDB();
        $this->store = new BankCertificateStore($this->db);

        // Generate a test certificate
        $this->testCertPem = $this->generateTestCertificate();

        // Create the test table
        $this->createTestTable();
    }

    private function createTestTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS llx_bankconnect_bank_certificate (
		rowid bigint AUTO_INCREMENT PRIMARY KEY,
		entity integer NOT NULL DEFAULT 1,
		datacenter varchar(16) NOT NULL,
		environment varchar(16) NOT NULL DEFAULT 'test',
		certificate_pem text NOT NULL,
		fingerprint_sha256 varchar(64) NOT NULL,
		valid_from datetime DEFAULT NULL,
		valid_to datetime DEFAULT NULL,
		date_creation datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		date_updated datetime DEFAULT NULL,
		KEY idx_bc_bankcert_datacenter (datacenter),
		KEY idx_bc_bankcert_env (environment),
		UNIQUE KEY uk_bc_bankcert_datacenter_env (entity, datacenter, environment)
	) ENGINE=innodb;";
        $this->db->query($sql);
    }

    /**
     * Generate a self-signed test certificate for testing purposes.
     */
    private function generateTestCertificate(): string
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $privKey = openssl_pkey_new($config);
        $dn = [
            'countryName' => 'DK',
            'organizationName' => 'Test Bank',
            'commonName' => 'test.bankconnect.dk',
        ];

        $csr = openssl_csr_new($dn, $privKey, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $privKey, 365, ['digest_alg' => 'sha256']);

        openssl_x509_export($cert, $pem);
        return $pem;
    }

    public function testSaveAndGetBankCertificate()
    {
        $meta = $this->store->validateCertificatePem($this->testCertPem);

        $id = $this->store->saveBankCertificate([
            'datacenter' => 'BANKDATA',
            'environment' => 'test',
            'certificate_pem' => $this->testCertPem,
            'fingerprint_sha256' => $meta['fingerprint_sha256'],
            'valid_from' => $meta['valid_from'],
            'valid_to' => $meta['valid_to'],
        ]);

        $this->assertGreaterThan(0, $id);

        $retrieved = $this->store->getBankCertificate('BANKDATA', 'test');
        $this->assertNotNull($retrieved);
        $this->assertEquals($this->testCertPem, $retrieved['certificate_pem']);
    }

    public function testGetActiveBankCertificate()
    {
        $meta = $this->store->validateCertificatePem($this->testCertPem);

        // Save test certificate
        $this->store->saveBankCertificate([
            'datacenter' => 'BANKDATA',
            'environment' => 'test',
            'certificate_pem' => $this->testCertPem,
            'fingerprint_sha256' => $meta['fingerprint_sha256'],
            'valid_from' => $meta['valid_from'],
            'valid_to' => $meta['valid_to'],
        ]);

        $cert = $this->store->getActiveBankCertificate('BANKDATA');
        $this->assertNotNull($cert);
        $this->assertEquals('BANKDATA', $cert['datacenter']);
    }

    public function testListBankCertificates()
    {
        $meta = $this->store->validateCertificatePem($this->testCertPem);

        // Save multiple certificates
        $this->store->saveBankCertificate([
            'datacenter' => 'BANKDATA',
            'environment' => 'test',
            'certificate_pem' => $this->testCertPem,
            'fingerprint_sha256' => $meta['fingerprint_sha256'],
            'valid_from' => $meta['valid_from'],
            'valid_to' => $meta['valid_to'],
        ]);

        $this->store->saveBankCertificate([
            'datacenter' => 'NBS',
            'environment' => 'test',
            'certificate_pem' => $this->testCertPem,
            'fingerprint_sha256' => $meta['fingerprint_sha256'],
            'valid_from' => $meta['valid_from'],
            'valid_to' => $meta['valid_to'],
        ]);

        $list = $this->store->listBankCertificates();
        $this->assertCount(2, $list);
    }

    public function testUpdateBankCertificate()
    {
        $meta = $this->store->validateCertificatePem($this->testCertPem);

        // Save initial certificate
        $id = $this->store->saveBankCertificate([
            'datacenter' => 'BANKDATA',
            'environment' => 'test',
            'certificate_pem' => $this->testCertPem,
            'fingerprint_sha256' => $meta['fingerprint_sha256'],
            'valid_from' => $meta['valid_from'],
            'valid_to' => $meta['valid_to'],
        ]);

        // Update with new certificate (same datacenter/environment)
        $newCertPem = $this->generateTestCertificate();
        $newMeta = $this->store->validateCertificatePem($newCertPem);

        $newId = $this->store->saveBankCertificate([
            'datacenter' => 'BANKDATA',
            'environment' => 'test',
            'certificate_pem' => $newCertPem,
            'fingerprint_sha256' => $newMeta['fingerprint_sha256'],
            'valid_from' => $newMeta['valid_from'],
            'valid_to' => $newMeta['valid_to'],
        ]);

        $this->assertEquals($id, $newId); // Should return same ID after update

        $retrieved = $this->store->getBankCertificate('BANKDATA', 'test');
        $this->assertEquals($newCertPem, $retrieved['certificate_pem']);
    }

    public function testValidateCertificatePem()
    {
        $meta = $this->store->validateCertificatePem($this->testCertPem);

        $this->assertArrayHasKey('fingerprint_sha256', $meta);
        $this->assertArrayHasKey('valid_from', $meta);
        $this->assertArrayHasKey('valid_to', $meta);
        $this->assertNotEmpty($meta['fingerprint_sha256']);
        $this->assertNotNull($meta['valid_from']);
        $this->assertNotNull($meta['valid_to']);
    }

    public function testValidateCertificatePemThrowsOnInvalid()
    {
        $this->expectException(BankConnectException::class);
        $this->store->validateCertificatePem('invalid certificate');
    }

    public function testIsCertificateValid()
    {
        $isValid = $this->store->isCertificateValid($this->testCertPem);
        $this->assertTrue($isValid);
    }

    public function testDeleteBankCertificate()
    {
        $meta = $this->store->validateCertificatePem($this->testCertPem);

        $id = $this->store->saveBankCertificate([
            'datacenter' => 'BANKDATA',
            'environment' => 'test',
            'certificate_pem' => $this->testCertPem,
            'fingerprint_sha256' => $meta['fingerprint_sha256'],
            'valid_from' => $meta['valid_from'],
            'valid_to' => $meta['valid_to'],
        ]);

        $this->store->deleteBankCertificate($id);

        $retrieved = $this->store->getBankCertificate('BANKDATA', 'test');
        $this->assertNull($retrieved);
    }
}
