<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectBootstrapVerifier.php';

final class BankConnectBootstrapVerifierTest extends TestCase
{
    /** @return array{string,string,string} root, bank leaf, unrelated root */
    private function certificates(): array
    {
        $config = tempnam(sys_get_temp_dir(), 'bankconnect-ca-');
        $this->assertNotFalse($config);
        file_put_contents($config, '[req]' . "\n" . 'distinguished_name=dn' . "\n"
            . '[dn]' . "\n" . '[v3_ca]' . "\n" . 'basicConstraints=critical,CA:TRUE' . "\n"
            . '[v3_leaf]' . "\n" . 'basicConstraints=critical,CA:FALSE' . "\n");
        try {
            $options = ['config' => $config, 'digest_alg' => 'sha256'];
            $caKey = openssl_pkey_new(['private_key_bits' => 2048]);
            $caCsr = openssl_csr_new(['commonName' => 'Trusted test CA'], $caKey, $options);
            $ca = openssl_csr_sign($caCsr, null, $caKey, 1, $options + ['x509_extensions' => 'v3_ca']);
            $leafKey = openssl_pkey_new(['private_key_bits' => 2048]);
            $leafCsr = openssl_csr_new(['commonName' => 'Bank test leaf'], $leafKey, $options);
            $leaf = openssl_csr_sign($leafCsr, $ca, $caKey, 1, $options + ['x509_extensions' => 'v3_leaf']);
            $otherKey = openssl_pkey_new(['private_key_bits' => 2048]);
            $otherCsr = openssl_csr_new(['commonName' => 'Untrusted CA'], $otherKey, $options);
            $other = openssl_csr_sign($otherCsr, null, $otherKey, 1, $options + ['x509_extensions' => 'v3_ca']);
            $this->assertTrue(openssl_x509_export($ca, $rootPem));
            $this->assertTrue(openssl_x509_export($leaf, $leafPem));
            $this->assertTrue(openssl_x509_export($other, $otherPem));
            return [$rootPem, $leafPem, $otherPem];
        } finally {
            unlink($config);
        }
    }

    public function testTrustedBankLeafIsAccepted(): void
    {
        [$root, $leaf] = $this->certificates();
        $conf = new Conf();
        $conf->global['BANKCONNECT_TRUSTED_CA_PEM'] = $root;
        (new BankConnectBootstrapVerifier($conf))->verifyCertificateChain([$leaf], $leaf);
        $this->assertTrue(true);
    }

    public function testUnknownRootIsRejected(): void
    {
        [, $leaf, $other] = $this->certificates();
        $conf = new Conf();
        $conf->global['BANKCONNECT_TRUSTED_CA_PEM'] = $other;
        $this->expectException(BankConnectException::class);
        (new BankConnectBootstrapVerifier($conf))->verifyCertificateChain([$leaf], $leaf);
    }

    public function testMissingTrustAnchorIsRejected(): void
    {
        [, $leaf] = $this->certificates();
        $this->expectException(BankConnectException::class);
        $this->expectExceptionMessage('trusted CA certificate is not configured');
        (new BankConnectBootstrapVerifier(new Conf()))->verifyCertificateChain([$leaf], $leaf);
    }

    public function testDuplicateBankLeafIsRejected(): void
    {
        [$root, $leaf] = $this->certificates();
        $conf = new Conf();
        $conf->global['BANKCONNECT_TRUSTED_CA_PEM'] = $root;
        $this->expectException(BankConnectException::class);
        (new BankConnectBootstrapVerifier($conf))->verifyCertificateChain([$leaf, $leaf], $leaf);
    }

    public function testUnsignedCertificateResponseIsRejected(): void
    {
        [$root, $leaf] = $this->certificates();
        $conf = new Conf();
        $conf->global['BANKCONNECT_TRUSTED_CA_PEM'] = $root;
        $xml = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:bc="http://bankconnect.dk/schema/2014">'
            .'<soap:Header/><soap:Body><bc:getBankCertificateResponse><bc:corporateMessage id="bank">'
            .'<content>'.base64_encode($leaf).'</content></bc:corporateMessage></bc:getBankCertificateResponse>'
            .'</soap:Body></soap:Envelope>';
        $this->expectException(BankConnectException::class);
        $this->expectExceptionMessage('business response requires one message and one signature');
        (new BankConnectBootstrapVerifier($conf))->verify($xml);
    }
}
