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
}
