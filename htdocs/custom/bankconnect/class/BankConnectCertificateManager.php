<?php
/**
 * BankConnectCertificateManager – lifecycle of customer & bank certificates.
 *
 * Onboarding (ActivateServiceAgreement) per official step-by-step guide:
 *  1. getBankCertificate → bank cert for encryption
 *  2. Generate RSA keypair with CN = functionIdentification@activationCode
 *  3. CSR (DER/base64 without PEM headers / line breaks)
 *  4. activationCode: strip dashes, base64-encode
 *  5. Encrypt body with bank cert (handled by XmlSecurity at send time)
 *  6. Parse response content → customer certificate PEM
 *  7. Store cert + AES-GCM encrypted private key via AgreementStore
 */

require_once __DIR__.'/BankConnectClient.php';
require_once __DIR__.'/BankConnectException.php';
require_once __DIR__.'/BankConnectLogger.php';
require_once __DIR__.'/AgreementStore.php';
require_once __DIR__.'/ServiceHeaderBuilder.php';
require_once __DIR__.'/BankConnectSecretStore.php';
require_once __DIR__.'/BankCertificateStore.php';

if (!class_exists('Conf')) {
    class Conf
    {
        /** @var array<string,mixed> */
        public $global = [];
    }
}

class BankConnectCertificateManager
{
    public const STATE_MISSING = 'MISSING';
    public const STATE_IMPORTED = 'IMPORTED';
    public const STATE_VALID = 'VALID';
    public const STATE_EXPIRING = 'EXPIRING';
    public const STATE_EXPIRED = 'EXPIRED';
    public const STATE_REVOKED = 'REVOKED';
    public const STATE_INVALID = 'INVALID';

    private Conf $conf;
    private BankConnectClient $client;
    private BankConnectLogger $logger;
    private ?AgreementStore $store;
    private ?BankCertificateStore $bankCertificateStore;

    public function __construct(
        Conf $conf,
        ?BankConnectClient $client = null,
        ?BankConnectLogger $logger = null,
        ?AgreementStore $store = null,
        ?BankCertificateStore $bankCertificateStore = null
    ) {
        $this->conf   = $conf;
        $this->client = $client ?? new BankConnectClient($conf);
        $this->logger = $logger ?? new BankConnectLogger();
        $this->store  = $store;
        $this->bankCertificateStore = $bankCertificateStore;
    }

    public function setStore(AgreementStore $store): void
    {
        $this->store = $store;
    }

