<?php
require_once __DIR__.'/BankConnectStore.php';
require_once __DIR__.'/DolibarrCandidateProvider.php';

/** Validates user selections before a proposal may be approved. */
class ReconciliationWorkflowService
{
	private BankConnectStore $store;
	private DolibarrCandidateProvider $provider;

	public function __construct(BankConnectStore $store, DolibarrCandidateProvider $provider)
	{
		$this->store = $store;
		$this->provider = $provider;
	}

	/** @param list<string> $selection */
	public function approve(int $matchId, int $bankAccountId, int $entity, array $selection, int $userId): void
	{
		$transactionRow = $this->store->transactionForMatch($matchId, $bankAccountId);
		$selection = array_values(array_unique(array_filter(array_map('strval', $selection))));
		if (count($selection) < 1 || count($selection) > 5) {
			throw new RuntimeException('BankConnect: select between one and five candidates');
		}

		$available = [];
		foreach ($this->provider->forTransaction(BankTransaction::fromArray($transactionRow), $entity) as $candidate) {
			$available[$this->key($candidate->type, $candidate->id)] = $candidate;
		}
		$rowIds = [];
		foreach ($selection as $key) {
			if (!isset($available[$key])) {
				throw new RuntimeException('BankConnect: selected candidate is no longer open or valid');
			}
			$rowIds[] = $this->store->ensureMatchCandidate($matchId, $available[$key]);
		}

		$this->store->selectMatchCandidates($matchId, $rowIds, $userId);
		$this->store->approveMatch($matchId, $userId);
	}

	public function reject(int $matchId, int $bankAccountId, int $userId): void
	{
		$this->store->transactionForMatch($matchId, $bankAccountId);
		$this->store->rejectMatch($matchId, $userId);
	}

	public function defer(int $transactionId, int $bankAccountId, int $userId): void
	{
		$this->store->transactionForAccount($transactionId, $bankAccountId);
		$this->store->deferTransaction($transactionId, $userId);
	}

	public function key(string $type, $id): string
	{
		return $type.':'.(string)$id;
	}
}
