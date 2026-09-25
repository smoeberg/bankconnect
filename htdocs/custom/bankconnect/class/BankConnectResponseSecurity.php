<?php
/**
 * Verifies Bank Connect SOAP responses before business parsing.
 *
 * The configured BANKCONNECT_BANK_CERTIFICATE is pinned by SHA-256
 * certificate fingerprint. Responses fail closed on malformed XML,
 * unsafe XML declarations, missing/ambiguous WS-Security, unsupported
 * algorithms, duplicate IDs, invalid reference digests or bad signatures.
 */
require_once __DIR__.'/BankConnectException.php';

class BankConnectResponseSecurity
{
    public const DEFAULT_MAX_RESPONSE_BYTES = 2 * 1024 * 1024;

    private Conf $conf;
    private int $maxResponseBytes;

    public function __construct(Conf $conf, int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES)
    {
        $this->conf = $conf;
        if ($maxResponseBytes < 1024) {
            throw new BankConnectException('BankConnect response size limit is too small');
        }
        $this->maxResponseBytes = $maxResponseBytes;
    }

    public function validateStructure(string $responseXml): string
    {
        $doc = $this->loadDocument($responseXml);
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('s', 'http://schemas.xmlsoap.org/soap/envelope/');
        if ($xp->query('/s:Envelope')->length !== 1
            || $xp->query('/s:Envelope/s:Header')->length !== 1
            || $xp->query('/s:Envelope/s:Body')->length !== 1) {
            throw new BankConnectException('BankConnect response must contain exactly one SOAP Envelope, Header and Body');
        }
        if ($xp->query('/s:Envelope/s:Body/s:Fault')->length !== 0) {
            throw new BankConnectException('BankConnect returned a SOAP Fault');
        }
        return $responseXml;
    }

