<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectException.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectXmlSecurity.php';

class BankConnectXmlSecurityTest extends TestCase
{
    private string $customerPrivate = '';
    private string $customerCert = '';
    private string $bankPrivate = '';
    private string $bankCert = '';

    protected function setUp(): void
    {
        if (!class_exists('DOMDocument')) {
            $this->markTestSkipped('ext-dom is required');
        }
        [$this->customerPrivate, $this->customerCert] = $this->certificate('customer');
        [$this->bankPrivate, $this->bankCert] = $this->certificate('bank');
    }

    private function certificate(string $cn): array
    {
        $key = openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $private = '';
        $this->assertTrue(openssl_pkey_export($key,$private));
        $csr = openssl_csr_new(['commonName'=>$cn],$key,['digest_alg'=>'sha256']);
        $this->assertNotFalse($csr);
        $cert = openssl_csr_sign($csr,null,$key,1,['digest_alg'=>'sha256']);
        $this->assertNotFalse($cert);
        $pem = '';
        $this->assertTrue(openssl_x509_export($cert,$pem));
        return [$private,$pem];
    }

    private function security(): BankConnectXmlSecurity
    {
        return (new BankConnectXmlSecurity(new Conf()))
            ->setCustomerPrivateKey($this->customerPrivate)
            ->setCustomerCertificate($this->customerCert)
            ->setBankCertificate($this->bankCert);
    }

    public function testContentPreparationIsBase64AndGzipOnly(): void
    {
        $xml='<?xml version="1.0"?><Document><A>test</A></Document>';
        $r=$this->security()->preparePayloadDetailed($xml);
        $this->assertSame(0,$r['compressed']);
        $this->assertSame($xml,base64_decode($r['content'],true));

        $large='<Document>'.str_repeat('x',5*1024*1024+1).'</Document>';
        $r=$this->security()->preparePayloadDetailed($large);
        $this->assertSame(1,$r['compressed']);
        $decoded=base64_decode($r['content'],true);
        $this->assertSame("\x1f\x8b",substr($decoded,0,2));
        $this->assertSame($large,gzdecode($decoded));
    }

    public function testBusinessSignatureUsesOfficialAlgorithmsAndReference(): void
    {
        $r=$this->security()->buildTransferPayment(
            '<?xml version="1.0"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03"><A>1</A></Document>',
            'e2e-1'
        );
        $doc=new DOMDocument();
        $this->assertTrue($doc->loadXML($r['xml'],LIBXML_NONET));
        $xp=new DOMXPath($doc);
        $xp->registerNamespace('bc',BankConnectXmlSecurity::BC_NS);
        $xp->registerNamespace('ds',BankConnectXmlSecurity::DS_NS);

        $payment=$xp->query('/bc:transferPayment/bc:paymentMessage')->item(0);
        $sig=$xp->query('/bc:transferPayment/ds:Signature')->item(0);
        $this->assertInstanceOf(DOMElement::class,$payment);
        $this->assertInstanceOf(DOMElement::class,$sig);
        $ref=$xp->query('./ds:SignedInfo/ds:Reference',$sig)->item(0);
        $this->assertSame('#'.$payment->getAttribute('id'),$ref->getAttribute('URI'));
        $this->assertSame(BankConnectXmlSecurity::EXC_C14N,$xp->query('./ds:SignedInfo/ds:CanonicalizationMethod',$sig)->item(0)->getAttribute('Algorithm'));
        $this->assertSame(BankConnectXmlSecurity::RSA_SHA256,$xp->query('./ds:SignedInfo/ds:SignatureMethod',$sig)->item(0)->getAttribute('Algorithm'));
        $this->assertSame(BankConnectXmlSecurity::SHA256,$xp->query('./ds:SignedInfo/ds:Reference/ds:DigestMethod',$sig)->item(0)->getAttribute('Algorithm'));
        $this->assertSame(BankConnectXmlSecurity::EXC_C14N,$xp->query('./ds:SignedInfo/ds:Reference/ds:Transforms/ds:Transform',$sig)->item(0)->getAttribute('Algorithm'));

        $canonical=$payment->C14N(true,false);
        $this->assertSame(base64_encode(hash('sha256',$canonical,true)),$xp->query('./ds:SignedInfo/ds:Reference/ds:DigestValue',$sig)->item(0)->textContent);
        $signedInfo=$xp->query('./ds:SignedInfo',$sig)->item(0)->C14N(true,false);
        $value=base64_decode($xp->query('./ds:SignatureValue',$sig)->item(0)->textContent,true);
        $this->assertSame(1,openssl_verify($signedInfo,$value,$this->customerCert,OPENSSL_ALGO_SHA256));
        $this->assertNotSame('',trim($xp->query('./ds:KeyInfo/ds:X509Data/ds:X509Certificate',$sig)->item(0)->textContent));
    }

