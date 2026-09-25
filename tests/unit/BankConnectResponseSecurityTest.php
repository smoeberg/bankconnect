<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectException.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectResponseSecurity.php';

final class BankConnectResponseSecurityTest extends TestCase
{
    private function security(): BankConnectResponseSecurity
    {
        $conf = new Conf();
        $conf->global['BANKCONNECT_BANK_CERTIFICATE'] = $this->cert;
        $conf->global['BANKCONNECT_CUSTOMER_PRIVATE_KEY'] = $this->private;
        return new BankConnectResponseSecurity($conf);
    }

    private string $private = '';
    private string $cert = '';

    protected function setUp(): void
    {
        if (!class_exists('DOMDocument')) {
            $this->markTestSkipped('ext-dom is required');
        }
        [$this->private, $this->cert] = $this->certificate();
    }

    private function certificate(): array
    {
        $key = openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $private = '';
        $this->assertTrue(openssl_pkey_export($key, $private));
        $csr = openssl_csr_new(['commonName'=>'bankconnect-test'], $key, ['digest_alg'=>'sha256']);
        $this->assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg'=>'sha256']);
        $this->assertNotFalse($cert);
        $pem = '';
        $this->assertTrue(openssl_x509_export($cert, $pem));
        return [$private, $pem];
    }

    private function response(): string
    {
        $pem = '';
        $x509 = openssl_x509_read($this->cert);
        $this->assertNotFalse($x509);
        $this->assertTrue(openssl_x509_export($x509, $pem));
        preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $pem, $m);
        $certDer = base64_decode(preg_replace('/\\s+/', '', $m[1]), true);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $envelope = $doc->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soapenv:Envelope');
        $doc->appendChild($envelope);
        $header = $doc->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soapenv:Header');
        $envelope->appendChild($header);
        $security = $doc->createElementNS('http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd', 'wsse:Security');
        $header->appendChild($security);
        $token = $doc->createElementNS('http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd', 'wsse:BinarySecurityToken', base64_encode($certDer));
        $token->setAttribute('ValueType', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3');
        $security->appendChild($token);
        $body = $doc->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soapenv:Body');
        $body->setAttributeNS('http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd', 'wsu:Id', 'Id-body');
        $envelope->appendChild($body);
        $operation = $doc->createElementNS('http://bankconnect.dk/schema/2014', 'bc:getStatusResponse');
        $body->appendChild($operation);

        $signature = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Signature');
        $security->insertBefore($signature, $token);
        $signedInfo = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:SignedInfo');
        $signature->appendChild($signedInfo);
        $c14n = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:CanonicalizationMethod');
        $c14n->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
        $signedInfo->appendChild($c14n);
        $method = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:SignatureMethod');
        $method->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
        $signedInfo->appendChild($method);
        $ref = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#','ds:Reference');
        $ref->setAttribute('URI','#Id-body');
        $transforms = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#','ds:Transforms');
        $transform = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#','ds:Transform');
        $transform->setAttribute('Algorithm','http://www.w3.org/2001/10/xml-exc-c14n#');
        $transforms->appendChild($transform);
        $ref->appendChild($transforms);
        $digest = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#','ds:DigestMethod');
        $digest->setAttribute('Algorithm','http://www.w3.org/2001/04/xmlenc#sha256');
        $ref->appendChild($digest);
        $ref->appendChild($doc->createElementNS('http://www.w3.org/2000/09/xmldsig#','ds:DigestValue',base64_encode(hash('sha256',$body->C14N(true,false),true))));
        $signedInfo->appendChild($ref);
        $signatureBytes = '';
        $this->assertTrue(openssl_sign($signedInfo->C14N(true,false), $signatureBytes, $this->private, OPENSSL_ALGO_SHA256));
        $signature->appendChild($doc->createElementNS('http://www.w3.org/2000/09/xmldsig#','ds:SignatureValue',base64_encode($signatureBytes)));
        return $doc->saveXML();
    }

