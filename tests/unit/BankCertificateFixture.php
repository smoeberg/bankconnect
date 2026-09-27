<?php

/** A self-contained, signed getBankCertificate response for cache regression tests. */
final class BankCertificateFixture
{
    /** @return array{root:string, leaf:string, response:string} */
    public static function create(): array
    {
        $config = tempnam(sys_get_temp_dir(), 'bc-ca-');
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:TRUE\n[v3_leaf]\nbasicConstraints=critical,CA:FALSE\n");
        try {
            $options = ['config' => $config, 'digest_alg' => 'sha256'];
            $rootKey = openssl_pkey_new(['private_key_bits' => 2048]);
            $rootCsr = openssl_csr_new(['commonName' => 'Trusted test CA'], $rootKey, $options);
            $rootCert = openssl_csr_sign($rootCsr, null, $rootKey, 1, $options + ['x509_extensions' => 'v3_ca']);
            $leafKey = openssl_pkey_new(['private_key_bits' => 2048]);
            $leafCsr = openssl_csr_new(['commonName' => 'Bank test leaf'], $leafKey, $options);
            $leafCert = openssl_csr_sign($leafCsr, $rootCert, $rootKey, 1, $options + ['x509_extensions' => 'v3_leaf']);
            openssl_x509_export($rootCert, $root);
            openssl_x509_export($leafCert, $leaf);
        } finally {
            unlink($config);
        }

        preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $leaf, $matches);
        $certificateBase64 = preg_replace('/\s+/', '', $matches[1]);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $soap = 'http://schemas.xmlsoap.org/soap/envelope/';
        $bc = 'http://bankconnect.dk/schema/2014';
        $ds = 'http://www.w3.org/2000/09/xmldsig#';
        $envelope = $doc->createElementNS($soap, 'soap:Envelope');
        $doc->appendChild($envelope);
        $envelope->appendChild($doc->createElementNS($soap, 'soap:Header'));
        $body = $doc->createElementNS($soap, 'soap:Body');
        $envelope->appendChild($body);
        $operation = $doc->createElementNS($bc, 'bc:getBankCertificateResponse');
        $body->appendChild($operation);
        $message = $doc->createElementNS($bc, 'bc:corporateMessage');
        $message->setAttribute('id', 'BankCert');
        $message->appendChild($doc->createElement('content', base64_encode($leaf)));
        $operation->appendChild($message);
        $signature = $doc->createElementNS($ds, 'ds:Signature');
        $operation->appendChild($signature);
        $info = $doc->createElementNS($ds, 'ds:SignedInfo');
        $signature->appendChild($info);
        $canonical = $doc->createElementNS($ds, 'ds:CanonicalizationMethod');
        $canonical->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
        $info->appendChild($canonical);
        $method = $doc->createElementNS($ds, 'ds:SignatureMethod');
        $method->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
        $info->appendChild($method);
        $reference = $doc->createElementNS($ds, 'ds:Reference');
        $reference->setAttribute('URI', '#BankCert');
        $info->appendChild($reference);
        $transforms = $doc->createElementNS($ds, 'ds:Transforms');
        $reference->appendChild($transforms);
        $transform = $doc->createElementNS($ds, 'ds:Transform');
        $transform->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
        $transforms->appendChild($transform);
        $digestMethod = $doc->createElementNS($ds, 'ds:DigestMethod');
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $reference->appendChild($digestMethod);
        $reference->appendChild($doc->createElementNS($ds, 'ds:DigestValue', base64_encode(hash('sha256', $message->C14N(true, false), true))));
        $bytes = '';
        openssl_sign($info->C14N(true, false), $bytes, $leafKey, OPENSSL_ALGO_SHA256);
        $signature->appendChild($doc->createElementNS($ds, 'ds:SignatureValue', base64_encode($bytes)));
        $keyInfo = $doc->createElementNS($ds, 'ds:KeyInfo');
        $signature->appendChild($keyInfo);
        $x509Data = $doc->createElementNS($ds, 'ds:X509Data');
        $keyInfo->appendChild($x509Data);
        $x509Data->appendChild($doc->createElementNS($ds, 'ds:X509Certificate', $certificateBase64));
        return ['root' => $root, 'leaf' => $leaf, 'response' => $doc->saveXML()];
    }
}
