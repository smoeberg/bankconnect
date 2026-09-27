<?php
/**
 * Unit tests for BankCertificateService.
 */

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankCertificateService.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectCertificateManager.php';
require_once __DIR__.'/MockDoliDB.php';
require_once __DIR__.'/BankCertificateFixture.php';

class BankCertificateServiceTest extends PHPUnit\Framework\TestCase
{
    private Conf $conf;
    private BankCertificateService $service;
    private string $testCertPem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conf = new Conf();
        $this->conf->global = [
            'BANKCONNECT_ENDPOINT' => 'https://stest.bankconnect.dk/2019/04/04/services/CorporateService',
            'BANKCONNECT_ENVIRONMENT' => 'test',
            'BANKCONNECT_DATACENTER' => 'BANKDATA',
        ];

        $this->service = new BankCertificateService($this->conf);

        // Generate a test certificate for mocking
        $this->testCertPem = $this->generateTestCertificate();
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

    public function testServiceInitialization()
    {
        $this->assertInstanceOf(BankCertificateService::class, $this->service);
    }

    public function testValidateBankCertificate()
    {
        $result = $this->service->validateBankCertificate($this->testCertPem);

        $this->assertArrayHasKey('fingerprint_sha256', $result);
        $this->assertArrayHasKey('valid_from', $result);
        $this->assertArrayHasKey('valid_to', $result);
        $this->assertNotEmpty($result['fingerprint_sha256']);
    }

    public function testGetEncryptionAlgorithmForBec()
    {
        $algo = BankCertificateService::getEncryptionAlgorithm('BEC');
        $this->assertEquals('http://www.w3.org/2001/04/xmlenc#rsa-1_5', $algo);
    }

    public function testGetEncryptionAlgorithmForBankdata()
    {
        $algo = BankCertificateService::getEncryptionAlgorithm('BANKDATA');
        $this->assertEquals('http://www.w3.org/2001/04/xmlenc#rsa-oaep-mgf1p', $algo);
    }

    public function testGetEncryptionAlgorithmForNbs()
    {
        $algo = BankCertificateService::getEncryptionAlgorithm('NBS');
        $this->assertEquals('http://www.w3.org/2001/04/xmlenc#rsa-oaep-mgf1p', $algo);
    }

    public function testGetSecurityOrderForBec()
    {
        $order = BankCertificateService::getSecurityOrder('BEC');
        $this->assertEquals('encrypt_first', $order);
    }

    public function testGetSecurityOrderForBankdata()
    {
        $order = BankCertificateService::getSecurityOrder('BANKDATA');
        $this->assertEquals('sign_first', $order);
    }

    public function testGetSecurityOrderForNbs()
    {
        $order = BankCertificateService::getSecurityOrder('NBS');
        $this->assertEquals('sign_first', $order);
    }

    public function testDatacenterEndpoints()
    {
        // Verify that the service knows the correct endpoints
        $endpoints = ((fn() => (new ReflectionClass(BankCertificateService::class))->getConstant('DATACENTER_ENDPOINTS')))();

        $this->assertArrayHasKey('BANKDATA', $endpoints);
        $this->assertArrayHasKey('NBS', $endpoints);
        $this->assertArrayHasKey('BEC', $endpoints);

        $this->assertArrayHasKey('test', $endpoints['BANKDATA']);
        $this->assertArrayHasKey('production', $endpoints['BANKDATA']);
    }

    public function testInvalidDatacenterThrowsException()
    {
        $this->expectException(BankConnectException::class);
        $this->expectExceptionMessage('Unknown datacenter');

        // This would fail during actual fetch, but we can test the validation
        // by checking the datacenter validation in the method
        $reflection = new ReflectionClass(BankCertificateService::class);
        $method = $reflection->getMethod('fetchBankCertificate');
        $method->setAccessible(true);

        $method->invoke($this->service, 'INVALID', 'test', '8079', 'test');
    }

    public function testCertificateForDatacenterSelection()
    {
        // Test that BEC uses its own certificate
        // This is a design test - in reality it would fetch from the service
        $this->assertTrue(true); // Placeholder for actual test with mocked client
    }

    public function testRefreshFetchesDespiteValidCacheAndPreservesItOnFailure(): void
    {
        $fixture = BankCertificateFixture::create();
        $this->conf->global['BANKCONNECT_TRUSTED_CA_PEM'] = $fixture['root'];
        $db = new MockDoliDB();
        $store = new BankCertificateStore($db);
        $store->saveBankCertificate([
            'datacenter' => 'BANKDATA', 'environment' => 'test',
            'certificate_pem' => $fixture['leaf'],
            'verified_response_xml' => $fixture['response'],
        ]);
        $store->saveBankCertificate([
            'datacenter' => 'BANKDATA', 'environment' => 'production',
            'certificate_pem' => $fixture['leaf'],
            'verified_response_xml' => $fixture['response'],
        ]);
        (new BankCertificateStore($db, 2))->saveBankCertificate([
            'datacenter' => 'BEC', 'environment' => 'production',
            'certificate_pem' => $fixture['leaf'],
            'verified_response_xml' => $fixture['response'],
        ]);
        $service = new class($this->conf, $store, $fixture['response']) extends BankCertificateService {
            public int $fetches = 0;
            public string $response;
            public function __construct(Conf $conf, BankCertificateStore $store, string $response)
            {
                parent::__construct($conf, null, $store);
                $this->response = $response;
            }
            protected function createFetchClient(Conf $conf): BankConnectClient
            {
                return new class($conf, $this) extends BankConnectClient {
                    private BankCertificateService $probe;
                    public function __construct(Conf $conf, BankCertificateService $probe)
                    {
                        parent::__construct($conf);
                        $this->probe = $probe;
                    }
                    public function getBankCertificate(string $activationHeaderXml): string
                    {
                        $this->probe->fetches++;
                        return $this->probe->response;
                    }
                };
            }
        };

        $service->fetchBankCertificate('BANKDATA');
        $this->assertSame(0, $service->fetches);
        $service->fetchBankCertificate('BANKDATA', 'test', '8079', null, true);
        $this->assertSame(1, $service->fetches);
        $this->assertSame($fixture['response'], $store->getBankCertificate('BANKDATA', 'test')['verified_response_xml']);

        $service->response = str_replace('BankCert', 'Tampered', $fixture['response']);
        try {
            $service->fetchBankCertificate('BANKDATA', 'test', '8079', null, true);
            $this->fail('A failed forced fetch must not replace the verified cache');
        } catch (BankConnectException $e) {
            $this->assertSame($fixture['response'], $store->getBankCertificate('BANKDATA', 'test')['verified_response_xml']);
        }

        $service->response = $fixture['response'];
        $results = $service->refreshAllCertificates();
        $this->assertCount(2, $results);
        $this->assertSame(4, $service->fetches);
        $environments = array_column($results, 'environment');
        sort($environments);
        $this->assertSame(['production', 'test'], $environments);
        $this->assertSame([], array_filter($results, static fn($result) => $result['status'] !== 'success'));
        $this->assertSame([], (new BankCertificateService($this->conf))->refreshAllCertificates());
    }
}