    private function encryptedResponse(string $payloadXml = '', bool $tamper = false): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $doc->loadXML($this->response(), LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('s', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xp->registerNamespace('wsse', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd');
        $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $body = $xp->query('/s:Envelope/s:Body')->item(0);
        $security = $xp->query('/s:Envelope/s:Header/wsse:Security')->item(0);
        $this->assertInstanceOf(DOMElement::class, $body);
        $this->assertInstanceOf(DOMElement::class, $security);

        $payload = $payloadXml !== '' ? $payloadXml : $doc->saveXML($body->firstChild);
        $aesKey = random_bytes(32);
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($payload, 'aes-256-cbc', $aesKey, OPENSSL_RAW_DATA, $iv);
        $this->assertNotFalse($ciphertext);

        $public = openssl_pkey_get_public($this->cert);
        $this->assertNotFalse($public);
        $wrapped = '';
        $this->assertTrue(openssl_public_encrypt($aesKey, $wrapped, $public, OPENSSL_PKCS1_OAEP_PADDING));

        while ($body->firstChild) {
            $body->removeChild($body->firstChild);
        }
        $xenc = 'http://www.w3.org/2001/04/xmlenc#';
        $ds = 'http://www.w3.org/2000/09/xmldsig#';
        $ek = $doc->createElementNS($xenc, 'xenc:EncryptedKey');
        $ek->setAttribute('Id', 'EK-test');
        $method = $doc->createElementNS($xenc, 'xenc:EncryptionMethod');
        $method->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#rsa-oaep-mgf1p');
        $ek->appendChild($method);
        $ki = $doc->createElementNS($ds, 'ds:KeyInfo');
        $str = $doc->createElementNS('http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd', 'wsse:SecurityTokenReference');
        $ref = $doc->createElementNS('http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd', 'wsse:Reference');
        $ref->setAttribute('URI', '#EK-test');
        $str->appendChild($ref);
        $ki->appendChild($str);
        $ek->appendChild($ki);
        $cd = $doc->createElementNS($xenc, 'xenc:CipherData');
        $cd->appendChild($doc->createElementNS($xenc, 'xenc:CipherValue', base64_encode($wrapped)));
        $ek->appendChild($cd);
        $rl = $doc->createElementNS($xenc, 'xenc:ReferenceList');
        $dr = $doc->createElementNS($xenc, 'xenc:DataReference');
        $dr->setAttribute('URI', '#ED-test');
        $rl->appendChild($dr);
        $ek->appendChild($rl);
        $security->insertBefore($ek, $security->firstChild);

        $ed = $doc->createElementNS($xenc, 'xenc:EncryptedData');
        $ed->setAttribute('Id', 'ED-test');
        $ed->setAttribute('Type', $xenc . 'Content');
        $dm = $doc->createElementNS($xenc, 'xenc:EncryptionMethod');
        $dm->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#aes256-cbc');
        $ed->appendChild($dm);
        $dki = $doc->createElementNS($ds, 'ds:KeyInfo');
        $dstr = $doc->createElementNS('http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd', 'wsse:SecurityTokenReference');
        $dref = $doc->createElementNS('http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd', 'wsse:Reference');
        $dref->setAttribute('URI', '#EK-test');
        $dstr->appendChild($dref);
        $dki->appendChild($dstr);
        $ed->appendChild($dki);
        $ecd = $doc->createElementNS($xenc, 'xenc:CipherData');
        $ecd->appendChild($doc->createElementNS($xenc, 'xenc:CipherValue', base64_encode($iv . $ciphertext)));
        $ed->appendChild($ecd);
        $body->appendChild($ed);

        $signature = $xp->query('./ds:Signature', $security)->item(0);
        $this->assertInstanceOf(DOMElement::class, $signature);
        while ($signature->firstChild) {
            $signature->removeChild($signature->firstChild);
        }
        $signedInfo = $doc->createElementNS($ds, 'ds:SignedInfo');
        $cm = $doc->createElementNS($ds, 'ds:CanonicalizationMethod');
        $cm->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
        $signedInfo->appendChild($cm);
        $sm = $doc->createElementNS($ds, 'ds:SignatureMethod');
        $sm->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
        $signedInfo->appendChild($sm);
        $sref = $doc->createElementNS($ds, 'ds:Reference');
        $sref->setAttribute('URI', '#Id-body');
        $transforms = $doc->createElementNS($ds, 'ds:Transforms');
        $transform = $doc->createElementNS($ds, 'ds:Transform');
        $transform->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
        $transforms->appendChild($transform);
        $sref->appendChild($transforms);
        $digest = $doc->createElementNS($ds, 'ds:DigestMethod');
        $digest->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $sref->appendChild($digest);
        $digestBytes = hash('sha256', $body->C14N(true, false), true);
        if ($tamper) {
            $digestBytes = hash('sha256', 'tampered', true);
        }
        $sref->appendChild($doc->createElementNS($ds, 'ds:DigestValue', base64_encode($digestBytes)));
        $signedInfo->appendChild($sref);
        $signature->appendChild($signedInfo);
        $signatureBytes = '';
        $this->assertTrue(openssl_sign($signedInfo->C14N(true, false), $signatureBytes, $this->private, OPENSSL_ALGO_SHA256));
        $signature->appendChild($doc->createElementNS($ds, 'ds:SignatureValue', base64_encode($signatureBytes)));
        return $doc->saveXML();
    }

    public function testValidResponseIsAccepted(): void
    {
        $verified = $this->security()->verify($this->response(),'getStatusResponse');
        $this->assertSame($this->response(), $verified);

        $doc = new DOMDocument();
        $this->assertTrue($doc->loadXML($verified, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA));
        $nodes = $doc->getElementsByTagNameNS('http://bankconnect.dk/schema/2014','getStatusResponse');
        $this->assertSame(1, $nodes->length);
        $this->assertSame('getStatusResponse', $nodes->item(0)->localName);
    }

    public function testSignedPlaintextCannotBypassRequiredResponseEncryption(): void
    {
        $verified = $this->security()->verify($this->response(), 'getStatusResponse');
        $this->expectException(BankConnectException::class);
        $this->expectExceptionMessage('response encryption is required');
        $this->security()->decrypt($verified, 'getStatusResponse');
    }

    public function testPlaintextCanBeAllowedOnlyForAnOperationWithoutResponseEncryption(): void
    {
        $verified = $this->security()->verify($this->response(), 'getStatusResponse');
        $this->assertSame($verified, $this->security()->decrypt($verified, 'getStatusResponse', false));
    }

    public function testVerifiedEncryptedResponseIsDecryptedAndValidated(): void
    {
        $encrypted = $this->encryptedResponse();
        $verified = $this->security()->verify($encrypted);
        $decrypted = $this->security()->decrypt($verified, 'getStatusResponse');
        $doc = new DOMDocument();
        $this->assertTrue($doc->loadXML($decrypted, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA));
        $nodes = $doc->getElementsByTagNameNS('http://bankconnect.dk/schema/2014', 'getStatusResponse');
        $this->assertSame(1, $nodes->length);
        $this->assertSame(0, $doc->getElementsByTagNameNS('http://www.w3.org/2001/04/xmlenc#', 'EncryptedData')->length);
    }

    public function testMalformedDecryptedPayloadFailsClosed(): void
    {
        $encrypted = $this->encryptedResponse('<bc:getStatusResponse xmlns:bc="http://bankconnect.dk/schema/2014"><broken></bc:getStatusResponse>');
        $verified = $this->security()->verify($encrypted);
        $this->expectException(BankConnectException::class);
        $this->security()->decrypt($verified, 'getStatusResponse');
    }

    public function testResponseTamperingFailsBeforeDecryption(): void
    {
        $xml = $this->encryptedResponse(false);
        $this->assertStringContainsString('<xenc:CipherValue>', $xml);
        $start = strrpos($xml, '<xenc:CipherValue>');
        $end = $start === false ? false : strpos($xml, '</xenc:CipherValue>', $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $xml = substr($xml, 0, $start + strlen('<xenc:CipherValue>'))
            .base64_encode(random_bytes(64))
            .substr($xml, $end);
        $this->expectException(BankConnectException::class);
        $this->security()->verify($xml);
    }

    public function testWrongCustomerKeyFailsClosed(): void
    {
        [, $otherCert] = $this->certificate();
        $verified = $this->security()->verify($this->encryptedResponse());
        $conf = new Conf();
        $conf->global['BANKCONNECT_BANK_CERTIFICATE'] = $this->cert;
        $otherKey = openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($otherKey);
        $otherPrivate = '';
        $this->assertTrue(openssl_pkey_export($otherKey, $otherPrivate));
        $conf->global['BANKCONNECT_CUSTOMER_PRIVATE_KEY'] = $otherPrivate;
        $this->expectException(BankConnectException::class);
        (new BankConnectResponseSecurity($conf))->decrypt($verified, 'getStatusResponse');
    }

    public function testTamperedBodyFailsClosed(): void
    {
        $xml=str_replace('getStatusResponse','getStatusResponseX',$this->response());
        $this->expectException(BankConnectException::class);
        $this->security()->verify($xml,'getStatusResponse');
    }

    public function testDoctypeFailsClosed(): void
    {
        $xml='<!DOCTYPE foo [ <!ENTITY xxe "blocked"> ]>'.$this->response();
        $this->expectException(BankConnectException::class);
        $this->security()->verify($xml,'getStatusResponse');
    }

    public function testWrongCertificateFailsClosed(): void
    {
        [, $otherCert] = $this->certificate();
        $this->expectException(BankConnectException::class);
        $conf = new Conf();
        $conf->global['BANKCONNECT_BANK_CERTIFICATE'] = $otherCert;
        (new BankConnectResponseSecurity($conf))->verify($this->response(),'getStatusResponse');
    }

    public function testDuplicateReferenceFailsClosed(): void
    {
        $xml=$this->response();
        $needle='</ds:Reference></ds:SignedInfo>';
        $replacement='</ds:Reference><ds:Reference URI="#Id-body"><ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/></ds:Transforms><ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/><ds:DigestValue>bad</ds:DigestValue></ds:Reference></ds:SignedInfo>';
        $this->assertStringContainsString($needle,$xml);
        $xml=str_replace($needle,$replacement,$xml);
        $this->expectException(BankConnectException::class);
        $this->security()->verify($xml,'getStatusResponse');
    }
}
