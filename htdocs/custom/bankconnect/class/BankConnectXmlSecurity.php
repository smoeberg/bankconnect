<?php
/**
 * BankConnectXmlSecurity – XML-Signature + XML-Encryption helpers.
 *
 * Implements the security layer required by BankConnect:
 *  - Sign ServiceHeader + body with customer private key (XMLDSig)
 *  - Encrypt pain.001 payload with bank certificate (XML-Encryption)
 *
 * Production path should use robrichards/xmlseclibs.
 * This class provides the interface + a safe stub so the rest of the
 * pipeline can be tested without the library present.
 */

require_once __DIR__.'/BankConnectException.php';

if (!class_exists('Conf')) {
    class Conf
    {
        /** @var array<string,mixed> */
        public $global = [];
    }
}

class BankConnectXmlSecurity
{
    private Conf $conf;
    private ?string $customerPrivateKeyPem = null;
    private ?string $bankCertificatePem = null;

    public function __construct(Conf $conf)
    {
        $this->conf = $conf;
        $g = $conf->global ?? [];

        // In production these come from BankConnectCertificateManager / DB
        if (!empty($g['BANKCONNECT_CUSTOMER_PRIVATE_KEY'])) {
            $this->customerPrivateKeyPem = (string) $g['BANKCONNECT_CUSTOMER_PRIVATE_KEY'];
        }
        if (!empty($g['BANKCONNECT_BANK_CERTIFICATE'])) {
            $this->bankCertificatePem = (string) $g['BANKCONNECT_BANK_CERTIFICATE'];
        }
    }

    public function setCustomerPrivateKey(string $pem): self
    {
        $this->customerPrivateKeyPem = $pem;
        return $this;
    }

    public function setBankCertificate(string $pem): self
    {
        $this->bankCertificatePem = $pem;
        return $this;
    }

    /**
     * Encrypt pain.001 XML for transferPayments payload.
     * Returns base64-encoded ciphertext (or gzip+encrypt when > 5 MB).
     *
     * Stub: returns base64 of the plaintext when no bank cert is configured,
     * so unit tests can still exercise the pipeline.
     */
    public function encryptPayload(string $pain001Xml): string
    {
        $content = $pain001Xml;
        $compressed = 0;

        // BankConnect rule: content > 5 MB must be gzip-compressed
        if (strlen($content) > 5 * 1024 * 1024) {
            $gz = gzencode($content, 6);
            if ($gz === false) {
                throw new BankConnectException('gzip compression failed');
            }
            $content = $gz;
            $compressed = 1;
        }

        if ($this->bankCertificatePem === null || $this->bankCertificatePem === '') {
            // Stub mode – no real encryption
            return base64_encode($content);
        }

        // Real path: XML-Encryption with bank cert (RSA-OAEP + AES)
        // Requires robrichards/xmlseclibs or equivalent.
        if (!class_exists('\RobRichards\XMLSecLibs\XMLSecurityKey')) {
            throw new BankConnectException(
                'XML encryption requires robrichards/xmlseclibs. '
                .'Install via composer or configure stub mode.'
            );
        }

        // Placeholder for full implementation against BankConnect package examples
        // resources/xml/security/encrypted-transferpayments.xml
        throw new BankConnectException(
            'Full XML-Encryption not yet implemented – use developer package examples'
        );
    }

    /**
     * Build the <paymentMessage> element expected by transferPayments.
     */
    public function buildPaymentMessage(string $encryptedBase64, string $endToEndMessageId): string
    {
        return '<transferPayments xmlns="http://bankconnect.dk/schema/2014">'
             . '<paymentMessage id="pm-'.htmlspecialchars($endToEndMessageId, ENT_XML1).'">'
             . '<format>ISO20022</format>'
             . '<mimeType>text/xml</mimeType>'
             . '<compressed>0</compressed>'
             . '<content>'.$encryptedBase64.'</content>'
             . '</paymentMessage>'
             . '</transferPayments>';
    }

    /**
     * Sign the request XML with the customer private key (XMLDSig enveloped).
     *
     * Stub: returns the XML unchanged when no private key is configured.
     */
    public function signRequest(string $xml): string
    {
        if ($this->customerPrivateKeyPem === null || $this->customerPrivateKeyPem === '') {
            return $xml; // stub
        }

        if (!class_exists('\RobRichards\XMLSecLibs\XMLSecurityDSig')) {
            throw new BankConnectException(
                'XML signature requires robrichards/xmlseclibs'
            );
        }

        // Placeholder – full implementation per
        // docs/Step by step guides/Signature and get operations.docx
        throw new BankConnectException(
            'Full XML-Signature not yet implemented – use developer package examples'
        );
    }

    /**
     * Whether real crypto is available (certs + library).
     */
    public function isLiveCryptoAvailable(): bool
    {
        return $this->customerPrivateKeyPem !== null
            && $this->customerPrivateKeyPem !== ''
            && $this->bankCertificatePem !== null
            && $this->bankCertificatePem !== ''
            && class_exists('\RobRichards\XMLSecLibs\XMLSecurityDSig');
    }
}
