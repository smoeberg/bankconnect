<?php
/**
 * BankCertificateService - Service for fetching and managing BankConnect bank certificates.
 *
 * Handles:
 * - Automatic fetching of bank certificates via getBankCertificate
 * - Selection of correct certificate based on datacenter
 * - Validation and secure storage of bank certificates
 * - Caching and refresh logic
 */

require_once __DIR__.'/BankConnectException.php';
require_once __DIR__.'/BankConnectCertificateManager.php';
require_once __DIR__.'/BankCertificateStore.php';
require_once __DIR__.'/ServiceHeaderBuilder.php';
require_once __DIR__.'/BankConnectClient.php';

if (!class_exists('Conf')) {
    class Conf
    {
        /** @var array<string,mixed> */
        public $global = [];
    }
}

class BankCertificateService
{
    private Conf $conf;
    private BankConnectClient $client;
    private ?BankCertificateStore $bankCertStore = null;
    private BankConnectCertificateManager $certManager;

    /**
     * Known BankConnect datacenter endpoints for certificate fetching.
     * Maps datacenter name to its getBankCertificate endpoint.
     */
    private const DATACENTER_ENDPOINTS = [
        'BANKDATA' => [
            'test' => 'https://stest.bankconnect.dk/2019/04/04/services/CorporateService',
            'production' => 'https://bankconnectservices.dk/2019/04/04/services/CorporateService',
        ],
        'NBS' => [
            'test' => 'https://stest.bankconnect.dk/2019/04/04/services/CorporateService',
            'production' => 'https://bankconnectservices.dk/2019/04/04/services/CorporateService',
        ],
        'BEC' => [
            'test' => 'https://stest.bankconnect.dk/2019/04/04/services/CorporateService',
            'production' => 'https://bankconnectservices.dk/2019/04/04/services/CorporateService',
        ],
    ];

    public function __construct(
        Conf $conf,
        ?BankConnectClient $client = null,
        ?BankCertificateStore $bankCertStore = null
    ) {
        $this->conf = $conf;
        $this->client = $client ?? new BankConnectClient($conf);
        $this->bankCertStore = $bankCertStore;
        $this->certManager = new BankConnectCertificateManager($conf);
    }

    public function setBankCertificateStore(?BankCertificateStore $store): void
    {
        $this->bankCertStore = $store;
    }

    /**
     * Fetch the bank certificate for a specific datacenter and environment.
     *
     * This is the main method for PR #60 - it automatically fetches the BankConnect
     * bank certificate without requiring manual configuration.
     *
     * @param string $datacenter One of: BANKDATA, NBS, BEC
     * @param string $environment One of: test, production
     * @param string $mainRegistrationNumber The CVR number for the service header
     * @param string $functionIdentification The BankConnect ID (optional for certificate fetch)
     * @return string The PEM-encoded bank certificate
     */
    public function fetchBankCertificate(
        string $datacenter,
        string $environment = 'test',
        string $mainRegistrationNumber = '8079',
        ?string $functionIdentification = null
    ): string {
        $datacenter = strtoupper(trim($datacenter));
        $environment = strtolower(trim($environment));

        if (!isset(self::DATACENTER_ENDPOINTS[$datacenter])) {
            throw new BankConnectException("Unknown datacenter: {$datacenter}. Must be BANKDATA, NBS, or BEC.");
        }

        if (!isset(self::DATACENTER_ENDPOINTS[$datacenter][$environment])) {
            throw new BankConnectException("Unknown environment: {$environment}. Must be test or production.");
        }

        // Check if we already have a valid certificate cached
        if ($this->bankCertStore !== null) {
            $cachedCert = $this->bankCertStore->getBankCertificate($datacenter, $environment);
            if ($cachedCert !== null && $this->bankCertStore->isCertificateValid($cachedCert['certificate_pem'])) {
                return $cachedCert['certificate_pem'];
            }
        }

        // Build service header for the getBankCertificate request
        $header = (new ServiceHeaderBuilder())
            ->setOrganisation($mainRegistrationNumber, 'DK')
            ->setFunctionIdentification($functionIdentification ?? $this->generateTemporaryFunctionId())
            ->build();

        // Use a temporary client configured for certificate fetching
        $tempConf = clone $this->conf;
        $tempGlobals = (array)($this->conf->global ?? []);
        
        // Set the appropriate endpoint for certificate fetching
        $endpoint = self::DATACENTER_ENDPOINTS[$datacenter][$environment];
        $tempGlobals['BANKCONNECT_ENDPOINT'] = $endpoint;
        $tempGlobals['BANKCONNECT_ENVIRONMENT'] = $environment;
        $tempGlobals['BANKCONNECT_DATACENTER'] = $datacenter;
        
        $tempConf->global = $tempGlobals;
        $tempClient = new BankConnectClient($tempConf);

        // Fetch the certificate via getBankCertificate
        $rawResponse = $tempClient->getBankCertificate($header);

        // Extract and validate the certificate
        $pem = $this->certManager->extractCertificatesFromContent($rawResponse);
        
        if (empty($pem)) {
            throw new BankConnectException('No certificate found in getBankCertificate response');
        }

        // Responses may contain an intermediate before the bank leaf.
        $certificatePem = $this->certManager->selectBankCertificatePem($pem);

        // Store the certificate for future use
        if ($this->bankCertStore !== null) {
            $meta = $this->bankCertStore->validateCertificatePem($certificatePem);
            $this->bankCertStore->saveBankCertificate([
                'datacenter' => $datacenter,
                'environment' => $environment,
                'certificate_pem' => $certificatePem,
                'fingerprint_sha256' => $meta['fingerprint_sha256'],
                'valid_from' => $meta['valid_from'],
                'valid_to' => $meta['valid_to'],
            ]);
        }

        return $certificatePem;
    }

