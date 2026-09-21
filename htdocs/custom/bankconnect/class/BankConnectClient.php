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
require_once __DIR__.'/BankConnectXmlSecurity.php';
require_once __DIR__.'/BankConnectResponseSecurity.php';

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
    private int $timeoutMs = 180000;
    private ?BankConnectXmlSecurity $xmlSecurity = null;
    private string $datacenter;

    public function __construct(Conf $conf, ?BankConnectLogger $logger = null)
    {
        $this->conf = $conf;
        $this->logger = $logger ?? new BankConnectLogger();
        $this->xmlSecurity = new BankConnectXmlSecurity($conf);

        $g = $conf->global ?? [];
        $this->endpoint = (string) ($g['BANKCONNECT_ENDPOINT']
            ?? 'https://stest.bankconnect.dk/2019/04/04/services/CorporateService');
        $this->datacenter = strtoupper(trim((string) ($g['BANKCONNECT_DATACENTER'] ?? 'BANKDATA')));

        if (isset($g['BANKCONNECT_TIMEOUT_MS'])) {
            $this->timeoutMs = max(30000, (int) $g['BANKCONNECT_TIMEOUT_MS']);
        }
    }

    public function call(string $operation, string $bodyXml, array $context = []): string
    {
        $start = microtime(true);
        [$bodyXml, $soapHeaderXml] = $this->extractServiceHeader($operation, $bodyXml);
        $envelope = $this->buildEnvelope($bodyXml, $soapHeaderXml);

        $envelope = $this->secureEnvelope($operation, $envelope);

        try {
            $response = $this->httpPost($envelope, $operation);
            $responseSecurity = new BankConnectResponseSecurity($this->conf);
            if ($operation === self::OP_GET_BANK_CERTIFICATE) {
                $responseSecurity->validateStructure($response);
            } else {
                $responseSecurity->verify($response, $this->expectedResponseOperation($operation));
            }
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

    protected function buildEnvelope(string $bodyXml, string $soapHeaderXml = ''): string
    {
        $technicalAddress = '<technicalAddress xmlns="http://bankconnect.dk/schema/2014"></technicalAddress>';
        return '<?xml version="1.0" encoding="UTF-8"?>'
             . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
             . '<soapenv:Header>'.$soapHeaderXml.$technicalAddress.'</soapenv:Header>'
             . '<soapenv:Body>'.$bodyXml.'</soapenv:Body>'
             . '</soapenv:Envelope>';
    }

    private function extractServiceHeader(string $operation, string $bodyXml): array
    {
        if ($operation === self::OP_ACTIVATE_SERVICE_AGREEMENT) {
            return [$bodyXml, ''];
        }

        if (preg_match('/<serviceHeader\b[^>]*>.*?<\/serviceHeader>/s', $bodyXml, $m)) {
            $header = $m[0];
            $body = str_replace($header, '', $bodyXml);
            return [$body, $header];
        }

        return [$bodyXml, ''];
    }

    private function mustSign(string $operation): bool
    {
        return !in_array($operation, [
            self::OP_GET_BANK_CERTIFICATE,
            self::OP_ACTIVATE_SERVICE_AGREEMENT,
        ], true);
    }

    protected function secureEnvelope(string $operation, string $envelope): string
    {
        if ($this->xmlSecurity === null && ($operation === self::OP_TRANSFER_PAYMENTS || $this->mustSign($operation))) {
            throw new BankConnectException('XML security is required for signed BankConnect operations');
        }
        if ($operation !== self::OP_TRANSFER_PAYMENTS) {
            return $this->mustSign($operation) ? $this->xmlSecurity->signRequest($envelope) : $envelope;
        }
        if (!in_array($this->datacenter, ['BANKDATA', 'NBS', 'BEC'], true)) {
            throw new BankConnectException('BANKCONNECT_DATACENTER must be BANKDATA, NBS or BEC for TransferPayment');
        }
        if ($this->datacenter === 'BEC') {
            return $this->xmlSecurity->encryptSoapBody($this->xmlSecurity->signRequest($envelope));
        }
        return $this->xmlSecurity->signRequest($this->xmlSecurity->encryptSoapBody($envelope));
    }

    /**
     * Operation-specific SOAPAction values from the v3.7 CorporateService WSDL.
     * transferPayments is the local method/constant name; the WSDL action is singular transferPayment.
     *
     * @return list<string>
     */
    private function expectedResponseOperation(string $operation): string
    {
        $responses = [
            self::OP_GET_BANK_CERTIFICATE => 'getBankCertificateResponse',
            self::OP_ACTIVATE_SERVICE_AGREEMENT => 'activateServiceAgreementResponse',
            self::OP_RENEW_CUSTOMER_CERTIFICATE => 'renewCustomerCertificateResponse',
            self::OP_TRANSFER_PAYMENTS => 'transferPaymentResponse',
            self::OP_GET_STATUS => 'getStatusResponse',
            self::OP_GET_CUSTOMER_STATEMENT => 'getCustomerStatementResponse',
            self::OP_GET_CUSTOMER_ACCOUNT_REPORT => 'getCustomerAccountReportResponse',
            self::OP_GET_DEBIT_CREDIT_NOTIFICATION => 'getDebitCreditNotificationResponse',
            self::OP_GET_ALTERNATE => 'getAlternateResponse',
        ];
        if (!isset($responses[$operation])) {
            throw new BankConnectException('Unsupported BankConnect response operation: '.$operation);
        }
        return $responses[$operation];
    }

    protected function buildHttpHeaders(string $operation = self::OP_TRANSFER_PAYMENTS): array
    {
        $actions = [
            self::OP_GET_BANK_CERTIFICATE          => 'getBankCertificate',
            self::OP_ACTIVATE_SERVICE_AGREEMENT    => 'activateServiceAgreement',
            self::OP_RENEW_CUSTOMER_CERTIFICATE    => 'renewCustomerCertificate',
            self::OP_TRANSFER_PAYMENTS             => 'transferPayment',
            self::OP_GET_STATUS                    => 'getStatus',
            self::OP_GET_CUSTOMER_STATEMENT        => 'getCustomerStatement',
            self::OP_GET_DEBIT_CREDIT_NOTIFICATION => 'getDebitCreditNotification',
            self::OP_GET_CUSTOMER_ACCOUNT_REPORT   => 'getCustomerAccountReport',
            self::OP_GET_ALTERNATE                 => 'getAlternate',
        ];

        if (!isset($actions[$operation])) {
            throw new BankConnectException('Unsupported BankConnect CorporateService operation: '.$operation);
        }

        return [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "urn:CorporateService:'.$actions[$operation].'"',
        ];
    }

    private function httpPost(string $envelope, string $operation): string
    {
        if (!function_exists('curl_init')) {
            throw new BankConnectException('cURL extension is required for BankConnectClient');
        }

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $envelope,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $this->buildHttpHeaders($operation),
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

    public function setXmlSecurity(BankConnectXmlSecurity $xmlSecurity): void
    {
        $this->xmlSecurity = $xmlSecurity;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }
}