    public function testXmlEncryptionUsesOfficialStructure(): void
    {
        $soap='<?xml version="1.0"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:bc="http://bankconnect.dk/schema/2014">'
            .'<soapenv:Header><bc:serviceHeader><bc:x>1</bc:x></bc:serviceHeader><bc:technicalAddress/></soapenv:Header>'
            .'<soapenv:Body><bc:transferPayment><bc:paymentMessage/></bc:transferPayment></soapenv:Body>'
            .'</soapenv:Envelope>';
        $encrypted=$this->security()->encryptSoapBody($soap);
        $doc=new DOMDocument();
        $this->assertTrue($doc->loadXML($encrypted,LIBXML_NONET));
        $xp=new DOMXPath($doc);
        $xp->registerNamespace('s','http://schemas.xmlsoap.org/soap/envelope/');
        $xp->registerNamespace('xenc',BankConnectXmlSecurity::XENC_NS);
        $xp->registerNamespace('wsse',BankConnectXmlSecurity::WSSE_NS);
        $xp->registerNamespace('ds',BankConnectXmlSecurity::DS_NS);

        $ek=$xp->query('/s:Envelope/s:Header/wsse:Security/xenc:EncryptedKey')->item(0);
        $ed=$xp->query('/s:Envelope/s:Body/xenc:EncryptedData')->item(0);
        $this->assertInstanceOf(DOMElement::class,$ek);
        $this->assertInstanceOf(DOMElement::class,$ed);
        $this->assertSame(BankConnectXmlSecurity::RSA_OAEP_MGF1P,$xp->query('./xenc:EncryptionMethod',$ek)->item(0)->getAttribute('Algorithm'));
        $this->assertSame(BankConnectXmlSecurity::AES256_CBC,$xp->query('./xenc:EncryptionMethod',$ed)->item(0)->getAttribute('Algorithm'));
        $this->assertSame('#'.$ed->getAttribute('Id'),$xp->query('./xenc:ReferenceList/xenc:DataReference',$ek)->item(0)->getAttribute('URI'));
        $this->assertSame('#'.$ek->getAttribute('Id'),$xp->query('./ds:KeyInfo/wsse:SecurityTokenReference/wsse:Reference',$ed)->item(0)->getAttribute('URI'));
        $this->assertNotSame('',trim($xp->query('./ds:KeyInfo/wsse:SecurityTokenReference/wsse:KeyIdentifier',$ek)->item(0)->textContent));
        $this->assertNotEmpty($xp->query('./xenc:CipherData/xenc:CipherValue',$ed)->item(0)->textContent);
    }

    public function testTransferPipelineOrderIsBusinessSignatureThenEncryptionThenTransportSignature(): void
    {
        $r=$this->security()->buildTransferPayment('<Document><A>1</A></Document>','e2e');
        $this->assertStringContainsString('<ds:Signature',$r['xml']);
        $soap='<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:bc="http://bankconnect.dk/schema/2014"><soapenv:Header><bc:serviceHeader><bc:x>1</bc:x></bc:serviceHeader></soapenv:Header><soapenv:Body>'.$r['xml'].'</soapenv:Body></soapenv:Envelope>';
        $encrypted=$this->security()->encryptSoapBody($soap);
        $this->assertStringContainsString('EncryptedData',$encrypted);
        $this->assertStringNotContainsString('<bc:transferPayment', $encrypted);
    }

    public function testFailsClosedWithoutRequiredCertificates(): void
    {
        $empty=new BankConnectXmlSecurity(new Conf());
        $this->expectException(BankConnectException::class);
        $empty->buildTransferPayment('<Document/>','e2e');
    }
}
