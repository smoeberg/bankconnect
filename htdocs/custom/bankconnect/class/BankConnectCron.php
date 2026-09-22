<?php
require_once __DIR__.'/BankConnectAutomaticImportService.php';
require_once __DIR__.'/BankConnectStore.php';
require_once __DIR__.'/DolibarrBankEntryService.php';

/** Dolibarr scheduled-job entry point for automatic statement import. */
class BankConnectCron
{
	public string $error = '';
	public array $errors = [];
	public string $output = '';

	/** @return int 0 on success, -1 on failure */
	public function importStatements(): int
	{
		global $db, $conf, $user;
		if (!is_object($db) || !is_object($conf) || !is_object($user) || empty($user->id)) {
			$this->error = 'BankConnect cron requires the Dolibarr database, configuration and cron user';
			return -1;
		}

		try {
			$agreements = new AgreementStore($db);
			$mappings = new BankAccountMappingStore($db);
			$store = new BankConnectStore($db);
			$importer = new ImportService($store, null, new DolibarrBankEntryService($db));
			$service = new BankConnectAutomaticImportService(
				$agreements,
				$mappings,
				$importer,
				new BankConnectClientFactory($conf, $agreements)
			);
			$result = $service->run((int)$conf->entity, $user);
			$this->errors = $result['errors'];
			$this->output = sprintf(
				'BankConnect: %d agreement(s), %d imported, %d duplicates',
				$result['agreements'],
				$result['imported'],
				$result['duplicates']
			);
			if (!empty($result['errors'])) {
				$this->error = implode('; ', $result['errors']);
				return -1;
			}
			return 0;
		} catch (Throwable $e) {
			$this->error = $e->getMessage();
			return -1;
		}
	}
}
