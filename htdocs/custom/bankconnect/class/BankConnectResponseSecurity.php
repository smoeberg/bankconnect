<?php
/**
 * Verifies Bank Connect SOAP responses before any business parsing.
 *
 * Security boundary:
 *   1. reject oversized / DTD / entity-bearing XML;
 *   2. validate SOAP envelope/body structure;
 *   3. locate exactly one WS-Security signature and X.509 token;
 *   4. validate every signed reference and digest;
 *   5. verify SignedInfo with the trusted configured bank certificate;
 *   6. return the original response only after all checks succeed.
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
        $this->maxResponseBytes = $maxResponseBytes;
        if ($maxResponseBytes < 1024) {
            throw new BankConnectException('BankConnect response size limit is too small');
        }
    }

    public function verify(string $responseXml): string
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
            if (!$doc->loadXML($responseXml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                throw new BankConnectException('Invalid BankConnect response XML');
            }
        } finally {
            libxml_use_internal_errors($previous);
            libxml_clear_errors();
        }

        $soapNs = 'http://schemas.xmlsoap.org/soap/envelope/';
        $wsseNs = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
        $wsuNs = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
        $dsNs = 'http://www.w3.org/2000/09/xmldsig#';

        $xp = new DOMXPath($doc);
        $xp->registerNamespace('s', $soapNs);
        $xp->registerNamespace('wsse', $wsseNs);
        $xp->registerNamespace('wsu', $wsuNs);
        $xp->registerNamespace('ds', $dsNs);

        $envelopes = $xp->query('/s:Envelope');
        $bodies = $xp->query('/s:Envelope/s:Body');
        if ($envelopes->length !== 1 || $bodies->length !== 1) {
            throw new BankConnectException('BankConnect response must contain exactly one SOAP Envelope and Body');
        }

        $security = $xp->query('/s:Envelope/s:Header/wsse:Security');
        if ($security->length !== 1 || !$security->item(0) instanceof DOMElement) {
            throw new BankConnectException('Verified BankConnect response requires exactly one WS-Security Security header');
        }

        $signatures = $xp->query('./ds:Signature', $security->item(0));
        if ($signatures->length !== 1 || !$signatures->item(0) instanceof DOMElement) {
            throw new BankConnectException('Verified BankConnect response requires exactly one WS-Security signature');
        }
        $signature = $signatures->item(0);

        $certNodes = $xp->query('./wsse:BinarySecurityToken', $security->item(0));
        if ($certNodes->length !== 1 || !$certNodes->item(0) instanceof DOMElement) {
            throw new BankConnectException('BankConnect response signature certificate is missing');
        }
        $certificate = $this->decodeCertificate((string) $certNodes->item(0)->textContent);
        $this->requireTrustedCertificate($certificate);

        $signedInfo = $xp->query('./ds:SignedInfo', $signature);
        $signatureValue = $xp->query('./ds:SignatureValue', $signature);
        if ($signedInfo->length !== 1 || $signatureValue->length !== 1) {
            throw new BankConnectException('BankConnect response signature is incomplete');
        }

        $canonicalization = $xp->query('./ds:CanonicalizationMethod', $signedInfo->item(0));
        $signatureMethod = $xp->query('./ds:SignatureMethod', $signedInfo->item(0));
        if ($canonicalization->length !== 1
            || $canonicalization->item(0)->getAttribute('Algorithm') !== 'http://www.w3.org/2001/10/xml-exc-c14n#'
            || $signatureMethod->length !== 1
            || $signatureMethod->item(0)->getAttribute('Algorithm') !== 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256') {
            throw new BankConnectException('Unsupported BankConnect response signature algorithm');
        }

        $references = $xp->query('./ds:Reference', $signedInfo->item(0));
        if ($references->length < 1) {
            throw new BankConnectException('BankConnect response signature contains no references');
        }

        $seenIds = [];
        $allIds = $xp->query('//*[@wsu:Id or @Id]');
        foreach ($allIds as $node) {
            $ids = [];
            $wsuId = $node->getAttributeNS($wsuNs, 'Id');
            $plainId = $node->getAttribute('Id');
            if ($wsuId !== '') $ids[] = $wsuId;
            if ($plainId !== '') $ids[] = $plainId;
            foreach ($ids as $id) {
                if (isset($seenIds[$id])) {
                    throw new BankConnectException('Duplicate XML security identifier: '.$id);
                }
                $seenIds[$id] = true;
            }
        }

        foreach ($references as $reference) {
            if (!$reference instanceof DOMElement) {
                throw new BankConnectException('Invalid BankConnect signature reference');
            }
            $uri = $reference->getAttribute('URI');
            if (!preg_match('/^#[A-Za-z0-9_.:-]+$/', $uri)) {
                throw new BankConnectException('BankConnect signature reference must be a local fragment');
            }
            $id = substr($uri, 1);
            $targets = [];
            foreach ($allIds as $candidate) {
                if ($candidate->getAttributeNS($wsuNs, 'Id') === $id || $candidate->getAttribute('Id') === $id) {
                    $targets[] = $candidate;
                }
            }
            if (count($targets) !== 1) {
                throw new BankConnectException('BankConnect signature reference does not resolve uniquely');
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

            $canonicalTarget = $targets[0]->C14N(true, false);
            if ($canonicalTarget === false) {
                throw new BankConnectException('Failed to canonicalize BankConnect signed response reference');
            }
            $actualDigest = base64_encode(hash('sha256', $canonicalTarget, true));
            if (!hash_equals(trim($digestValue->item(0)->textContent), $actualDigest)) {
                throw new BankConnectException('BankConnect response reference digest verification failed');
            }
        }

        $canonicalSignedInfo = $signedInfo->item(0)->C14N(true, false);
        if ($canonicalSignedInfo === false) {
            throw new BankConnectException('Failed to canonicalize BankConnect SignedInfo');
        }
        $signatureBytes = base64_decode(trim($signatureValue->item(0)->textContent), true);
        if ($signatureBytes === false || $signatureBytes === '') {
            throw new BankConnectException('Invalid BankConnect SignatureValue');
        }
        if (openssl_verify($canonicalSignedInfo, $signatureBytes, $certificate, OPENSSL_ALGO_SHA256) !== 1) {
            throw new BankConnectException('BankConnect response signature verification failed');
        }

        return $responseXml;
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
}    public function validateStructure(string $xml): DOMDocument
    {
        if (strlen($xml) > self::MAX_RESPONSE_BYTES) {
            throw new BankConnectException('BankConnect response exceeds the maximum allowed size');
        }
        if ($xml === '') {
            throw new BankConnectException('BankConnect response is empty');
        }
        $doc = $this->loadXml($xml);
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('s', self::SOAP_NS);
        $envelope = $xp->query('/s:Envelope')->item(0);
        $header = $xp->query('/s:Envelope/s:Header')->item(0);
        $body = $xp->query('/s:Envelope/s:Body')->item(0);
        if (!$envelope instanceof DOMElement || !$header instanceof DOMElement || !$body instanceof DOMElement) {
            throw new BankConnectException('BankConnect response must contain SOAP Envelope, Header and Body');
        }
        if ($xp->query('/s:Envelope/s:Body/s:Fault')->length > 0) {
            throw new BankConnectException('BankConnect returned a SOAP Fault');
        }
        return $doc;
    }

    public function validateAndVerify(string $xml, ?string $expectedOperation = null): DOMDocument
    {
        $doc = $this->validateStructure($xml);
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('s', self::SOAP_NS);
        $xp->registerNamespace('wsse', self::WSSE_NS);
        $xp->registerNamespace('wsu', self::WSU_NS);
        $xp->registerNamespace('ds', self::DS_NS);
        $body = $xp->query('/s:Envelope/s:Body')->item(0);
        if (!$body instanceof DOMElement) {
            throw new BankConnectException('BankConnect response SOAP Body is required');
        }

        $securityNodes = $xp->query('/s:Envelope/s:Header/wsse:Security');
        if ($securityNodes->length !== 1) {
            throw new BankConnectException('BankConnect response must contain exactly one WS-Security Security header');
        }
        $security = $securityNodes->item(0);
        $signatures = $xp->query('./ds:Signature', $security);
        if ($signatures->length !== 1) {
            throw new BankConnectException('BankConnect response must contain exactly one WS-Security signature');
        }
        $signature = $signatures->item(0);
        $tokens = $xp->query('./wsse:BinarySecurityToken', $security);
        if ($tokens->length !== 1) {
            throw new BankConnectException('BankConnect response must contain exactly one BinarySecurityToken');
        }
        $token = $tokens->item(0);
        $certificate = $this->certificateFromToken($token);
        if ($this->trustedCertificatePem !== null &&
            $this->fingerprint($certificate) !== $this->fingerprint($this->trustedCertificatePem)) {
            throw new BankConnectException('BankConnect response certificate does not match the configured trusted bank certificate');
        }
        $this->validateCertificateTime($certificate);
        $this->verifySignature($doc, $xp, $signature, $certificate);
        if ($expectedOperation !== null) {
            $this->validateOperation($body, $expectedOperation);
        }
        return $doc;
    }


