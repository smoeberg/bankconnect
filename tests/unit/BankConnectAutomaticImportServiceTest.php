<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectAutomaticImportService.php';

class BankConnectAutomaticImportServiceTest extends TestCase
{
	public function testImportsOnlyActiveMappedAgreementsThroughSharedImporter(): void
	{
		$agreements = new class extends AgreementStore {
			public function __construct() {}
			public array $syncResults = [];
			public function getAgreement(int $id): ?array
			{
				return $id === 1
					? ['rowid' => 1, 'status' => 'active', 'main_registration_number' => '8079', 'bank_connect_id' => 'BC1']
					: ['rowid' => 2, 'status' => 'draft', 'main_registration_number' => '8079', 'bank_connect_id' => 'BC2'];
			}
			public function recordSyncResult(int $agreementId, string $summary, string $error = ''): void
			{
				$this->syncResults[] = [$agreementId, $summary, $error];
			}
		};
		$mappings = new class extends BankAccountMappingStore {
			public function __construct() {}
			public function listMappings(int $entity): array
			{
				return [
					['fk_agreement' => 1, 'fk_bank_account' => 1004],
					['fk_agreement' => 2, 'fk_bank_account' => 1005],
				];
			}
		};
		$importer = new class extends ImportService {
			public array $calls = [];
			public function __construct() {}
			public function import(string $xml, int $fkBankAccount = 0, string $sourceFile = 'import', $user = null): array
			{
				$this->calls[] = [$xml, $fkBankAccount, $sourceFile, $user];
				return ['imported' => 2, 'duplicates' => 1, 'total' => 3];
			}
		};
		$camt = '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt/></Document>';
		$client = new class extends BankConnectClient {
			public string $header = '';
			public function __construct() {}
			public function getCustomerStatement(string $serviceHeaderXml): string
			{
				$this->header = $serviceHeaderXml;
				return '<Envelope><content>'.base64_encode('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt/></Document>').'</content></Envelope>';
			}
			public function getCustomerAccountReport(string $serviceHeaderXml): string
			{
				return '<Envelope><content>'.base64_encode('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.052.001.02"><BkToCstmrAcctRpt/></Document>').'</content></Envelope>';
			}
			public function getDebitCreditNotification(string $serviceHeaderXml): string
			{
				return '<Envelope><content>'.base64_encode('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.02"><BkToCstmrDbtCdtNtfctn/></Document>').'</content></Envelope>';
			}
		};
		$clients = new class($client) extends BankConnectClientFactory {
			private BankConnectClient $client;
			public function __construct(BankConnectClient $client) { $this->client = $client; }
			public function create(array $agreement): BankConnectClient { return $this->client; }
		};
		$user = (object)['id' => 42];
		$service = new BankConnectAutomaticImportService($agreements, $mappings, $importer, $clients);

		$result = $service->run(1, $user);

		$this->assertSame(1, $result['agreements']);
		$this->assertCount(3, $importer->calls, '053, 052 and 054 are all fetched');
		$this->assertSame(6, $result['imported']);
		$this->assertSame(3, $result['duplicates']);
		$this->assertSame(9, $result['total']);
		$this->assertSame([], $result['errors']);
		$this->assertCount(3, $importer->calls);
		$this->assertSame(1004, $importer->calls[0][1]);
		$this->assertSame($user, $importer->calls[0][3]);
		$this->assertStringContainsString('camt.053.001.02', $client->header);
		// The active agreement must have its sync status recorded, the draft one must not.
		$this->assertCount(1, $agreements->syncResults, 'one status record per active agreement');
		$this->assertSame([1, '6 imported, 3 duplicates, 0 deferred reversals, 9 total (camt.053/052/054)', ''], $agreements->syncResults[0]);
	}
}