    /**
     * Full first-time onboarding.
     *
     * @param array{
     *   activation_code:string,
     *   function_identification:string,
     *   main_registration_number?:string,
     *   label?:string,
     *   entity?:int,
     *   fk_user?:int,
	 *   datacenter?:string,
	 *   endpoint?:string,
     *   dry_run?:bool  If true, do not call SOAP – only generate + persist draft
     * } $opts
     * @return array{
     *   agreement_id:int,
     *   certificate_id:?int,
     *   status:string,
     *   csr_b64:string,
     *   activation_code_b64:string
     * }
     */
    public function onboard(array $opts): array
    {
        $activationCode = preg_replace('/[^0-9A-Za-z]/', '', $opts['activation_code'] ?? '');
        $functionId     = (string) ($opts['function_identification'] ?? '');
        $mainReg        = (string) ($opts['main_registration_number'] ?? '8079'); // test default Sydbank
        $dryRun         = !empty($opts['dry_run']);
        $datacenter     = strtoupper(trim($opts['datacenter'] ?? 'BANKDATA'));
        $environment    = $this->getEnvironmentFromEndpoint($opts['endpoint'] ?? '');

        if ($activationCode === '' || $functionId === '') {
            throw new BankConnectException('activation_code and function_identification are required');
        }

        $keypair = $this->generateKeyPairAndCsr([
            'commonName' => $functionId.'@'.$activationCode,
        ]);

        $csrB64 = $this->csrToRequestBody($keypair['csr']);
        $actB64 = base64_encode($activationCode);

        $header = (new ServiceHeaderBuilder())
            ->setOrganisation($mainReg, 'DK')
            ->setFunctionIdentification($functionId)
            ->build();

        $customerCertPem = null;
        $status = 'draft';

        if (!$dryRun) {
            // Try to fetch bank certificate automatically if not already configured
            $bankCertPem = $this->fetchBankCertificateIfNeeded($datacenter, $environment, $mainReg, $functionId);
            if ($bankCertPem === null || trim($bankCertPem) === '') {
                throw new BankConnectException(
                    'Bankens BankConnect-certifikat kunne ikke hentes automatisk (GetBankCertificate). '
                    .'Sæt BANKCONNECT_BANK_CERTIFICATE i miljø eller konfiguration, eller kontrollér datacenter/mainReg.'
                );
            }
            // getBankCertificate is deliberately unsigned, but activation must
            // immediately encrypt with the certificate returned by that call.
            $this->client->setBankCertificate($bankCertPem);
            
            $raw = $this->activateServiceAgreement($activationCode, $keypair['csr'], $header);
            $customerCertPem = $this->extractCustomerCertificatePem($raw, $keypair['private_key']);
            if ($customerCertPem === null || $customerCertPem === '') {
                throw new BankConnectException('No customer certificate found in activateServiceAgreement response');
            }
            $status = 'active';
        }

        if ($this->store === null) {
            return [
                'agreement_id'         => 0,
                'certificate_id'       => null,
                'status'               => $status,
                'csr_b64'              => $csrB64,
                'activation_code_b64'  => $actB64,
                'customer_cert_pem'    => $customerCertPem,
                'private_key_pem'      => $keypair['private_key'], // only when no store – caller must secure
                'bank_certificate_pem' => $bankCertPem ?? null,
            ];
        }

        $certId = null;
        $validity = null;
        if ($customerCertPem !== null) {
            $validity = $this->parseCertValidity($customerCertPem);
            if ($validity['from'] === null || $validity['to'] === null) {
                throw new BankConnectException('Customer certificate could not be parsed');
            }
            if (!$this->isCertificateCurrentlyValid($validity)) {
                throw new BankConnectException('Customer certificate is outside its validity period');
            }
            if (!$this->validateCertificateAndPrivateKey($customerCertPem, $keypair['private_key'])) {
                throw new BankConnectException('Customer certificate does not match generated private key');
            }
        }

        $agreementId = $this->store->createAgreement([
            'entity'                    => (int) ($opts['entity'] ?? 1),
            'label'                     => $opts['label'] ?? ('BC '.$functionId),
            'bank_connect_id'           => $functionId,
            'main_registration_number'  => $mainReg,
			'datacenter'                 => (string)($opts['datacenter'] ?? ''),
			'endpoint'                   => (string)($opts['endpoint'] ?? ''),
            'status'                    => $status,
            'fk_user_creat'             => (int) ($opts['fk_user'] ?? 0),
        ]);

        if ($customerCertPem !== null) {
            $certId = $this->store->saveCertificate([
                'fk_agreement'     => $agreementId,
                'certificate_pem'  => $customerCertPem,
                'private_key_enc'  => $this->encryptPrivateKey($keypair['private_key']),
                'valid_from'       => $validity['from'],
                'valid_to'         => $validity['to'],
                'is_active'        => 1,
            ]);
            $this->store->updateAgreementStatus($agreementId, 'active', date('Y-m-d H:i:s'));
        }

        $this->logger->info('onboard_complete', [
            'agreement_id' => $agreementId,
            'certificate_id' => $certId,
            'dry_run' => $dryRun,
        ]);

        // Wipe plaintext key from return
        return [
            'agreement_id'        => $agreementId,
            'certificate_id'      => $certId,
            'status'              => $status,
            'csr_b64'             => $csrB64,
            'activation_code_b64' => $actB64,
        ];
    }

