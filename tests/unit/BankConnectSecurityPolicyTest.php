<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectException.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectEndpointPolicy.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectSecretStore.php';

final class BankConnectSecurityPolicyTest extends TestCase
{
    public function testBankConnectTestEndpointIsAccepted(): void
    {
        $this->assertSame(
            'https://stest.bankconnect.dk/2019/04/04/services/CorporateService',
            BankConnectEndpointPolicy::validateBankConnect(
                'https://stest.bankconnect.dk/2019/04/04/services/CorporateService',
                'test'
            )
        );
    }

    public function testHttpEndpointIsRejected(): void
    {
        $this->expectException(BankConnectException::class);
        BankConnectEndpointPolicy::validateBankConnect(
            'http://stest.bankconnect.dk/2019/04/04/services/CorporateService',
            'test'
        );
    }

    public function testWrongHostIsRejected(): void
    {
        $this->expectException(BankConnectException::class);
        BankConnectEndpointPolicy::validateBankConnect(
            'https://evil.example/2019/04/04/services/CorporateService',
            'test'
        );
    }

    public function testWrongPathIsRejected(): void
    {
        $this->expectException(BankConnectException::class);
        BankConnectEndpointPolicy::validateBankConnect(
            'https://stest.bankconnect.dk/other',
            'test'
        );
    }

    public function testMistralWrongHostIsRejected(): void
    {
        $this->expectException(BankConnectException::class);
        BankConnectEndpointPolicy::validateMistral('https://evil.example/v1/chat/completions');
    }

    public function testSecretStoreDoesNotReadDolibarrConfiguration(): void
    {
        $conf = (object)['global' => ['BANKCONNECT_MISTRAL_API_KEY' => 'db-secret']];
        putenv('BANKCONNECT_MISTRAL_API_KEY');
        $this->assertNull((new BankConnectSecretStore($conf))->get('BANKCONNECT_MISTRAL_API_KEY', false));
    }
}
