<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectException.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectResponseSecurity.php';

final class BankConnectResponseSecurityTest extends TestCase
{
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

    public function testValidResponseIsAccepted(): void
    {
        $doc=(new BankConnectResponseSecurity($this->cert))->validateAndVerify($this->response(),'getStatusResponse');
        $this->assertSame('getStatusResponse',$doc->getElementsByTagNameNS('http://bankconnect.dk/schema/2014','getStatusResponse')->item(0)->localName);
    }

    public function testTamperedBodyFailsClosed(): void
    {
        $xml=str_replace('getStatusResponse','getStatusResponseX',$this->response());
        $this->expectException(BankConnectException::class);
        (new BankConnectResponseSecurity($this->cert))->validateAndVerify($xml,'getStatusResponse');
    }

    public function testDoctypeFailsClosed(): void
    {
        $xml='<!DOCTYPE foo [ <!ENTITY xxe "blocked"> ]>'.$this->response();
        $this->expectException(BankConnectException::class);
        (new BankConnectResponseSecurity($this->cert))->validateAndVerify($xml,'getStatusResponse');
    }

    public function testWrongCertificateFailsClosed(): void
    {
        [, $otherCert] = $this->certificate();
        $this->expectException(BankConnectException::class);
        (new BankConnectResponseSecurity($otherCert))->validateAndVerify($this->response(),'getStatusResponse');
    }

    public function testDuplicateReferenceFailsClosed(): void
    {
        $xml=$this->response();
        $needle='</ds:Reference></ds:SignedInfo>';
        $replacement='</ds:Reference><ds:Reference URI="#Id-body"><ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/></ds:Transforms><ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/><ds:DigestValue>bad</ds:DigestValue></ds:Reference></ds:SignedInfo>';
        $this->assertStringContainsString($needle,$xml);
        $xml=str_replace($needle,$replacement,$xml);
        $this->expectException(BankConnectException::class);
        (new BankConnectResponseSecurity($this->cert))->validateAndVerify($xml,'getStatusResponse');
    }
}