    public function getBankCertificate(string $serviceHeaderXml): string
    {
        $raw = $this->client->getBankCertificate($serviceHeaderXml);
        $pem = $this->extractCertificatesFromContent($raw);
        if (empty($pem)) {
            throw new BankConnectException('Bank certificate response did not contain a certificate');
        }
        $this->validateCertificatePem($pem[0]);
        return $pem[0];
    }

    public function activateServiceAgreement(
        string $activationCode,
        string $pkcs10Pem,
        string $serviceHeaderXml
    ): string {
        $payload = $this->buildActivatePayload($activationCode, $pkcs10Pem, $serviceHeaderXml);
        return $this->client->activateServiceAgreement($payload);
    }

    public function renewCustomerCertificate(string $pkcs10Pem, string $serviceHeaderXml): string
    {
        $payload = $this->buildRenewPayload($pkcs10Pem, $serviceHeaderXml);
        return $this->client->renewCustomerCertificate($payload);
    }

    /**
     * Renew a customer certificate and persist it only after cryptographic validation.
     * Future-dated certificates are staged inactive so the current certificate remains usable.
     *
     * @return array{agreement_id:int, certificate_id:int, status:string, csr_b64:string}
     */
    public function renewCustomerCertificateForAgreement(int $agreementId, string $serviceHeaderXml): array
    {
        if ($this->store === null) {
            throw new BankConnectException('AgreementStore not configured');
        }

        $agreement = $this->store->getAgreement($agreementId);
        if ($agreement === null) {
            throw new BankConnectException("Agreement {$agreementId} not found");
        }

        $keypair = $this->generateKeyPairAndCsr([
            'commonName' => (string) ($agreement['bank_connect_id'] ?? 'BankConnect'),
        ]);
        $csrB64 = $this->csrToRequestBody($keypair['csr']);

        $raw = $this->renewCustomerCertificate($keypair['csr'], $serviceHeaderXml);
        $customerCertPem = $this->extractCustomerCertificatePem($raw, $keypair['private_key']);
        if ($customerCertPem === null || $customerCertPem === '') {
            throw new BankConnectException('No customer certificate found in renewCustomerCertificate response');
        }

        $validity = $this->parseCertValidity($customerCertPem);
        if ($validity['from'] === null || $validity['to'] === null) {
            throw new BankConnectException('Renewed customer certificate could not be parsed');
        }
        if (!$this->validateCertificateAndPrivateKey($customerCertPem, $keypair['private_key'])) {
            throw new BankConnectException('Renewed customer certificate does not match generated private key');
        }

        $currentlyValid = $this->isCertificateCurrentlyValid($validity);
        $certificateId = $this->store->saveCertificate([
            'fk_agreement' => $agreementId,
            'certificate_pem' => $customerCertPem,
            'private_key_enc' => $this->encryptPrivateKey($keypair['private_key']),
            'valid_from' => $validity['from'],
            'valid_to' => $validity['to'],
            'is_active' => $currentlyValid ? 1 : 0,
        ]);

        if ($currentlyValid) {
            $this->store->updateAgreementStatus($agreementId, 'active', date('Y-m-d H:i:s'));
        }

        return [
            'agreement_id' => $agreementId,
            'certificate_id' => $certificateId,
            'status' => $currentlyValid ? 'active' : 'staged',
            'csr_b64' => $csrB64,
        ];
    }

    /**
     * @return array{private_key:string, csr:string}
     */
    public function generateKeyPairAndCsr(array $dn = []): array
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        foreach (['/etc/ssl/openssl.cnf', '/etc/pki/tls/openssl.cnf'] as $cnf) {
            if (is_readable($cnf)) {
                $config['config'] = $cnf;
                break;
            }
        }

        $privKey = openssl_pkey_new($config);
        if ($privKey === false) {
            throw new BankConnectException('Failed to generate private key: '.openssl_error_string());
        }

        $defaultDn = [
            'countryName'      => 'DK',
            'organizationName' => 'BankConnect Customer',
            'commonName'       => 'BankConnect',
        ];
        $dn = array_merge($defaultDn, $dn);

        $csrConfig = ['digest_alg' => 'sha256'];
        if (isset($config['config'])) {
            $csrConfig['config'] = $config['config'];
        }