    public function verify(string $responseXml, ?string $expectedOperation = null): string
    {
        $doc = $this->loadDocument($responseXml);

        $soapNs = 'http://schemas.xmlsoap.org/soap/envelope/';
        $wsseNs = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
        $wsuNs = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
        $dsNs = 'http://www.w3.org/2000/09/xmldsig#';
        $bcNs = 'http://bankconnect.dk/schema/2014';

        $xp = new DOMXPath($doc);
        $xp->registerNamespace('s', $soapNs);
        $xp->registerNamespace('wsse', $wsseNs);
        $xp->registerNamespace('wsu', $wsuNs);
        $xp->registerNamespace('ds', $dsNs);

        if ($xp->query('/s:Envelope')->length !== 1
            || $xp->query('/s:Envelope/s:Header')->length !== 1
            || $xp->query('/s:Envelope/s:Body')->length !== 1) {
            throw new BankConnectException('BankConnect response must contain exactly one SOAP Envelope, Header and Body');
        }
        $body = $xp->query('/s:Envelope/s:Body')->item(0);
        if ($xp->query('/s:Envelope/s:Body/s:Fault')->length !== 0) {
            throw new BankConnectException('BankConnect returned a SOAP Fault');
        }

        if ($expectedOperation !== null) {
            $children = [];
            foreach ($body->childNodes as $child) {
                if ($child instanceof DOMElement) $children[] = $child;
            }
            if (count($children) !== 1
                || $children[0]->namespaceURI !== $bcNs
                || $children[0]->localName !== $expectedOperation) {
                throw new BankConnectException('Unexpected BankConnect response operation');
            }
        }

        $security = $xp->query('/s:Envelope/s:Header/wsse:Security');
        if ($security->length !== 1) {
            throw new BankConnectException('BankConnect response must contain exactly one WS-Security Security header');
        }
        $security = $security->item(0);

        $signatures = $xp->query('./ds:Signature', $security);
        if ($signatures->length !== 1 || !$signatures->item(0) instanceof DOMElement) {
            throw new BankConnectException('BankConnect response must contain exactly one WS-Security signature');
        }
        $signature = $signatures->item(0);

        $tokens = $xp->query('./wsse:BinarySecurityToken', $security);
        if ($tokens->length !== 1 || !$tokens->item(0) instanceof DOMElement) {
            throw new BankConnectException('BankConnect response must contain exactly one BinarySecurityToken');
        }
        $certificate = $this->decodeCertificate($tokens->item(0)->textContent);
        $this->requireTrustedCertificate($certificate);

        $signedInfo = $xp->query('./ds:SignedInfo', $signature);
        $signatureValue = $xp->query('./ds:SignatureValue', $signature);
        if ($signedInfo->length !== 1 || $signatureValue->length !== 1) {
            throw new BankConnectException('BankConnect response signature is incomplete');
        }

        if ($xp->query('./ds:CanonicalizationMethod', $signedInfo->item(0))->length !== 1
            || $xp->query('./ds:CanonicalizationMethod', $signedInfo->item(0))->item(0)->getAttribute('Algorithm')
                !== 'http://www.w3.org/2001/10/xml-exc-c14n#'
            || $xp->query('./ds:SignatureMethod', $signedInfo->item(0))->length !== 1
            || $xp->query('./ds:SignatureMethod', $signedInfo->item(0))->item(0)->getAttribute('Algorithm')
                !== 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256') {
            throw new BankConnectException('Unsupported BankConnect response signature algorithm');
        }

        $allIds = $xp->query('//*[@wsu:Id or @Id]');
        $idTargets = [];
        foreach ($allIds as $node) {
            $ids = [];
            $wsuId = $node->getAttributeNS($wsuNs, 'Id');
            $plainId = $node->getAttribute('Id');
            if ($wsuId !== '') $ids[] = $wsuId;
            if ($plainId !== '') $ids[] = $plainId;
            foreach ($ids as $id) {
                if (isset($idTargets[$id])) {
                    throw new BankConnectException('Duplicate XML security identifier: '.$id);
                }
                $idTargets[$id] = $node;
            }
        }

        $references = $xp->query('./ds:Reference', $signedInfo->item(0));
        if ($references->length < 1) {
            throw new BankConnectException('BankConnect response signature contains no references');
        }
        $bodyId = $body instanceof DOMElement ? $body->getAttributeNS($wsuNs, 'Id') : '';
        if ($bodyId === '') {
            throw new BankConnectException('BankConnect response SOAP Body must have a WS-Security identifier');
        }

        $seenReferences = [];
        foreach ($references as $reference) {
            if (!$reference instanceof DOMElement) {
                throw new BankConnectException('Invalid BankConnect signature reference');
            }
            $uri = $reference->getAttribute('URI');
            if (!preg_match('/^#[A-Za-z0-9_.:-]+$/', $uri)) {
                throw new BankConnectException('BankConnect signature reference must be a local fragment');
            }
            $id = substr($uri, 1);
            if (isset($seenReferences[$id])) {
                throw new BankConnectException('Duplicate BankConnect signature reference');
            }
            $seenReferences[$id] = true;
            if (!isset($idTargets[$id])) {
                throw new BankConnectException('BankConnect signature reference does not resolve');
            }

            $transforms = $xp->query('./ds:Transforms/ds:Transform', $reference);
            if ($transforms->length !== 1
                || $transforms->item(0)->getAttribute('Algorithm') !== 'http://www.w3.org/2001/10/xml-exc-c14n#') {
                throw new BankConnectException('Unsupported BankConnect reference transform');
            }
            $digestMethod = $xp->query('./ds:DigestMethod', $reference);
            $digestValue = $xp->query('./ds:DigestValue', $reference);
            if ($digestMethod->length !== 1
                || $digestMethod->item(0)->getAttribute('Algorithm') !== 'http://www.w3.org/2001/04/xmlenc#sha256'
                || $digestValue->length !== 1) {
                throw new BankConnectException('Unsupported BankConnect reference digest');
            }

            $canonical = $idTargets[$id]->C14N(true, false);
            if ($canonical === false) {
                throw new BankConnectException('Failed to canonicalize signed BankConnect response data');
            }
            $actualDigest = base64_encode(hash('sha256', $canonical, true));
            if (!hash_equals(trim($digestValue->item(0)->textContent), $actualDigest)) {
                throw new BankConnectException('BankConnect response reference digest verification failed');
            }
        }

        if (!isset($seenReferences[$bodyId])) {
            throw new BankConnectException('BankConnect response SOAP Body is not covered by the XML signature');
        }

        $canonicalSignedInfo = $signedInfo->item(0)->C14N(true, false);
        $signatureBytes = base64_decode(trim($signatureValue->item(0)->textContent), true);
        if ($canonicalSignedInfo === false || $signatureBytes === false || $signatureBytes === '') {
            throw new BankConnectException('Invalid BankConnect response signature encoding');
        }
        if (openssl_verify($canonicalSignedInfo, $signatureBytes, $certificate, OPENSSL_ALGO_SHA256) !== 1) {
            throw new BankConnectException('BankConnect response signature verification failed');
        }

        return $responseXml;
    }

