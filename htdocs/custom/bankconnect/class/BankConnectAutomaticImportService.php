<?php
require_once __DIR__.'/AgreementStore.php';
require_once __DIR__.'/BankAccountMappingStore.php';
require_once __DIR__.'/BankConnectClientFactory.php';
require_once __DIR__.'/BankConnectStatementResponseParser.php';
require_once __DIR__.'/ServiceHeaderBuilder.php';
require_once __DIR__.'/ImportService.php';

/** Runs automatic BankConnect statement retrieval through the shared import path. */
class BankConnectAutomaticImportService
{
	private AgreementStore $agreements;
	private BankAccountMappingStore $mappings;
	private ImportService $importer;
	private BankConnectClientFactory $clients;
	private BankConnectStatementResponseParser $responses;

	public function __construct(
		AgreementStore $agreements,
		BankAccountMappingStore $mappings,
		ImportService $importer,
		BankConnectClientFactory $clients,
		?BankConnectStatementResponseParser $responses = null
	) {
		$this->agreements = $agreements;
		$this->mappings = $mappings;
		$this->importer = $importer;
		$this->clients = $clients;
		$this->responses = $responses ?? new BankConnectStatementResponseParser();
	}

	/** @return array{agreements:int,imported:int,duplicates:int,total:int,errors:list<string>} */
	public function run(int $entity, $user): array
	{
		$result = ['agreements' => 0, 'imported' => 0, 'duplicates' => 0, 'total' => 0, 'errors' => []];
		foreach ($this->mappings->listMappings(max(1, $entity)) as $mapping) {
			$agreement = $this->agreements->getAgreement((int)$mapping['fk_agreement']);
			if ($agreement === null || (string)($agreement['status'] ?? '') !== 'active') {
				continue;
			}
			$result['agreements']++;
			try {
				$one = $this->runAgreement($agreement, (int)$mapping['fk_bank_account'], $user);
				$result['imported'] += $one['imported'];
				$result['duplicates'] += $one['duplicates'];
				$result['total'] += $one['total'];
			} catch (Throwable $e) {
				$result['errors'][] = 'Agreement #'.(int)$agreement['rowid'].': '.$e->getMessage();
			}
		}
		return $result;
	}

	/** @param array<string,mixed> $agreement
	 * @return array{imported:int,duplicates:int,total:int}
	 */
	private function runAgreement(array $agreement, int $bankAccountId, $user): array
	{
		$header = (new ServiceHeaderBuilder())
			->setOrganisation((string)$agreement['main_registration_number'], 'DK')
			->setFunctionIdentification((string)$agreement['bank_connect_id'])
			->setErp('Dolibarr', defined('DOL_VERSION') ? DOL_VERSION : '')
			->setFormat('camt.053.001.02');
		$serviceHeader = $header->build();
		$client = $this->clients->create($agreement);
		$response = $client->getCustomerStatement($serviceHeader);
		$camt = $this->responses->extract($response);
		$source = 'bankconnect:'.(int)$agreement['rowid'].':'.$header->getEndToEndMessageId();
		return $this->importer->import($camt, $bankAccountId, $source, $user);
	}
}
