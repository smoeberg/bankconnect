<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/ReconciliationWorkflowService.php';

class ReconciliationWorkflowServiceTest extends TestCase
{
	public function testApprovalRevalidatesAndPersistsCurrentSelection(): void
	{
		$store = new WorkflowStore();
		$provider = new WorkflowProvider([$this->candidate(81, 'customer_invoice')]);
		$service = new ReconciliationWorkflowService($store, $provider);

		$service->approve(12, 4, 2, ['customer_invoice:81'], 7);

		$this->assertSame([[12, 'customer_invoice', 81]], $store->attached);
		$this->assertSame([501], $store->selected);
		$this->assertSame(12, $store->approvedMatch);
		$this->assertSame(7, $store->approvedBy);
	}

	public function testApprovalRejectsForgedOrNoLongerOpenCandidate(): void
	{
		$store = new WorkflowStore();
		$service = new ReconciliationWorkflowService($store, new WorkflowProvider([]));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no longer open or valid');
		$service->approve(12, 4, 2, ['customer_invoice:999'], 7);
	}

	public function testRejectAndDeferCheckBankAccountOwnershipFirst(): void
	{
		$store = new WorkflowStore();
		$store->allowOwnership = false;
		$service = new ReconciliationWorkflowService($store, new WorkflowProvider([]));

		try {
			$service->reject(12, 999, 7);
			$this->fail('Expected ownership validation to fail');
		} catch (RuntimeException $e) {
			$this->assertSame('wrong bank account', $e->getMessage());
		}
		$this->assertNull($store->rejectedMatch);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('wrong bank account');
		$service->defer(31, 999, 7);
	}

	private function candidate(int $id, string $type): Candidate
	{
		$candidate = new Candidate();
		$candidate->id = $id;
		$candidate->type = $type;
		$candidate->ref = 'INV-'.$id;
		$candidate->remaining = 550.0;
		return $candidate;
	}
}

class WorkflowProvider extends DolibarrCandidateProvider
{
	/** @var Candidate[] */
	private array $candidates;

	/** @param Candidate[] $candidates */
	public function __construct(array $candidates)
	{
		$this->candidates = $candidates;
	}

	public function forTransaction(BankTransaction $transaction, int $entity): array
	{
		return $this->candidates;
	}
}

class WorkflowStore extends BankConnectStore
{
	public bool $allowOwnership = true;
	public array $attached = [];
	public array $selected = [];
	public ?int $approvedMatch = null;
	public ?int $approvedBy = null;
	public ?int $rejectedMatch = null;

	public function __construct()
	{
	}

	public function transactionForMatch(int $matchRowid, int $bankAccountId): array
	{
		if (!$this->allowOwnership) throw new RuntimeException('wrong bank account');
		return ['rowid' => 31, 'tx_date' => '2026-09-22', 'amount' => 550, 'currency' => 'DKK'];
	}

	public function transactionForAccount(int $transactionId, int $bankAccountId): array
	{
		if (!$this->allowOwnership) throw new RuntimeException('wrong bank account');
		return ['rowid' => $transactionId];
	}

	public function ensureMatchCandidate(int $matchRowid, Candidate $candidate): int
	{
		$this->attached[] = [$matchRowid, $candidate->type, $candidate->id];
		return 501;
	}

	public function selectMatchCandidates(int $matchRowid, array $candidateRowIds, int $userId): void
	{
		$this->selected = $candidateRowIds;
	}

	public function approveMatch(int $matchRowid, int $userId): void
	{
		$this->approvedMatch = $matchRowid;
		$this->approvedBy = $userId;
	}

	public function rejectMatch(int $matchRowid, int $userId): void
	{
		$this->rejectedMatch = $matchRowid;
	}

	public function deferTransaction(int $txRowid, int $userId): void
	{
	}
}
