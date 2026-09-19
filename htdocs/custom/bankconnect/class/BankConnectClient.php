<?php
/**
 * BankConnectClient – SOAP client for Danish BankConnect (datacenter hub).
 *
 * Handles:
 *  - ServiceHeader + TechnicalAddress
 *  - XML-Signature (customer private key)
 *  - XML-Encryption of payment payload (bank certificate)
 *  - All CorporateService operations
 *
 * Endpoint (test): https://stest.bankconnect.dk/2019/04/04/services/CorporateService
 * Endpoint (prod): https://www.bankconnectservices.dk/2019/04/04/services/CorporateService
 *
 * Never throws raw SOAP faults to callers – always wraps in BankConnectException.
 */

require_once __DIR__.'/BankConnectException.php';
require_once __DIR__.'/BankConnectLogger.php';

if (!class_exists('Conf')) {
    class Conf
    {
        /** @var array<string,mixed> */
        public $global = [];
    }
}

class BankConnectClient
{
    public const OP_GET_BANK_CERTIFICATE        = 'getBankCertificate';
    public const OP_ACTIVATE_SERVICE_AGREEMENT  = 'activateServiceAgreement';
    public const OP_RENEW_CUSTOMER_CERTIFICATE  = 'renewCustomerCertificate';
    public const OP_TRANSFER_PAYMENTS           = 'transferPayments';
    public const OP_GET_STATUS                  = 'getStatus';
    public const OP_GET_CUSTOMER_STATEMENT      = 'getCustomerStatement';
    public const OP_GET_CUSTOMER_ACCOUNT_REPORT = 'getCustomerAccountReport';
    public const OP_GET_DEBIT_CREDIT_NOTIFICATION = 'getDebitCreditNotification';
    public const OP_GET_ALTERNATE               = 'getAlternate';

    private Conf $conf;
    private BankConnectLogger $logger;
    private string $endpoint;
    private int $timeoutMs = 180000; // BankConnect server-side timeout is 3 min

    public function __construct(Conf $conf, ?BankConnectLogger $logger = null)
    {
        $this->conf = $conf;
        $this->logger = $logger ?? new BankConnectLogger();

        $g = $conf->global ?? [];
        $this->endpoint = (string) ($g['BANKCONNECT_ENDPOINT']
            ?? 'https://stest.bankconnect.dk/2019/04/04/services/CorporateService');

        if (isset($g['BANKCONNECT_TIMEOUT_MS'])) {
            $this->timeoutMs = max(30000, (int) $g['BANKCONNECT_TIMEOUT_MS']);
        }
    }

    /**
     * Low-level call. All public operation methods delegate here.
     *
     * @param string $operation  One of the OP_* constants
     * @param string $bodyXml    Inner XML (already containing the operation element)
     * @param array  $context    Optional context for logging (endToEndMessageId etc.)
     * @return string            Raw SOAP response body
     * @throws BankConnectException
     */
    public function call(string $operation, string $bodyXml, array $context = []): string
    {
        $start = microtime(true);
        $envelope = $this->buildEnvelope($bodyXml);

        try {
            $response = $this->httpPost($envelope);
            $duration = (int) ((microtime(true) - $start) * 1000);

            $this->logger->info('soap_call', [
                'operation' => $operation,
                'duration_ms' => $duration,
                'end_to_end' => $context['endToEndMessageId'] ?? null,
            ]);

            return $response;
        } catch (Throwable $e) {
            $duration = (int) ((microtime(true) - $start) * 1000);
            $this->logger->error('soap_call_failed', [
                'operation' => $operation,
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
            ]);
            throw new BankConnectException('BankConnect call failed: '.$e->getMessage(), 0, $e);
        }
    }

    // ------------------------------------------------------------------
    // Public operations (thin wrappers)
    // ------------------------------------------------------------------

    public function getBankCertificate(string $serviceHeaderXml): string
    {
        $body = '<getBankCertificate xmlns="http://bankconnect.dk/schema/2014">'
              . $serviceHeaderXml
              . '</getBankCertificate>';
        return $this->call(self::OP_GET_BANK_CERTIFICATE, $body);
    }

    public function activateServiceAgreement(string $payloadXml): string
    {
        return $this->call(self::OP_ACTIVATE_SERVICE_AGREEMENT, $payloadXml);
    }

    public function renewCustomerCertificate(string $payloadXml): string
    {
        return $this->call(self::OP_RENEW_CUSTOMER_CERTIFICATE, $payloadXml);
    }

    /**
     * @param string $paymentMessageXml  Already encrypted + base64 content inside paymentMessage
     */
    public function transferPayments(string $paymentMessageXml, string $endToEndMessageId): string
    {
        return $this->call(
            self::OP_TRANSFER_PAYMENTS,
            $paymentMessageXml,
            ['endToEndMessageId' => $endToEndMessageId]
        );
    }

    public function getStatus(string $serviceHeaderXml): string
    {
        $body = '<getStatus xmlns="http://bankconnect.dk/schema/2014">'
              . $serviceHeaderXml
              . '</getStatus>';
        return $this->call(self::OP_GET_STATUS, $body);
    }

    public function getCustomerStatement(string $serviceHeaderXml): string
    {
        $body = '<getCustomerStatement xmlns="http://bankconnect.dk/schema/2014">'
              . $serviceHeaderXml
              . '</getCustomerStatement>';
        return $this->call(self::OP_GET_CUSTOMER_STATEMENT, $body);
    }

    public function getCustomerAccountReport(string $serviceHeaderXml): string
    {
        $body = '<getCustomerAccountReport xmlns="http://bankconnect.dk/schema/2014">'
              . $serviceHeaderXml
              . '</getCustomerAccountReport>';
        return $this->call(self::OP_GET_CUSTOMER_ACCOUNT_REPORT, $body);
    }

    public function getDebitCreditNotification(string $serviceHeaderXml): string
    {
        $body = '<getDebitCreditNotification xmlns="http://bankconnect.dk/schema/2014">'
              . $serviceHeaderXml
              . '</getDebitCreditNotification>';
        return $this->call(self::OP_GET_DEBIT_CREDIT_NOTIFICATION, $body);
    }

    public function getAlternate(string $serviceHeaderXml): string
    {
        $body = '<getAlternate xmlns="http://bankconnect.dk/schema/2014">'
              . $serviceHeaderXml
              . '</getAlternate>';
        return $this->call(self::OP_GET_ALTERNATE, $body);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function buildEnvelope(string $bodyXml): string
    {
        // Minimal SOAP 1.1 envelope. Signature + encryption are added by
        // CertificateManager / higher layers before calling transferPayments.
        return '<?xml version="1.0" encoding="UTF-8"?>'
             . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
             . '<soapenv:Header/>'
             . '<soapenv:Body>'
             . $bodyXml
             . '</soapenv:Body>'
             . '</soapenv:Envelope>';
    }

    private function httpPost(string $envelope): string
    {
        if (!function_exists('curl_init')) {
            throw new BankConnectException('cURL extension is required for BankConnectClient');
        }

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $envelope,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ""',
            ],
            CURLOPT_TIMEOUT_MS     => $this->timeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new BankConnectException("cURL error {$errno}: {$error}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new BankConnectException("HTTP {$httpCode} from BankConnect endpoint");
        }
        if ($response === false || $response === '') {
            throw new BankConnectException('Empty response from BankConnect');
        }

        return $response;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }
}
