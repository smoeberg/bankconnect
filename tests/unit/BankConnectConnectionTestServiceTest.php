<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectConnectionTestService.php';

class BankConnectConnectionTestServiceTest extends TestCase
{
	public function testSignedStatusCallRecordsSuccessfulConnection(): void
	{
		$agreements = new ConnectionAgreementStore();
		$mappings = new ConnectionMappingStore(true);
		$client = new ConnectionClient();
		$service = new BankConnectConnectionTestService($agreements, $mappings, new ConnectionClientFactory($client));

		$service->test(7, 2);

		$this->assertTrue($client->called);
		$this->assertStringContainsString('<functionIdentification>BC-7</functionIdentification>', $client->header);
		$this->assertSame([7, true, ''], $agreements->recorded);
	}

	public function testMissingMappingFailsBeforeNetworkAndRecordsFailure(): void
	{
		$agreements = new ConnectionAgreementStore();
		$client = new ConnectionClient();
		$service = new BankConnectConnectionTestService($agreements, new ConnectionMappingStore(false), new ConnectionClientFactory($client));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('not mapped');
		try {
			$service->test(7, 2);
		} finally {
			$this->assertFalse($client->called);
			$this->assertFalse($agreements->recorded[1]);
		}
	}
}

class ConnectionAgreementStore extends AgreementStore
{
	public array $recorded = [];
	public function __construct() {}
	public function getAgreement(int $id): ?array
	{
		return ['rowid' => $id, 'entity' => 2, 'status' => 'active', 'main_registration_number' => '8079', 'bank_connect_id' => 'BC-7'];
	}
	public function getActiveCertificate(int $agreementId): ?array { return ['rowid' => 3]; }
	public function recordConnectionTest(int $agreementId, bool $success, string $error = ''): void { $this->recorded = [$agreementId, $success, $error]; }
}

class ConnectionMappingStore extends BankAccountMappingStore
{
	private bool $mapped;
	public function __construct(bool $mapped) { $this->mapped = $mapped; }
	public function findByAgreement(int $entity, int $agreementId): ?array { return $this->mapped ? ['fk_bank_account' => 4] : null; }
}

class ConnectionClientFactory extends BankConnectClientFactory
{
	private BankConnectClient $client;
	public function __construct(BankConnectClient $client) { $this->client = $client; }
	public function create(array $agreement): BankConnectClient { return $this->client; }
}

class ConnectionClient extends BankConnectClient
{
	public bool $called = false;
	public string $header = '';
	public function __construct() {}
	public function getStatus(string $serviceHeaderXml): string
	{
		$this->called = true;
		$this->header = $serviceHeaderXml;
		return '<getStatusResponse/>';
	}
}
