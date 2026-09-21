<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectException.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectLogger.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectXmlSecurity.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectClient.php';

final class BankConnectSoapHeadersTest extends TestCase
{
    private function client(): BankConnectClient
    {
        return new class(new Conf()) extends BankConnectClient {
            public function headersFor(string $operation): array
            {
                return $this->buildHttpHeaders($operation);
            }

            public function envelopeFor(string $body, string $header = ''): string
            {
                return $this->buildEnvelope($body, $header);
            }
        };
    }

    public function testCorporateServiceUsesOperationSpecificSoapActionsFromV37Wsdl(): void
    {
        $client = $this->client();

        $expected = [
            BankConnectClient::OP_GET_BANK_CERTIFICATE => 'getBankCertificate',
            BankConnectClient::OP_ACTIVATE_SERVICE_AGREEMENT => 'activateServiceAgreement',
            BankConnectClient::OP_RENEW_CUSTOMER_CERTIFICATE => 'renewCustomerCertificate',
            BankConnectClient::OP_TRANSFER_PAYMENTS => 'transferPayment',
            BankConnectClient::OP_GET_STATUS => 'getStatus',
            BankConnectClient::OP_GET_CUSTOMER_STATEMENT => 'getCustomerStatement',
            BankConnectClient::OP_GET_DEBIT_CREDIT_NOTIFICATION => 'getDebitCreditNotification',
            BankConnectClient::OP_GET_CUSTOMER_ACCOUNT_REPORT => 'getCustomerAccountReport',
            BankConnectClient::OP_GET_ALTERNATE => 'getAlternate',
        ];

        foreach ($expected as $operation => $action) {
            $this->assertContains(
                'SOAPAction: "urn:CorporateService:'.$action.'"',
                $client->headersFor($operation),
                'Unexpected SOAPAction for '.$operation
            );
        }
    }

    public function testSoapHeaderContainsTechnicalAddressInBankConnectNamespace(): void
    {
        $client = $this->client();
        $xml = $client->envelopeFor('<getStatus xmlns="http://bankconnect.dk/schema/2014"/>');

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml));

        $technicalAddresses = $dom->getElementsByTagNameNS(
            'http://bankconnect.dk/schema/2014',
            'technicalAddress'
        );
        $this->assertSame(1, $technicalAddresses->length);
        $technicalAddress = $technicalAddresses->item(0);
        $this->assertNotNull($technicalAddress);
        $this->assertSame(
            'http://schemas.xmlsoap.org/soap/envelope/',
            $technicalAddress->parentNode->parentNode->namespaceURI
        );
        $this->assertSame('Header', $technicalAddress->parentNode->localName);
    }

    public function testTransferPaymentSecurityOrderIsDatacenterSpecific(): void
    {
        $security = new class(new Conf()) extends BankConnectXmlSecurity {
            public function signRequest(string $xml): string { return 'SIGN('.$xml.')'; }
            public function encryptSoapBody(string $xml): string { return 'ENCRYPT('.$xml.')'; }
        };
        $bankdata = $this->securityClient('BANKDATA', $security);
        $nbs = $this->securityClient('NBS', $security);
        $bec = $this->securityClient('BEC', $security);

        $this->assertSame('SIGN(ENCRYPT(payload))', $bankdata->secure('payload'));
        $this->assertSame('SIGN(ENCRYPT(payload))', $nbs->secure('payload'));
        $this->assertSame('ENCRYPT(SIGN(payload))', $bec->secure('payload'));
    }

    public function testTransferPaymentRejectsUnknownDatacenter(): void
    {
        $security = new class(new Conf()) extends BankConnectXmlSecurity {
            public function signRequest(string $xml): string { return $xml; }
            public function encryptSoapBody(string $xml): string { return $xml; }
        };
        $client = $this->securityClient('UNKNOWN', $security);
        $this->expectException(BankConnectException::class);
        $client->secure('payload');
    }

    private function securityClient(string $datacenter, BankConnectXmlSecurity $security): BankConnectClient
    {
        $conf = new Conf();
        $conf->global['BANKCONNECT_DATACENTER'] = $datacenter;
        $client = new class($conf) extends BankConnectClient {
            public function secure(string $xml): string
            {
                return $this->secureEnvelope(BankConnectClient::OP_TRANSFER_PAYMENTS, $xml);
            }
        };
        $client->setXmlSecurity($security);
        return $client;
    }
}