    /**
     * Decrypt an already verified SOAP response when the body uses XML Encryption.
     *
     * The response MUST have passed verify() first. Decryption never establishes
     * trust; it only unwraps the already authenticated encrypted body. For
     * operations requiring response encryption, plaintext must fail closed.
     */
    public function decrypt(string $verifiedXml, ?string $expectedOperation = null, bool $requireEncryption = true): string
    {
        $doc = $this->loadDocument($verifiedXml);
        $soapNs = 'http://schemas.xmlsoap.org/soap/envelope/';
        $xencNs = 'http://www.w3.org/2001/04/xmlenc#';
        $wsseNs = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
        $wsse11Ns = 'http://docs.oasis-open.org/wss/oasis-wss/oasis-wss-soap-message-security-1.1#';
        $dsNs = 'http://www.w3.org/2000/09/xmldsig#';
        $bcNs = 'http://bankconnect.dk/schema/2014';

        $xp = new DOMXPath($doc);
        $xp->registerNamespace('s', $soapNs);
        $xp->registerNamespace('xenc', $xencNs);
        $xp->registerNamespace('wsse', $wsseNs);
        $xp->registerNamespace('ds', $dsNs);

        $body = $xp->query('/s:Envelope/s:Body')->item(0);
        if (!$body instanceof DOMElement) {
            throw new BankConnectException('BankConnect response SOAP Body is required for decryption');
        }

        $children = [];
        foreach ($body->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }
        if (count($children) !== 1) {
            throw new BankConnectException('BankConnect encrypted response must contain exactly one SOAP Body child');
        }

        if ($children[0]->namespaceURI !== $xencNs || $children[0]->localName !== 'EncryptedData') {
            if ($requireEncryption) {
                throw new BankConnectException('BankConnect response encryption is required');
            }
            $this->validateDecryptedOperation($body, $bcNs, $expectedOperation);
            return $verifiedXml;
        }

        $encryptedData = $children[0];
        if ($encryptedData->getAttribute('Type') !== $xencNs . 'Content') {
            throw new BankConnectException('Unsupported BankConnect EncryptedData type');
        }
        $edId = $encryptedData->getAttribute('Id');
        if (!preg_match('/^ED-[A-Za-z0-9]+$/', $edId)) {
            throw new BankConnectException('Invalid BankConnect EncryptedData identifier');
        }

        $dataMethod = $xp->query('./xenc:EncryptionMethod', $encryptedData);
        if ($dataMethod->length !== 1
            || $dataMethod->item(0)->getAttribute('Algorithm') !== 'http://www.w3.org/2001/04/xmlenc#aes256-cbc') {
            throw new BankConnectException('Unsupported BankConnect response data encryption algorithm');
        }
        $cipherValues = $xp->query('./xenc:CipherData/xenc:CipherValue', $encryptedData);
        if ($cipherValues->length !== 1) {
            throw new BankConnectException('BankConnect encrypted response data is incomplete');
        }
        $cipher = base64_decode(preg_replace('/\\s+/', '', trim($cipherValues->item(0)->textContent)), true);
        if ($cipher === false || strlen($cipher) <= 16) {
            throw new BankConnectException('Invalid BankConnect response ciphertext');
        }
        $iv = substr($cipher, 0, 16);
        $ciphertext = substr($cipher, 16);

        $encryptedKeyRefs = $xp->query('./ds:KeyInfo/wsse:SecurityTokenReference/wsse:Reference', $encryptedData);
        if ($encryptedKeyRefs->length !== 1) {
            throw new BankConnectException('BankConnect encrypted response must reference exactly one EncryptedKey');
        }
        $ekRef = $encryptedKeyRefs->item(0)->getAttribute('URI');
        if (!preg_match('/^#[A-Za-z0-9_.:-]+$/', $ekRef)) {
            throw new BankConnectException('Invalid BankConnect EncryptedKey reference');
        }
        $ekId = substr($ekRef, 1);
        $encryptedKeys = $xp->query('/s:Envelope/s:Header/wsse:Security/xenc:EncryptedKey');
        if ($encryptedKeys->length !== 1 || !$encryptedKeys->item(0) instanceof DOMElement) {
            throw new BankConnectException('BankConnect encrypted response must contain exactly one EncryptedKey');
        }
        $encryptedKey = $encryptedKeys->item(0);
        if ($encryptedKey->getAttribute('Id') !== $ekId) {
            throw new BankConnectException('BankConnect EncryptedKey reference does not resolve');
        }

        $keyMethod = $xp->query('./xenc:EncryptionMethod', $encryptedKey);
        if ($keyMethod->length !== 1
            || $keyMethod->item(0)->getAttribute('Algorithm') !== 'http://www.w3.org/2001/04/xmlenc#rsa-oaep-mgf1p') {
            throw new BankConnectException('Unsupported BankConnect response key encryption algorithm');
        }
        $dataRefs = $xp->query('./xenc:ReferenceList/xenc:DataReference', $encryptedKey);
        if ($dataRefs->length !== 1 || $dataRefs->item(0)->getAttribute('URI') !== '#'.$edId) {
            throw new BankConnectException('BankConnect EncryptedKey DataReference does not resolve');
        }
        $keyValues = $xp->query('./xenc:CipherData/xenc:CipherValue', $encryptedKey);
        if ($keyValues->length !== 1) {
            throw new BankConnectException('BankConnect encrypted response key is incomplete');
        }
        $wrappedKey = base64_decode(preg_replace('/\\s+/', '', trim($keyValues->item(0)->textContent)), true);
        if ($wrappedKey === false || $wrappedKey === '') {
            throw new BankConnectException('Invalid BankConnect wrapped response key');
        }

        $privateKey = $this->configuredCustomerPrivateKey();
        $aesKey = '';
        if (!openssl_private_decrypt($wrappedKey, $aesKey, $privateKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new BankConnectException('BankConnect response key decryption failed');
        }
        if (strlen($aesKey) !== 32) {
            throw new BankConnectException('BankConnect response AES key has invalid length');
        }

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $aesKey, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false || $plaintext === '') {
            throw new BankConnectException('BankConnect response AES decryption failed');
        }

        $plainDoc = $this->loadDocument('<root>'.$plaintext.'</root>');
        $plainRoot = $plainDoc->documentElement;
        $decryptedChildren = [];
        foreach ($plainRoot->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $decryptedChildren[] = $child;
            }
        }
        if (count($decryptedChildren) !== 1) {
            throw new BankConnectException('Decrypted BankConnect response must contain exactly one SOAP Body payload');
        }

