<?php
require_once __DIR__.'/AgreementStore.php';
require_once __DIR__.'/BankAccountMappingStore.php';
require_once __DIR__.'/BankConnectClientFactory.php';
require_once __DIR__.'/ServiceHeaderBuilder.php';

/** Runs a signed, read-only getStatus call for one fully configured agreement. */
class BankConnectConnectionTestService
{
	private AgreementStore $agreements;
	private BankAccountMappingStore $mappings;
	private BankConnectClientFactory $clients;

	public function __construct(AgreementStore $agreements, BankAccountMappingStore $mappings, BankConnectClientFactory $clients)
	{
		$this->agreements = $agreements;
		$this->mappings = $mappings;
		$this->clients = $clients;
	}

	public function test(int $agreementId, int $entity): void
	{
		$agreement = $this->agreements->getAgreement($agreementId);
		try {
			if ($agreement === null || (int)($agreement['entity'] ?? 0) !== $entity) throw new RuntimeException('BankConnect agreement does not belong to this entity');
			if ((string)($agreement['status'] ?? '') !== 'active') throw new RuntimeException('BankConnect agreement is not active');
			if ($this->agreements->getActiveCertificate($agreementId) === null) throw new RuntimeException('BankConnect agreement has no active customer certificate');
			if ($this->mappings->findByAgreement($entity, $agreementId) === null) throw new RuntimeException('BankConnect agreement is not mapped to a Dolibarr bank account');
			$header = (new ServiceHeaderBuilder())
				->setOrganisation((string)$agreement['main_registration_number'], 'DK')
				->setFunctionIdentification((string)$agreement['bank_connect_id'])
				->setErp('Dolibarr', defined('DOL_VERSION') ? DOL_VERSION : '')
				->build();
			$this->clients->create($agreement)->getStatus($header);
			$this->agreements->recordConnectionTest($agreementId, true);
		} catch (Throwable $e) {
			if ($agreement !== null && (int)($agreement['entity'] ?? 0) === $entity) $this->agreements->recordConnectionTest($agreementId, false, $this->safeError($e));
			throw $e;
		}
	}

	private function safeError(Throwable $error): string
	{
		$message = $error->getMessage();
		foreach (['not active', 'no active customer certificate', 'not mapped', 'does not belong to this entity'] as $safe) {
			if (str_contains($message, $safe)) return $message;
		}
		return 'Signed BankConnect getStatus call failed';
	}
}