        $csr = openssl_csr_new($dn, $privKey, $csrConfig);
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

    /** Strip PEM headers/newlines → single base64 body for <certificateRequest> */
    public function csrToRequestBody(string $csrPem): string
    {
        $body = preg_replace('/-----BEGIN[^-]*-----/', '', $csrPem);
        $body = preg_replace('/-----END[^-]*-----/', '', $body);
        return preg_replace('/\s+/', '', $body);
    }

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

    /**
     * Determine environment from endpoint URL.
     */
    private function getEnvironmentFromEndpoint(string $endpoint): string
    {
        if (str_contains($endpoint, 'stest.bankconnect.dk')) {
            return 'test';
        }
        if (str_contains($endpoint, 'bankconnectservices.dk')) {
            return 'production';
        }
        return 'test';
    }

    /**
     * Load active customer private key (decrypted) for an agreement.
     */
    public function loadCustomerPrivateKey(int $agreementId): string
    {
        if ($this->store === null) {
            throw new BankConnectException('AgreementStore not configured');
        }
        $cert = $this->store->getActiveCertificate($agreementId);
        if (!$cert) {
            throw new BankConnectException("No active certificate for agreement {$agreementId}");
        }
        $validity = [
            'from' => $cert['valid_from'] ?? null,
            'to' => $cert['valid_to'] ?? null,
        ];
        if (!$this->isCertificateCurrentlyValid($validity)) {
            throw new BankConnectException("Active certificate for agreement {$agreementId} is outside its validity period");
        }

        $privateKey = $this->decryptPrivateKey($cert['private_key_enc']);
        if (!$this->validateCertificateAndPrivateKey($cert['certificate_pem'], $privateKey)) {
            throw new BankConnectException("Active certificate for agreement {$agreementId} does not match its private key");
        }

        return $privateKey;
    }

    public function loadCustomerCertificatePem(int $agreementId): string
    {
        if ($this->store === null) {
            throw new BankConnectException('AgreementStore not configured');
        }
        $cert = $this->store->getActiveCertificate($agreementId);
        if (!$cert) {
            throw new BankConnectException("No active certificate for agreement {$agreementId}");
        }
        $validity = [
            'from' => $cert['valid_from'] ?? null,
            'to' => $cert['valid_to'] ?? null,
        ];
        if (!$this->isCertificateCurrentlyValid($validity)) {
            throw new BankConnectException("Active certificate for agreement {$agreementId} is outside its validity period");
        }

        $privateKey = $this->decryptPrivateKey($cert['private_key_enc']);
        if (!$this->validateCertificateAndPrivateKey($cert['certificate_pem'], $privateKey)) {
            throw new BankConnectException("Active certificate for agreement {$agreementId} does not match its private key");
        }

        return $cert['certificate_pem'];
    }

    /**
     * Extract PEM certificates from SOAP / content base64 blob.
     * @return string[]
     */
    public function extractCertificatesFromContent(string $raw): array
    {
        $pems = [];

        // Already PEM?
        if (str_contains($raw, '-----BEGIN CERTIFICATE-----')) {
            if (preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $raw, $m)) {
                return $m[0];
            }
        }

