<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectException.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectEndpointPolicy.php';

class BankConnectEndpointPolicyTest extends TestCase
{
	public function testAcceptsOfficialV37Endpoints(): void
	{
		$this->assertSame(
			'https://stest.bankconnect.dk/2019/04/04/services/CorporateService',
			BankConnectEndpointPolicy::validateBankConnect('https://stest.bankconnect.dk/2019/04/04/services/CorporateService', 'test')
		);
		$this->assertSame(
			'https://bankconnectservices.dk/2019/04/04/services/CorporateService',
			BankConnectEndpointPolicy::validateBankConnect('https://bankconnectservices.dk/2019/04/04/services/CorporateService', 'production')
		);
	}

	public function testRejectsLegacyWwwProductionHost(): void
	{
		$this->expectException(BankConnectException::class);
		BankConnectEndpointPolicy::validateBankConnect('https://www.bankconnectservices.dk/2019/04/04/services/CorporateService', 'production');
	}
}