        $decryptedPayload = $decryptedChildren[0];
        if ($decryptedPayload->namespaceURI !== $bcNs) {
            throw new BankConnectException('Decrypted BankConnect response payload has an unexpected namespace');
        }
        while ($body->firstChild) {
            $body->removeChild($body->firstChild);
        }
        $body->appendChild($doc->importNode($decryptedPayload, true));

        $this->validateDecryptedOperation($body, $bcNs, $expectedOperation);
        $result = $doc->saveXML();
        if ($result === false || $result === '') {
            throw new BankConnectException('Failed to serialize decrypted BankConnect response');
        }
        return $result;
    }

    private function validateDecryptedOperation(DOMElement $body, string $bcNs, ?string $expectedOperation): void
    {
        if ($expectedOperation === null) {
            return;
        }
        $children = [];
        foreach ($body->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }
        if (count($children) !== 1
            || $children[0]->namespaceURI !== $bcNs
            || $children[0]->localName !== $expectedOperation) {
            throw new BankConnectException('Unexpected BankConnect response operation after decryption');
        }
    }

    private function configuredCustomerPrivateKey(): string
    {
        $key = $this->conf->global['BANKCONNECT_CUSTOMER_PRIVATE_KEY'] ?? null;
        if (!is_string($key) || trim($key) === '') {
            throw new BankConnectException('Customer private key is not configured for BankConnect response decryption');
        }
        if (openssl_pkey_get_private($key) === false) {
            throw new BankConnectException('Invalid customer private key for BankConnect response decryption');
        }
        return $key;
    }

    private function loadDocument(string $responseXml): DOMDocument
    {
        if ($responseXml === '') {
            throw new BankConnectException('BankConnect response is empty');
        }
        if (strlen($responseXml) > $this->maxResponseBytes) {
            throw new BankConnectException('BankConnect response exceeds configured size limit');
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $responseXml)) {
            throw new BankConnectException('DTD and entity declarations are not permitted in BankConnect responses');
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$doc->loadXML($responseXml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA)) {
                throw new BankConnectException('Invalid BankConnect response XML');
            }
        } finally {
            libxml_use_internal_errors($previous);
            libxml_clear_errors();
        }
        return $doc;
    }

    private function decodeCertificate(string $base64): string
    {
        $der = base64_decode(preg_replace('/\s+/', '', trim($base64)), true);
        if ($der === false || $der === '') {
            throw new BankConnectException('Invalid BankConnect response certificate encoding');
        }
        $pem = "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE-----\n";
        if (openssl_x509_read($pem) === false) {
            throw new BankConnectException('Invalid BankConnect response X.509 certificate');
        }
        return $pem;
    }

    private function requireTrustedCertificate(string $certificate): void
    {
        $configured = $this->conf->global['BANKCONNECT_BANK_CERTIFICATE'] ?? null;
        if (!is_string($configured) || trim($configured) === '') {
            throw new BankConnectException('Trusted BankConnect bank certificate is not configured');
        }
        $actual = openssl_x509_fingerprint($certificate, 'sha256', true);
        $trusted = openssl_x509_fingerprint($configured, 'sha256', true);
        if ($actual === false || $trusted === false || !hash_equals($trusted, $actual)) {
            throw new BankConnectException('BankConnect response certificate is not trusted');
        }
    }
}