        // content tag base64
        if (preg_match('/<content[^>]*>([^<]+)<\/content>/i', $raw, $m)) {
            $decoded = base64_decode(trim($m[1]), true);
            if ($decoded !== false && str_contains($decoded, '-----BEGIN')) {
                if (preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $decoded, $pm)) {
                    return $pm[0];
                }
            }
            // Maybe the content is the cert base64 without PEM headers
            if ($decoded !== false && strlen($decoded) > 100) {
                $pems[] = "-----BEGIN CERTIFICATE-----\n"
                    .chunk_split(base64_encode($decoded), 64, "\n")
                    ."-----END CERTIFICATE-----\n";
            }
        }

        return $pems;
    }

    public function extractCustomerCertificatePem(string $raw, ?string $privateKeyPem = null): ?string
    {
        $list = $this->extractCertificatesFromContent($raw);
        if (empty($list)) {
            return null;
        }
        if ($privateKeyPem !== null) {
            foreach ($list as $certificatePem) {
                if ($this->validateCertificateAndPrivateKey($certificatePem, $privateKeyPem)) {
                    return $certificatePem;
                }
            }
            throw new BankConnectException('No returned customer certificate matches the generated private key');
        }
        if (count($list) !== 1) {
            throw new BankConnectException('Multiple certificates returned; private-key binding is required to identify the customer certificate');
        }
        $this->validateCertificatePem($list[0]);
        return $list[0];
    }

    public function validateCertificatePem(string $certificatePem): array
    {
        $certificate = openssl_x509_read($certificatePem);
        if ($certificate === false) {
            throw new BankConnectException('Invalid X.509 certificate');
        }
        $parsed = openssl_x509_parse($certificate);
        if ($parsed === false) {
            throw new BankConnectException('Unable to parse X.509 certificate');
        }
        $publicKey = openssl_pkey_get_public($certificate);
        if ($publicKey === false) {
            throw new BankConnectException('Certificate does not contain a usable public key');
        }
        $details = openssl_pkey_get_details($publicKey);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || (int) ($details['bits'] ?? 0) < 2048) {
            throw new BankConnectException('BankConnect requires an RSA certificate with at least 2048 bits');
        }
        $validFrom = isset($parsed['validFrom_time_t']) ? (int) $parsed['validFrom_time_t'] : null;
        $validTo = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
        if ($validFrom === null || $validTo === null || $validFrom > $validTo) {
            throw new BankConnectException('Certificate validity interval is invalid');
        }
        return [
            'fingerprint_sha256' => openssl_x509_fingerprint($certificate, 'sha256'),
            'valid_from' => date('Y-m-d H:i:s', $validFrom),
            'valid_to' => date('Y-m-d H:i:s', $validTo),
        ];
    }

    public function certificateState(array $certificate, ?int $now = null, int $warningDays = 30): string
    {
        if (!empty($certificate['revoked_at']) || (($certificate['status'] ?? '') === self::STATE_REVOKED)) {
            return self::STATE_REVOKED;
        }
        $pem = (string) ($certificate['certificate_pem'] ?? '');
        if ($pem === '') {
            return self::STATE_MISSING;
        }
        try {
            $meta = $this->validateCertificatePem($pem);
        } catch (BankConnectException $e) {
            return self::STATE_INVALID;
        }
        $now = $now ?? time();
        $from = strtotime($meta['valid_from']);
        $to = strtotime($meta['valid_to']);
        if ($from === false || $to === false) {
            return self::STATE_INVALID;
        }
        if ($now < $from) {
            return self::STATE_IMPORTED;
        }
        if ($now > $to) {
            return self::STATE_EXPIRED;
        }
        if ($warningDays > 0 && $now >= ($to - ($warningDays * 86400))) {
            return self::STATE_EXPIRING;
        }
        return self::STATE_VALID;
    }

    public function certificateFingerprint(string $certificatePem): string
    {
        $meta = $this->validateCertificatePem($certificatePem);
        return (string) $meta['fingerprint_sha256'];
    }

    public function isCertificateExpiringSoon(string $certificatePem, int $warningDays = 30, ?int $now = null): bool
    {
        return $this->certificateState(['certificate_pem' => $certificatePem], $now, $warningDays) === self::STATE_EXPIRING;
    }


    /** @return array{from:?string, to:?string} */
    public function validateCertificateAndPrivateKey(string $certificatePem, string $privateKeyPem): bool
    {
        $certificate = openssl_x509_read($certificatePem);
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($certificate === false || $privateKey === false) {
            return false;
        }
        return openssl_x509_check_private_key($certificate, $privateKey);
    }

    /** @param array{from:?string,to:?string} $validity */
    public function isCertificateCurrentlyValid(array $validity, ?int $now = null): bool
    {
        if ($validity['from'] === null || $validity['to'] === null) {
            return false;
        }

        $from = strtotime($validity['from']);
        $to = strtotime($validity['to']);
        $now = $now ?? time();

        return $from !== false && $to !== false && $from <= $now && $now <= $to;
    }

    /** @return array{from:?string, to:?string} */
    public function parseCertValidity(string $pem): array
    {
        $parsed = openssl_x509_parse($pem);
        if ($parsed === false) {
            return ['from' => null, 'to' => null];
        }
        $from = isset($parsed['validFrom_time_t']) ? date('Y-m-d H:i:s', $parsed['validFrom_time_t']) : null;
        $to   = isset($parsed['validTo_time_t']) ? date('Y-m-d H:i:s', $parsed['validTo_time_t']) : null;
        return ['from' => $from, 'to' => $to];
    }


    /**
     * Fetch bank certificate automatically if not already available.
     */
    private function fetchBankCertificateIfNeeded(
        string $datacenter,
        string $environment,
        string $mainReg,
        string $functionId
    ): ?string {
        $envCert = getenv('BANKCONNECT_BANK_CERTIFICATE');
        if ($envCert !== false && trim($envCert) !== '') {
            return trim($envCert);
        }
        $g = (array) ($this->conf->global ?? []);
        if (!empty($g['BANKCONNECT_BANK_CERTIFICATE'])) {
            return $g['BANKCONNECT_BANK_CERTIFICATE'];
        }
        if ($this->bankCertificateStore !== null) {
            $cached = $this->bankCertificateStore->getBankCertificate($datacenter, $environment);
            if ($cached !== null
                && $this->bankCertificateStore->isCertificateValid((string)$cached['certificate_pem'])) {
                return (string)$cached['certificate_pem'];
            }
        }
        try {
            $header = (new ServiceHeaderBuilder())
                ->setOrganisation($mainReg, 'DK')
                ->setFunctionIdentification($functionId)
                ->build();
            $certificatePem = $this->getBankCertificate($header);
            if ($this->bankCertificateStore !== null) {
                $meta = $this->bankCertificateStore->validateCertificatePem($certificatePem);
                $this->bankCertificateStore->saveBankCertificate([
                    'datacenter' => $datacenter,
                    'environment' => $environment,
                    'certificate_pem' => $certificatePem,
                    'fingerprint_sha256' => $meta['fingerprint_sha256'],
                    'valid_from' => $meta['valid_from'],
                    'valid_to' => $meta['valid_to'],
                ]);
            }
            return $certificatePem;
        } catch (BankConnectException $e) {
            $this->logger->warning('auto_fetch_bank_cert_failed', [
                'datacenter' => $datacenter,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }


    private function getEncryptionSecret(): string
    {
        $secret = (new BankConnectSecretStore($this->conf))
            ->requireMinLength('BANKCONNECT_KEY_ENCRYPTION_SECRET', 32);
        return hash('sha256', $secret, true);
    }

    private function buildActivatePayload(string $activationCode, string $pkcs10Pem, string $serviceHeaderXml): string
    {
        $codeClean = preg_replace('/[^0-9A-Za-z]/', '', $activationCode);
        $actB64 = base64_encode($codeClean);
        $csrB64 = $this->csrToRequestBody($pkcs10Pem);

        return '<activateServiceAgreement xmlns="http://bankconnect.dk/schema/2014">'
             . $serviceHeaderXml
             . '<activationCode>'.$actB64.'</activationCode>'
             . '<certificateRequest>'.$csrB64.'</certificateRequest>'
             . '</activateServiceAgreement>';
    }

    private function buildRenewPayload(string $pkcs10Pem, string $serviceHeaderXml): string
    {
        $csrB64 = $this->csrToRequestBody($pkcs10Pem);
        return '<renewCustomerCertificate xmlns="http://bankconnect.dk/schema/2014">'
             . $serviceHeaderXml
             . '<certificateRequestMessage>'
             . '<certificateRequest>'.$csrB64.'</certificateRequest>'
             . '</certificateRequestMessage>'
             . '</renewCustomerCertificate>';
    }
}
