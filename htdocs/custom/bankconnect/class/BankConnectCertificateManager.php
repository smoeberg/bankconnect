<?php
/**
 * BankConnectCertificateManager – lifecycle of customer & bank certificates.
 *
 * Responsibilities:
 *  - getBankCertificate (cache per mainRegistrationNumber)
 *  - activateServiceAgreement (first-time onboarding)
 *  - renewCustomerCertificate (every ~3 years)
 *  - Secure storage of private key (AES-256-GCM, key from conf/env)
 *
 * Private keys must NEVER be stored in clear text.
 */

require_once __DIR__.'/BankConnectClient.php';
require_once __DIR__.'/BankConnectException.php';
require_once __DIR__.'/BankConnectLogger.php';

if (!class_exists('Conf')) {
    class Conf
    {
        /** @var array<string,mixed> */
        public $global = [];
    }
}

class BankConnectCertificateManager
{
    private Conf $conf;
    private BankConnectClient $client;
    private BankConnectLogger $logger;

    public function __construct(Conf $conf, ?BankConnectClient $client = null, ?BankConnectLogger $logger = null)
    {
        $this->conf   = $conf;
        $this->client = $client ?? new BankConnectClient($conf);
        $this->logger = $logger ?? new BankConnectLogger();
    }

    /**
     * Fetch bank certificate (used for XML-Encryption of payment payload).
     * Caller should cache the result (e.g. once per day per agreement).
     *
     * @return string PEM certificate(s)
     */
    public function getBankCertificate(string $serviceHeaderXml): string
    {
        $raw = $this->client->getBankCertificate($serviceHeaderXml);
        // TODO: extract PEM from SOAP response / CorporateMessage
        return $raw;
    }

    /**
     * First-time activation.
     * Requires: activation code from bank + PKCS#10 request.
     *
     * @param string $activationCode
     * @param string $pkcs10Pem      Certificate signing request
     * @param string $serviceHeaderXml
     * @return string                Customer certificate (PEM)
     */
    public function activateServiceAgreement(
        string $activationCode,
        string $pkcs10Pem,
        string $serviceHeaderXml
    ): string {
        // TODO: build full ActivateServiceAgreement payload with
        //       ActivationHeader + CertificateRequest + Signature
        $payload = $this->buildActivatePayload($activationCode, $pkcs10Pem, $serviceHeaderXml);
        $raw = $this->client->activateServiceAgreement($payload);
        // TODO: parse CorporateMessage, store cert + encrypted private key
        return $raw;
    }

    /**
     * Renew customer certificate (must be done before valid_to).
     * Request is signed with the *current* certificate.
     */
    public function renewCustomerCertificate(string $pkcs10Pem, string $serviceHeaderXml): string
    {
        $payload = $this->buildRenewPayload($pkcs10Pem, $serviceHeaderXml);
        $raw = $this->client->renewCustomerCertificate($payload);
        return $raw;
    }

    /**
     * Generate RSA keypair + PKCS#10 CSR.
     * Returns ['private_key' => PEM, 'csr' => PEM]
     */
    public function generateKeyPairAndCsr(array $dn = []): array
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $privKey = openssl_pkey_new($config);
        if ($privKey === false) {
            throw new BankConnectException('Failed to generate private key: '.openssl_error_string());
        }

        $defaultDn = [
            'countryName'            => 'DK',
            'organizationName'       => 'BankConnect Customer',
            'commonName'             => 'BankConnect',
        ];
        $dn = array_merge($defaultDn, $dn);

        $csr = openssl_csr_new($dn, $privKey, ['digest_alg' => 'sha256']);
        if ($csr === false) {
            throw new BankConnectException('Failed to create CSR: '.openssl_error_string());
        }

        openssl_pkey_export($privKey, $privPem);
        openssl_csr_export($csr, $csrPem);

        return [
            'private_key' => $privPem,
            'csr'         => $csrPem,
        ];
    }

    /**
     * Encrypt private key for storage (AES-256-GCM).
     * Encryption key is taken from BANKCONNECT_KEY_ENCRYPTION_SECRET.
     */
    public function encryptPrivateKey(string $privateKeyPem): string
    {
        $secret = $this->getEncryptionSecret();
        $iv     = random_bytes(12);
        $tag    = '';
        $cipher = openssl_encrypt(
            $privateKeyPem,
            'aes-256-gcm',
            $secret,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if ($cipher === false) {
            throw new BankConnectException('Failed to encrypt private key');
        }
        // Store as base64(iv + tag + ciphertext)
        return base64_encode($iv.$tag.$cipher);
    }

    public function decryptPrivateKey(string $encrypted): string
    {
        $secret = $this->getEncryptionSecret();
        $raw    = base64_decode($encrypted, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new BankConnectException('Invalid encrypted private key');
        }
        $iv     = substr($raw, 0, 12);
        $tag    = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $secret,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plain === false) {
            throw new BankConnectException('Failed to decrypt private key');
        }
        return $plain;
    }

    private function getEncryptionSecret(): string
    {
        $g = $this->conf->global ?? [];
        $secret = (string) ($g['BANKCONNECT_KEY_ENCRYPTION_SECRET'] ?? getenv('BANKCONNECT_KEY_ENCRYPTION_SECRET') ?: '');
        if ($secret === '') {
            throw new BankConnectException(
                'BANKCONNECT_KEY_ENCRYPTION_SECRET is not configured. '
                .'Set a strong random secret (32+ bytes) in conf or environment.'
            );
        }
        // Derive 32-byte key
        return hash('sha256', $secret, true);
    }

    private function buildActivatePayload(string $activationCode, string $pkcs10Pem, string $serviceHeaderXml): string
    {
        // Skeleton – full XML per BankConnect API docs to be completed
        $csrB64 = base64_encode($pkcs10Pem);
        return '<activateServiceAgreement xmlns="http://bankconnect.dk/schema/2014">'
             . $serviceHeaderXml
             . '<activationCode>'.htmlspecialchars($activationCode, ENT_XML1).'</activationCode>'
             . '<certificateRequest>'.$csrB64.'</certificateRequest>'
             . '</activateServiceAgreement>';
    }

    private function buildRenewPayload(string $pkcs10Pem, string $serviceHeaderXml): string
    {
        $csrB64 = base64_encode($pkcs10Pem);
        return '<renewCustomerCertificate xmlns="http://bankconnect.dk/schema/2014">'
             . $serviceHeaderXml
             . '<certificateRequestMessage>'
             . '<certificateRequest>'.$csrB64.'</certificateRequest>'
             . '</certificateRequestMessage>'
             . '</renewCustomerCertificate>';
    }
}
