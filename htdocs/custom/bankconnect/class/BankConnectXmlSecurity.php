<?php
/**
 * BankConnectXmlSecurity – encrypt payment payload + sign requests.
 *
 * Encryption (transferPayments content):
 *  - Optional gzip if > 5 MB
 *  - AES-256-CBC of payload
 *  - RSA-OAEP (SHA-1) wrap of AES key with bank certificate public key
 *  - Package: base64( version | iv | wrappedKeyLen | wrappedKey | ciphertext )
 *    BankConnect also accepts pure content encryption variants; this format is
 *    documented in encryptPayload() for interoperability testing. Adjust packing
 *    to match your datacenter's exact envelope when integrating against stest.
 *
 * Signature:
 *  - Prefer robrichards/xmlseclibs for WS-Security XMLDSig on serviceHeader + Body
 *  - Without xmlseclibs, signRequest returns XML unchanged only if no private key
 *    is set; with a key set it throws asking for the library.
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
    public const PACK_VERSION = 1;

    private Conf $conf;
    private ?string $customerPrivateKeyPem = null;
    private ?string $customerCertificatePem = null;
    private ?string $bankCertificatePem = null;

    public function __construct(Conf $conf)
    {
        $this->conf = $conf;
        $g = $conf->global ?? [];

        if (!empty($g['BANKCONNECT_CUSTOMER_PRIVATE_KEY'])) {
            $this->customerPrivateKeyPem = (string) $g['BANKCONNECT_CUSTOMER_PRIVATE_KEY'];
        }
        if (!empty($g['BANKCONNECT_CUSTOMER_CERTIFICATE'])) {
            $this->customerCertificatePem = (string) $g['BANKCONNECT_CUSTOMER_CERTIFICATE'];
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

    public function setCustomerCertificate(string $pem): self
    {
        $this->customerCertificatePem = $pem;
        return $this;
    }

    public function setBankCertificate(string $pem): self
    {
        $this->bankCertificatePem = $pem;
        return $this;
    }

    /**
     * Encrypt pain.001 for the <content> element of paymentMessage.
     *
     * @return array{content:string, compressed:int}  content = base64
     */
    public function encryptPayloadDetailed(string $pain001Xml): array
    {
        $content = $pain001Xml;
        $compressed = 0;

        if (strlen($content) > 5 * 1024 * 1024) {
            $gz = gzencode($content, 6);
            if ($gz === false) {
                throw new BankConnectException('gzip compression failed');
            }
            $content = $gz;
            $compressed = 1;
        }

        if ($this->bankCertificatePem === null || $this->bankCertificatePem === '') {
            return ['content' => base64_encode($content), 'compressed' => $compressed];
        }

        $pubKey = openssl_pkey_get_public($this->bankCertificatePem);
        if ($pubKey === false) {
            throw new BankConnectException('Invalid bank certificate: '.openssl_error_string());
        }

        $aesKey = random_bytes(32);
        $iv     = random_bytes(16);

        $ciphertext = openssl_encrypt(
            $content,
            'aes-256-cbc',
            $aesKey,
            OPENSSL_RAW_DATA,
            $iv
        );
        if ($ciphertext === false) {
            throw new BankConnectException('AES encryption failed: '.openssl_error_string());
        }

        $wrappedKey = '';
        $ok = openssl_public_encrypt(
            $aesKey,
            $wrappedKey,
            $pubKey,
            OPENSSL_PKCS1_OAEP_PADDING
        );
        if (!$ok) {
            throw new BankConnectException('RSA key wrap failed: '.openssl_error_string());
        }

        // Binary package for transport inside base64 content field
        $pack = pack('C', self::PACK_VERSION)
              . $iv
              . pack('n', strlen($wrappedKey))
              . $wrappedKey
              . $ciphertext;

        return ['content' => base64_encode($pack), 'compressed' => $compressed];
    }

    /**
     * Backwards-compatible: returns only base64 content string.
     */
    public function encryptPayload(string $pain001Xml): string
    {
        return $this->encryptPayloadDetailed($pain001Xml)['content'];
    }

    /**
     * Decrypt a package produced by encryptPayloadDetailed (for tests / inbound).
     * Requires customer private key only when decrypting bank→customer messages;
     * for our outbound pack, decryption uses the *bank* private key in tests.
     */
    public function decryptPayload(string $base64Content, string $privateKeyPem): string
    {
        $raw = base64_decode($base64Content, true);
        if ($raw === false || strlen($raw) < 19) {
            // Maybe plaintext stub
            $plain = base64_decode($base64Content, true);
            return $plain !== false ? $plain : $base64Content;
        }

        $version = ord($raw[0]);
        if ($version !== self::PACK_VERSION) {
            // Treat as raw base64 plaintext (stub mode)
            return base64_decode($base64Content, true) ?: '';
        }

        $iv = substr($raw, 1, 16);
        $keyLen = unpack('n', substr($raw, 17, 2))[1];
        $wrappedKey = substr($raw, 19, $keyLen);
        $ciphertext = substr($raw, 19 + $keyLen);

        $priv = openssl_pkey_get_private($privateKeyPem);
        if ($priv === false) {
            throw new BankConnectException('Invalid private key for decrypt');
        }

        $aesKey = '';
        if (!openssl_private_decrypt($wrappedKey, $aesKey, $priv, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new BankConnectException('RSA unwrap failed: '.openssl_error_string());
        }

        $plain = openssl_decrypt($ciphertext, 'aes-256-cbc', $aesKey, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new BankConnectException('AES decrypt failed: '.openssl_error_string());
        }

        // Auto-detect gzip
        if (strlen($plain) >= 2 && $plain[0] === "\x1f" && $plain[1] === "\x8b") {
            $unzipped = gzdecode($plain);
            if ($unzipped !== false) {
                return $unzipped;
            }
        }

        return $plain;
    }

    /**
     * Build transferPayments body with encrypted content.
     */
    public function buildPaymentMessage(string $encryptedBase64, string $endToEndMessageId, int $compressed = 0): string
    {
        return '<transferPayments xmlns="http://bankconnect.dk/schema/2014">'
             . '<paymentMessage id="pm-'.htmlspecialchars($endToEndMessageId, ENT_XML1).'">'
             . '<format>ISO20022</format>'
             . '<mimeType>text/xml</mimeType>'
             . '<compressed>'.(int) $compressed.'</compressed>'
             . '<content>'.$encryptedBase64.'</content>'
             . '</paymentMessage>'
             . '</transferPayments>';
    }

    /**
     * Sign SOAP request. Uses xmlseclibs when present and private key is set.
     */
    public function signRequest(string $xml): string
    {
        if ($this->customerPrivateKeyPem === null || $this->customerPrivateKeyPem === '') {
            return $xml; // stub – no key configured
        }

        if (!class_exists('\RobRichards\XMLSecLibs\XMLSecurityDSig')) {
            throw new BankConnectException(
                'Customer private key is configured but robrichards/xmlseclibs is not installed. '
                .'Run: composer require robrichards/xmlseclibs'
            );
        }

        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;
        if (!$doc->loadXML($xml)) {
            throw new BankConnectException('Cannot load XML for signing');
        }

        $objDSig = new \RobRichards\XMLSecLibs\XMLSecurityDSig();
        $objDSig->setCanonicalMethod(\RobRichards\XMLSecLibs\XMLSecurityDSig::EXC_C14N);

        // Sign serviceHeader and Body if present
        $nodes = [];
        foreach (['serviceHeader', 'Body', 'soapenv:Body'] as $name) {
            $list = $doc->getElementsByTagName($name);
            if ($list->length > 0) {
                $nodes[] = $list->item(0);
            }
        }
        // local-name Body
        if (empty($nodes)) {
            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('s', 'http://schemas.xmlsoap.org/soap/envelope/');
            $body = $xpath->query('//s:Body');
            if ($body && $body->length) {
                $nodes[] = $body->item(0);
            }
        }

        if (empty($nodes)) {
            $objDSig->addReference(
                $doc,
                \RobRichards\XMLSecLibs\XMLSecurityDSig::SHA256,
                ['http://www.w3.org/2000/09/xmldsig#enveloped-signature'],
                ['force_uri' => true]
            );
        } else {
            foreach ($nodes as $node) {
                $objDSig->addReference(
                    $node,
                    \RobRichards\XMLSecLibs\XMLSecurityDSig::SHA256,
                    ['http://www.w3.org/2000/09/xmldsig#enveloped-signature'],
                    ['id_name' => 'Id', 'overwrite' => false]
                );
            }
        }

        $objKey = new \RobRichards\XMLSecLibs\XMLSecurityKey(
            \RobRichards\XMLSecLibs\XMLSecurityKey::RSA_SHA256,
            ['type' => 'private']
        );
        $objKey->loadKey($this->customerPrivateKeyPem, false);

        $objDSig->sign($objKey);

        if ($this->customerCertificatePem) {
            $objDSig->add509Cert($this->customerCertificatePem, true);
        }

        $header = $doc->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Header')->item(0);
        if ($header) {
            $objDSig->appendSignature($header);
        } else {
            $objDSig->appendSignature($doc->documentElement);
        }

        return $doc->saveXML();
    }

    public function isLiveCryptoAvailable(): bool
    {
        return $this->bankCertificatePem !== null
            && $this->bankCertificatePem !== ''
            && $this->customerPrivateKeyPem !== null
            && $this->customerPrivateKeyPem !== '';
    }

    public function isXmlSecLibsAvailable(): bool
    {
        return class_exists('\RobRichards\XMLSecLibs\XMLSecurityDSig');
    }
}
