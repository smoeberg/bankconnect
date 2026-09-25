<?php
/** Validate the bank certificate before it is used to encrypt onboarding data. */
require_once __DIR__.'/BankConnectException.php';
require_once __DIR__.'/BankConnectCertificateManager.php';
require_once __DIR__.'/BankConnectResponseSecurity.php';

class BankConnectBootstrapVerifier
{
    private Conf $conf;

    public function __construct(Conf $conf)
    {
        $this->conf = $conf;
    }

    public function verify(string $responseXml): string
    {
        $manager = new BankConnectCertificateManager($this->conf);
        $certificates = $manager->extractCertificatesFromContent($responseXml);
        $leaf = $manager->selectBankCertificatePem($certificates);
        $this->verifyCertificateChain($certificates, $leaf);

        // The leaf certificate becomes trusted only after its chain is verified.
        $temporaryConf = clone $this->conf;
        $temporaryConf->global = (array) ($this->conf->global ?? []);
        $temporaryConf->global['BANKCONNECT_BANK_CERTIFICATE'] = $leaf;
        (new BankConnectResponseSecurity($temporaryConf))->verifyBusinessSignature(
            $responseXml,
            'getBankCertificateResponse'
        );
        return $leaf;
    }

    /** @param string[] $certificates PEM certificates in the signed corporateMessage. */
    public function verifyCertificateChain(array $certificates, string $leaf): void
    {
        $root = $this->conf->global['BANKCONNECT_TRUSTED_CA_PEM'] ?? null;
        if (!is_string($root) || trim($root) === '') {
            throw new BankConnectException('BankConnect trusted CA certificate is not configured');
        }
        $this->requireValidCertificate($root, true);
        $rootDetails = openssl_x509_parse($root);
        if ($rootDetails['subject'] !== $rootDetails['issuer'] || openssl_x509_verify($root, $root) !== 1) {
            throw new BankConnectException('BankConnect trusted CA must be a self-signed root');
        }
        $this->requireValidCertificate($leaf, false);
        if (count($certificates) < 1 || count($certificates) > 2) {
            throw new BankConnectException('Unexpected BankConnect bank certificate chain length');
        }
        $leafFingerprint = openssl_x509_fingerprint($leaf, 'sha256');
        $other = [];
        foreach ($certificates as $pem) {
            if (openssl_x509_fingerprint($pem, 'sha256') !== $leafFingerprint) {
                $other[] = $pem;
            }
        }
        if (count($other) !== count($certificates) - 1) {
            throw new BankConnectException('Duplicate BankConnect bank certificates');
        }
        $issuer = $root;
        if ($other !== []) {
            $issuer = $other[0];
            $this->requireValidCertificate($issuer, true);
            $this->requireIssuedBy($issuer, $root);
        }
        $this->requireIssuedBy($leaf, $issuer);
    }

    private function requireValidCertificate(string $pem, bool $ca): void
    {
        $info = openssl_x509_parse($pem);
        if ($info === false || ($info['validFrom_time_t'] ?? PHP_INT_MAX) > time()
            || ($info['validTo_time_t'] ?? 0) < time()) {
            throw new BankConnectException('Invalid or expired BankConnect certificate in trust chain');
        }
        $constraints = (string) ($info['extensions']['basicConstraints'] ?? '');
        if (!preg_match($ca ? '/(?:^|,)\s*CA\s*:\s*TRUE\b/i' : '/(?:^|,)\s*CA\s*:\s*FALSE\b/i', $constraints)) {
            throw new BankConnectException('Unexpected CA constraint in BankConnect certificate chain');
        }
    }

    private function requireIssuedBy(string $certificate, string $issuer): void
    {
        $subjectDetails = openssl_x509_parse($certificate);
        $issuerDetails = openssl_x509_parse($issuer);
        if ($subjectDetails === false || $issuerDetails === false
            || $subjectDetails['issuer'] !== $issuerDetails['subject']
            || openssl_x509_verify($certificate, $issuer) !== 1) {
            throw new BankConnectException('Untrusted BankConnect certificate chain');
        }
    }
}
