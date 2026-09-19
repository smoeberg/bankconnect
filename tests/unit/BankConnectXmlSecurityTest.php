<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectException.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectXmlSecurity.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/ServiceHeaderBuilder.php';

class BankConnectXmlSecurityTest extends TestCase
{
    private string $privatePem;
    private string $publicPem;
    private string $certPem;

    protected function setUp(): void
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $priv = openssl_pkey_new($config);
        $this->assertNotFalse($priv);

        openssl_pkey_export($priv, $this->privatePem);
        $details = openssl_pkey_get_details($priv);
        $this->publicPem = $details['key'];

        // Self-signed cert for "bank" certificate role
        $dn = ['countryName' => 'DK', 'organizationName' => 'Test Bank', 'commonName' => 'BankConnect Test'];
        $csr = openssl_csr_new($dn, $priv, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $priv, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $this->certPem);
    }

    public function testStubEncryptWithoutBankCert(): void
    {
        $sec = new BankConnectXmlSecurity(new Conf());
        $out = $sec->encryptPayload('<Document>test</Document>');
        $this->assertSame(base64_encode('<Document>test</Document>'), $out);
    }

    public function testEncryptDecryptRoundtrip(): void
    {
        $sec = new BankConnectXmlSecurity(new Conf());
        $sec->setBankCertificate($this->certPem);

        $xml = '<?xml version="1.0"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03"><CstmrCdtTrfInitn/></Document>';
        $detailed = $sec->encryptPayloadDetailed($xml);

        $this->assertNotSame(base64_encode($xml), $detailed['content']);
        $this->assertSame(0, $detailed['compressed']);

        $plain = $sec->decryptPayload($detailed['content'], $this->privatePem);
        $this->assertSame($xml, $plain);
    }

    public function testBuildPaymentMessage(): void
    {
        $sec = new BankConnectXmlSecurity(new Conf());
        $msg = $sec->buildPaymentMessage('QUJD', 'e2eid123', 0);
        $this->assertStringContainsString('<format>ISO20022</format>', $msg);
        $this->assertStringContainsString('<content>QUJD</content>', $msg);
        $this->assertStringContainsString('e2eid123', $msg);
    }

    public function testSignWithoutKeyReturnsUnchanged(): void
    {
        $sec = new BankConnectXmlSecurity(new Conf());
        $xml = '<soapenv:Envelope><soapenv:Body/></soapenv:Envelope>';
        $this->assertSame($xml, $sec->signRequest($xml));
    }

    public function testServiceHeaderBuilder(): void
    {
        $h = (new ServiceHeaderBuilder())
            ->setOrganisation('12345678')
            ->setFunctionIdentification('0010888100007')
            ->setEndToEndMessageId('abc123')
            ->setErp('Dolibarr', '20.0')
            ->build();

        $this->assertStringContainsString('<mainRegistrationNumber>12345678</mainRegistrationNumber>', $h);
        $this->assertStringContainsString('<functionIdentification>0010888100007</functionIdentification>', $h);
        $this->assertStringContainsString('<endToEndMessageId>abc123</endToEndMessageId>', $h);
        $this->assertStringContainsString('<createDateTime>', $h);
    }

    public function testServiceHeaderRequiresFields(): void
    {
        $this->expectException(BankConnectException::class);
        (new ServiceHeaderBuilder())->build();
    }

    public function testIsLiveCryptoAvailable(): void
    {
        $sec = new BankConnectXmlSecurity(new Conf());
        $this->assertFalse($sec->isLiveCryptoAvailable());

        $sec->setBankCertificate($this->certPem);
        $sec->setCustomerPrivateKey($this->privatePem);
        $this->assertTrue($sec->isLiveCryptoAvailable());
    }
}