    /**
     * Get the bank certificate for a datacenter, fetching if necessary.
     *
     * This is the primary method to use in the onboarding flow.
     * It will automatically fetch the certificate if not already cached.
     *
     * @param string $datacenter One of: BANKDATA, NBS, BEC
     * @param string $environment One of: test, production
     * @param string $mainRegistrationNumber The CVR number
     * @param string $functionIdentification The BankConnect ID (optional)
     * @return string The PEM-encoded bank certificate
     */
    public function getOrFetchBankCertificate(
        string $datacenter,
        string $environment = 'test',
        string $mainRegistrationNumber = '8079',
        ?string $functionIdentification = null
    ): string {
        $datacenter = strtoupper(trim($datacenter));
        $environment = strtolower(trim($environment));

        // Try to get from cache first
        if ($this->bankCertStore !== null) {
            $cachedCert = $this->bankCertStore->getBankCertificate($datacenter, $environment);
            if ($cachedCert !== null && $this->bankCertStore->isCertificateValid($cachedCert['certificate_pem'])) {
                return $cachedCert['certificate_pem'];
            }
        }

        // Fetch fresh certificate
        return $this->fetchBankCertificate($datacenter, $environment, $mainRegistrationNumber, $functionIdentification);
    }

    /**
     * Select the appropriate certificate based on datacenter.
     *
     * Different datacenters (BANKDATA, NBS, BEC) may have different certificates
     * and security requirements. This method ensures the correct certificate is used.
     *
     * @param string $datacenter The datacenter to get certificate for
     * @param string $environment test or production
     * @return string The PEM-encoded certificate
     */
    public function getCertificateForDatacenter(string $datacenter, string $environment = 'test'): string
    {
        $datacenter = strtoupper(trim($datacenter));

        // For BEC, we need to use the BEC-specific certificate
        // For BANKDATA and NBS, they share the same certificate in test
        if ($datacenter === 'BEC') {
            return $this->getOrFetchBankCertificate('BEC', $environment);
        }

        // BANKDATA and NBS use the same certificate in the test environment
        // In production, they may have different certificates
        return $this->getOrFetchBankCertificate($datacenter, $environment);
    }

    /**
     * Validate that a certificate is suitable for BankConnect.
     *
     * Checks:
     * - Valid X.509 certificate
     * - RSA key with at least 2048 bits
     * - Not expired
     * - Has a valid fingerprint
     *
     * @param string $pem The PEM-encoded certificate
     * @return array Validation result with details
     */
    public function validateBankCertificate(string $pem): array
    {
        return $this->certManager->validateCertificatePem($pem);
    }

    /**
     * Refresh all cached bank certificates.
     *
     * Useful for maintenance or when certificates are rotated.
     *
     * @return array Results of refresh operations
     */
    public function refreshAllCertificates(): array
    {
        $results = [];
        $datacenters = ['BANKDATA', 'NBS', 'BEC'];
        $environments = ['test', 'production'];

        foreach ($datacenters as $datacenter) {
            foreach ($environments as $environment) {
                try {
                    $cert = $this->fetchBankCertificate($datacenter, $environment);
                    $results[] = [
                        'datacenter' => $datacenter,
                        'environment' => $environment,
                        'status' => 'success',
                        'fingerprint' => $this->certManager->certificateFingerprint($cert),
                    ];
                } catch (Throwable $e) {
                    $results[] = [
                        'datacenter' => $datacenter,
                        'environment' => $environment,
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Generate a temporary function ID for certificate fetching.
     *
     * This is used when fetching certificates before onboarding is complete.
     */
    private function generateTemporaryFunctionId(): string
    {
        return 'TEMP_'.str_replace('-', '', sprintf(
            '%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        ));
    }

    /**
     * Get the encryption order for a datacenter.
     *
     * BEC requires RSA-1_5 encryption, while BANKDATA and NBS use RSA-OAEP-MGF1P.
     * This is important for proper XML encryption.
     *
     * @param string $datacenter The datacenter
     * @return string The encryption algorithm constant
     */
    public static function getEncryptionAlgorithm(string $datacenter): string
    {
        $datacenter = strtoupper(trim($datacenter));
        
        if ($datacenter === 'BEC') {
            return BankConnectXmlSecurity::RSA_1_5;
        }
        
        // BANKDATA and NBS use RSA-OAEP-MGF1P
        return BankConnectXmlSecurity::RSA_OAEP_MGF1P;
    }

    /**
     * Get the signing order for a datacenter.
     *
     * The order of operations (sign-then-encrypt vs encrypt-then-sign) differs
     * between datacenters according to BankConnect v3.7 specifications.
     *
     * @param string $datacenter The datacenter
     * @return string 'sign_first' or 'encrypt_first'
     */
    public static function getSecurityOrder(string $datacenter): string
    {
        $datacenter = strtoupper(trim($datacenter));
        
        if ($datacenter === 'BEC') {
            // BEC: encrypt first, then sign
            return 'encrypt_first';
        }
        
        // BANKDATA and NBS: sign first, then encrypt
        return 'sign_first';
    }
}
