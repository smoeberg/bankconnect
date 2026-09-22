<?php
/**
 * BankConnectXmlSecurity – encrypt payment payload + sign requests.
 *
 * Encryption (TransferPayment SOAP body):
 *  - Optional gzip of the business payload if > 5 MB, then base64 in paymentMessage
 *  - XML Encryption AES-256-CBC with IV prepended to ciphertext
 *  - RSA-OAEP (SHA-1/MGF1) wrap of the AES key with the bank certificate
 *
 * Signature:
 *  - Bank Connect WS-Security XMLDSig on serviceHeader + SOAP Body
 *  - Signature and customer BinarySecurityToken live inside wsse:Security
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
    public const BC_NS = 'http://bankconnect.dk/schema/2014';
    public const DS_NS = 'http://www.w3.org/2000/09/xmldsig#';
    public const EXC_C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';
    public const SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';
    public const RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    public const XENC_NS = 'http://www.w3.org/2001/04/xmlenc#';
    public const AES256_CBC = 'http://www.w3.org/2001/04/xmlenc#aes256-cbc';
    public const RSA_OAEP_MGF1P = 'http://www.w3.org/2001/04/xmlenc#rsa-oaep-mgf1p';
    public const RSA_1_5 = 'http://www.w3.org/2001/04/xmlenc#rsa-1_5';
    public const WSSE_NS = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    public const WSU_NS = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';

    public const PACK_VERSION = 1;

    private Conf $conf;
    private ?string $customerPrivateKeyPem = null;
    private ?string $customerCertificatePem = null;
    private ?string $bankCertificatePem = null;

    public function __construct(Conf $conf)
    {
        $this->conf = $conf;
        $g = (array) ($conf->global ?? []);

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
    /**
     * Prepare the ISO 20022 payload for paymentMessage/content.
     * Bank Connect signs the ISO 20022 bytes (or gzipped bytes) before
     * SOAP-body XML Encryption. Content itself is base64 encoded.
     *
     * @return array{content:string,compressed:int}
     */
    public function preparePayloadDetailed(string $pain001Xml): array
    {
        $content=$pain001Xml; $compressed=0;
        if (strlen($content)>5*1024*1024) {
            $gz=gzencode($content,6);
            if ($gz===false) throw new BankConnectException('gzip compression failed');
            $content=$gz; $compressed=1;
        }
        return ['content'=>base64_encode($content),'compressed'=>$compressed];
    }

    /** @deprecated Compatibility alias; this performs content preparation, not encryption. */
    public function encryptPayloadDetailed(string $pain001Xml): array
    {
        return $this->preparePayloadDetailed($pain001Xml);
    }

    /** @deprecated Compatibility alias; this performs content preparation, not encryption. */
    public function encryptPayload(string $pain001Xml): string
    {
        return $this->preparePayloadDetailed($pain001Xml)['content'];
    }

    /**
     * Build and business-sign the Bank Connect TransferPayment payload.
     * Signature is placed immediately after paymentMessage and references
     * paymentMessage/@id using exclusive C14N, SHA-256 and RSA-SHA256.
     *
     * @return array{xml:string,compressed:int}
     */
    public function buildTransferPayment(string $pain001Xml, string $endToEndMessageId): array
    {
        $this->requireCustomerSigningMaterial();
        $prepared=$this->preparePayloadDetailed($pain001Xml);
        $doc=$this->newDocument();

        $transfer=$doc->createElementNS('http://bankconnect.dk/schema/2014','transferPayment');
        $doc->appendChild($transfer);

        $payment=$doc->createElementNS('http://bankconnect.dk/schema/2014','paymentMessage');
        $paymentId='Id-'.str_replace('-','',$this->uuidV4());
        $payment->setAttribute('id',$paymentId);
        foreach ([
            ['format','ISO20022'],
            ['mimeType','text/xml'],
            ['compressed',(string)$prepared['compressed']],
            ['content',$prepared['content']],
        ] as [$name,$value]) {
            $payment->appendChild($doc->createElementNS('http://bankconnect.dk/schema/2014',$name,$value));
        }
        $transfer->appendChild($payment);

        $signature=$this->createBusinessSignature($doc,$payment,$paymentId);
        $transfer->appendChild($signature);
        $this->completeBusinessSignature($signature);

        return ['xml'=>$doc->saveXML($doc->documentElement),'compressed'=>$prepared['compressed']];
    }

    /**
     * Compatibility helper. The content is not encrypted here; XML Encryption
     * is applied to the SOAP Body after the business signature.
     */
    public function buildPaymentMessage(string $base64Content,string $endToEndMessageId,int $compressed=0): string
    {
        $doc=$this->newDocument();
        $payment=$doc->createElementNS('http://bankconnect.dk/schema/2014','paymentMessage');
        $payment->setAttribute('id','Id-'.substr(hash('sha256',$endToEndMessageId),0,32));
        foreach ([['format','ISO20022'],['mimeType','text/xml'],['compressed',(string)$compressed],['content',$base64Content]] as [$name,$value]) {
            $payment->appendChild($doc->createElementNS('http://bankconnect.dk/schema/2014',$name,$value));
        }
        $doc->appendChild($payment);
        return $doc->saveXML($payment);
    }

    /**
     * XML Encryption of the SOAP Body. Bank Connect uses standard XML
     * Encryption: AES-256-CBC for data and RSA-OAEP-mgf1p for the AES key.
     */
    public function encryptSoapBody(string $soapXml, string $keyTransportAlgorithm = self::RSA_OAEP_MGF1P): string
    {
        $this->requireBankCertificate();
        $doc=$this->loadDocument($soapXml);
        $xp=new DOMXPath($doc);
        $xp->registerNamespace('s','http://schemas.xmlsoap.org/soap/envelope/');
        $body=$xp->query('/s:Envelope/s:Body')->item(0);
        if (!$body instanceof DOMElement) throw new BankConnectException('SOAP Body is required for XML encryption');
        if (!$body->firstChild) throw new BankConnectException('SOAP Body is empty');

        $inner='';
        foreach ($body->childNodes as $child) $inner.=$doc->saveXML($child);

        $aesKey=random_bytes(32); $iv=random_bytes(16);
        $ciphertext=openssl_encrypt($inner,'aes-256-cbc',$aesKey,OPENSSL_RAW_DATA,$iv);
        if ($ciphertext===false) throw new BankConnectException('AES-256-CBC encryption failed');
        $bankKey=openssl_pkey_get_public($this->bankCertificatePem);
        if ($bankKey===false) throw new BankConnectException('Invalid bank certificate');
        $wrapped='';
        $padding = $keyTransportAlgorithm === self::RSA_1_5 ? OPENSSL_PKCS1_PADDING : ($keyTransportAlgorithm === self::RSA_OAEP_MGF1P ? OPENSSL_PKCS1_OAEP_PADDING : null);
        if ($padding === null || !openssl_public_encrypt($aesKey,$wrapped,$bankKey,$padding)) {
            throw new BankConnectException('RSA key transport encryption failed');
        }

        $ekId='EK-'.$this->randomId(); $edId='ED-'.$this->randomId();
        $header=$xp->query('/s:Envelope/s:Header')->item(0);
        if (!$header instanceof DOMElement) throw new BankConnectException('SOAP Header is required for XML encryption');
        $security=$xp->query('/s:Envelope/s:Header/*[local-name()="Security"]')->item(0);
        if (!$security instanceof DOMElement) {
            $security=$doc->createElementNS('http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd','wsse:Security');
            $security->setAttributeNS('http://schemas.xmlsoap.org/soap/envelope/','soapenv:mustUnderstand','1');
            $header->insertBefore($security,$header->firstChild);
        } else {
            $security->setAttributeNS('http://schemas.xmlsoap.org/soap/envelope/','soapenv:mustUnderstand','1');
        }

        $xenc='http://www.w3.org/2001/04/xmlenc#';
        $ds='http://www.w3.org/2000/09/xmldsig#';
        $wsse='http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
        $wsse11='http://docs.oasis-open.org/wss/oasis-wss/oasis-wss-soap-message-security-1.1#';
        $wsse11Real='http://docs.oasis-open.org/wss/oasis-wss-wssecurity-secext-1.1.xsd';

        $ek=$doc->createElementNS($xenc,'xenc:EncryptedKey'); $ek->setAttribute('Id',$ekId);
        $ek->appendChild($this->xmlElement($doc,$xenc,'xenc:EncryptionMethod',null,['Algorithm'=>$keyTransportAlgorithm]));
        $ki=$doc->createElementNS($ds,'ds:KeyInfo');
        $str=$doc->createElementNS($wsse,'wsse:SecurityTokenReference');
        $kid=$doc->createElementNS($wsse,'wsse:KeyIdentifier',$this->certificateDerBase64($this->bankCertificatePem));
        $kid->setAttribute('EncodingType','http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary');
        $kid->setAttribute('ValueType','http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3');
        $str->appendChild($kid); $ki->appendChild($str); $ek->appendChild($ki);
        $cd=$doc->createElementNS($xenc,'xenc:CipherData');
        $cd->appendChild($doc->createElementNS($xenc,'xenc:CipherValue',base64_encode($wrapped)));
        $ek->appendChild($cd);
        $rl=$doc->createElementNS($xenc,'xenc:ReferenceList');
        $dr=$doc->createElementNS($xenc,'xenc:DataReference'); $dr->setAttribute('URI','#'.$edId);
        $rl->appendChild($dr); $ek->appendChild($rl);
        $security->insertBefore($ek,$security->firstChild);

        $ed=$doc->createElementNS($xenc,'xenc:EncryptedData'); $ed->setAttribute('Id',$edId); $ed->setAttribute('Type',$xenc.'Content');
        $ed->appendChild($this->xmlElement($doc,$xenc,'xenc:EncryptionMethod',null,['Algorithm'=>'http://www.w3.org/2001/04/xmlenc#aes256-cbc']));
        $eki=$doc->createElementNS($ds,'ds:KeyInfo');
        $estr=$doc->createElementNS($wsse,'wsse:SecurityTokenReference');
        $estr->setAttributeNS($wsse11Real,'wsse11:TokenType','http://docs.oasis-open.org/wss/oasis-wss-soap-message-security-1.1#EncryptedKey');
        $er=$doc->createElementNS($wsse,'wsse:Reference'); $er->setAttribute('URI','#'.$ekId);
        $estr->appendChild($er); $eki->appendChild($estr); $ed->appendChild($eki);
        $ecd=$doc->createElementNS($xenc,'xenc:CipherData');
        $ecd->appendChild($doc->createElementNS($xenc,'xenc:CipherValue',base64_encode($iv.$ciphertext)));
        $ed->appendChild($ecd);

        while ($body->firstChild) $body->removeChild($body->firstChild);
        $body->appendChild($ed);
        return $doc->saveXML();
    }

    private function createBusinessSignature(DOMDocument $doc,DOMElement $payment,string $paymentId): DOMElement
    {
        $canonical=$payment->C14N(true,false);
        if ($canonical===false) throw new BankConnectException('Failed to canonicalize paymentMessage');
        $sig=$doc->createElementNS('http://www.w3.org/2000/09/xmldsig#','ds:Signature');
        $sig->setAttribute('Id','DS-'.$this->randomId());
        $si=$doc->createElementNS('http://www.w3.org/2000/09/xmldsig#','ds:SignedInfo');
        $si->appendChild($this->xmlElement($doc,self::DS_NS,'ds:CanonicalizationMethod',null,['Algorithm'=>self::EXC_C14N]));
        $si->appendChild($this->xmlElement($doc,'http://www.w3.org/2000/09/xmldsig#','ds:SignatureMethod',null,['Algorithm'=>'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256']));
        $ref=$doc->createElementNS('http://www.w3.org/2000/09/xmldsig#','ds:Reference'); $ref->setAttribute('URI','#'.$paymentId);
        $ts=$doc->createElementNS('http://www.w3.org/2000/09/xmldsig#','ds:Transforms');
        $ts->appendChild($this->xmlElement($doc,self::DS_NS,'ds:Transform',null,['Algorithm'=>self::EXC_C14N]));
        $ref->appendChild($ts);
        $ref->appendChild($this->xmlElement($doc,'http://www.w3.org/2000/09/xmldsig#','ds:DigestMethod',null,['Algorithm'=>'http://www.w3.org/2001/04/xmlenc#sha256']));
        $ref->appendChild($doc->createElementNS('http://www.w3.org/2000/09/xmldsig#','ds:DigestValue',base64_encode(hash('sha256',$canonical,true))));
        $si->appendChild($ref); $sig->appendChild($si);
        $ki=$doc->createElementNS('http://www.w3.org/2000/09/xmldsig#','ds:KeyInfo');
        $xd=$doc->createElementNS('http://www.w3.org/2000/09/xmldsig#','ds:X509Data');
        $xd->appendChild($doc->createElementNS('http://www.w3.org/2000/09/xmldsig#','ds:X509Certificate',$this->certificateDerBase64($this->customerCertificatePem)));
        $ki->appendChild($xd); $sig->appendChild($ki);
        return $sig;
    }

    private function completeBusinessSignature(DOMElement $signature): void
    {
        $signedInfo=$signature->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#','SignedInfo')->item(0);
        if (!$signedInfo instanceof DOMElement) throw new BankConnectException('SignedInfo missing');
        $canonical=$signedInfo->C14N(true,false);
        if ($canonical===false) throw new BankConnectException('Failed to canonicalize SignedInfo');
        $value='';
        if (!openssl_sign($canonical,$value,$this->customerPrivateKeyPem,OPENSSL_ALGO_SHA256)) {
            throw new BankConnectException('Business XML signature failed');
        }
        $doc=$signature->ownerDocument;
        $signatureValue=$doc->createElementNS('http://www.w3.org/2000/09/xmldsig#','ds:SignatureValue',base64_encode($value));
        $signature->insertBefore($signatureValue,$signature->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#','KeyInfo')->item(0));
    }

    private function prepareDocument(string $xml): DOMDocument { return $this->loadDocument($xml); }

    private function newDocument(): DOMDocument
    {
        $doc=new DOMDocument('1.0','UTF-8'); $doc->preserveWhiteSpace=false; $doc->formatOutput=false; return $doc;
    }

    private function loadDocument(string $xml): DOMDocument
    {
        $doc=$this->newDocument(); $prev=libxml_use_internal_errors(true);
        try {
            if (!$doc->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS)) throw new BankConnectException('Cannot parse XML');
            return $doc;
        } finally { libxml_use_internal_errors($prev); libxml_clear_errors(); }
    }

    private function inclusiveNamespaces(DOMDocument $doc, string $prefixList): DOMElement
    {
        $el=$doc->createElementNS(self::EXC_C14N,'ec:InclusiveNamespaces');
        $el->setAttribute('PrefixList',$prefixList);
        return $el;
    }

    private function xmlElement(DOMDocument $doc,string $ns,string $name,?string $value,array $attrs=[]): DOMElement
    {
        $el=$doc->createElementNS($ns,$name,$value); foreach($attrs as $k=>$v)$el->setAttribute($k,$v); return $el;
    }

    private function certificateDerBase64(string $pem): string
    {
        if (preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s',$pem,$m)) {
            $der=base64_decode(preg_replace('/\s+/','',$m[1]),true);
            if ($der!==false) return base64_encode($der);
        }
        throw new BankConnectException('Invalid X.509 certificate PEM');
    }

    private function requireCustomerSigningMaterial(): void
    {
        if (!$this->customerPrivateKeyPem) throw new BankConnectException('Customer private key is required for BankConnect signing');
        if (!$this->customerCertificatePem) throw new BankConnectException('Customer certificate is required for BankConnect signing');
        if (openssl_pkey_get_private($this->customerPrivateKeyPem)===false) throw new BankConnectException('Invalid customer private key');
    }

    private function requireBankCertificate(): void
    {
        if (!$this->bankCertificatePem) throw new BankConnectException('Bank certificate is required for BankConnect XML encryption');
        if (openssl_pkey_get_public($this->bankCertificatePem)===false) throw new BankConnectException('Invalid bank certificate');
    }

    private function randomId(): string { return strtoupper(bin2hex(random_bytes(12))); }

    private function uuidV4(): string
    {
        $d=random_bytes(16); $d[6]=chr((ord($d[6])&15)|64); $d[8]=chr((ord($d[8])&63)|128);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
    }

    /** Sign serviceHeader and SOAP Body using the v3.7 WS-Security wire profile. */
    public function signRequest(string $xml): string
    {
        $this->requireCustomerSigningMaterial();
        $doc=$this->loadDocument($xml);
        $xp=new DOMXPath($doc);
        $xp->registerNamespace('s','http://schemas.xmlsoap.org/soap/envelope/');
        $header=$xp->query('/s:Envelope/s:Header')->item(0);
        $body=$xp->query('/s:Envelope/s:Body')->item(0);
        $serviceHeader=$xp->query('/s:Envelope/s:Header/*[local-name()="serviceHeader" and namespace-uri()="'.self::BC_NS.'"]')->item(0);
        if (!$header instanceof DOMElement || !$body instanceof DOMElement || !$serviceHeader instanceof DOMElement) {
            throw new BankConnectException('SOAP Header, serviceHeader and Body are required for Bank Connect signing');
        }

        $security=$xp->query('/s:Envelope/s:Header/*[local-name()="Security" and namespace-uri()="'.self::WSSE_NS.'"]')->item(0);
        if (!$security instanceof DOMElement) {
            $security=$doc->createElementNS(self::WSSE_NS,'wsse:Security');
            $security->setAttributeNS('http://schemas.xmlsoap.org/soap/envelope/','soapenv:mustUnderstand','1');
            $header->insertBefore($security,$header->firstChild);
        }

        $signature=$doc->createElementNS(self::DS_NS,'ds:Signature');
        $signature->setAttribute('Id','DS-'.$this->randomId());
        $signedInfo=$doc->createElementNS(self::DS_NS,'ds:SignedInfo');
        $cm=$this->xmlElement($doc,self::DS_NS,'ds:CanonicalizationMethod',null,['Algorithm'=>self::EXC_C14N]);
        $cm->appendChild($this->inclusiveNamespaces($doc,'soapenv'));
        $signedInfo->appendChild($cm);
        $signedInfo->appendChild($this->xmlElement($doc,self::DS_NS,'ds:SignatureMethod',null,['Algorithm'=>self::RSA_SHA256]));

        foreach ([$body,$serviceHeader] as $node) {
            $id=$node->getAttributeNS(self::WSU_NS,'Id');
            if ($id==='') {
                $id='Id-'.$this->randomId();
                $node->setAttributeNS(self::WSU_NS,'wsu:Id',$id);
            }
            $canonical=$node->C14N(true,false,null,['soapenv']);
            if ($canonical===false) throw new BankConnectException('Failed to canonicalize Bank Connect signed reference');
            $reference=$doc->createElementNS(self::DS_NS,'ds:Reference');
            $reference->setAttribute('URI','#'.$id);
            $transforms=$doc->createElementNS(self::DS_NS,'ds:Transforms');
            $transform=$this->xmlElement($doc,self::DS_NS,'ds:Transform',null,['Algorithm'=>self::EXC_C14N]);
            $transform->appendChild($this->inclusiveNamespaces($doc,'soapenv'));
            $transforms->appendChild($transform);
            $reference->appendChild($transforms);
            $reference->appendChild($this->xmlElement($doc,self::DS_NS,'ds:DigestMethod',null,['Algorithm'=>self::SHA256]));
            $reference->appendChild($doc->createElementNS(self::DS_NS,'ds:DigestValue',base64_encode(hash('sha256',$canonical,true))));
            $signedInfo->appendChild($reference);
        }
        $signature->appendChild($signedInfo);
        $security->insertBefore($signature,$security->firstChild);

        $canonicalSignedInfo=$signedInfo->C14N(true,false,null,['soapenv']);
        $signatureBytes='';
        if ($canonicalSignedInfo===false || !openssl_sign($canonicalSignedInfo,$signatureBytes,$this->customerPrivateKeyPem,OPENSSL_ALGO_SHA256)) {
            throw new BankConnectException('Bank Connect transport signature failed');
        }
        $signature->appendChild($doc->createElementNS(self::DS_NS,'ds:SignatureValue',base64_encode($signatureBytes)));

        $tokenId='X509-'.$this->randomId();
        $keyInfo=$doc->createElementNS(self::DS_NS,'ds:KeyInfo');
        $tokenReference=$doc->createElementNS(self::WSSE_NS,'wsse:SecurityTokenReference');
        $tokenReference->setAttributeNS(self::WSU_NS,'wsu:Id','Id-'.$this->randomId());
        $reference=$doc->createElementNS(self::WSSE_NS,'wsse:Reference');
        $reference->setAttribute('URI','#'.$tokenId);
        $reference->setAttribute('ValueType','http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3');
        $tokenReference->appendChild($reference); $keyInfo->appendChild($tokenReference); $signature->appendChild($keyInfo);

        $token=$doc->createElementNS(self::WSSE_NS,'wsse:BinarySecurityToken',$this->certificateDerBase64($this->customerCertificatePem));
        $token->setAttribute('EncodingType','http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary');
        $token->setAttribute('ValueType','http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3');
        $token->setAttributeNS(self::WSU_NS,'wsu:Id',$tokenId);
        $security->appendChild($token);
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
