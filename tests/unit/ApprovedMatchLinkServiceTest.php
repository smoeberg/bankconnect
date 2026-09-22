<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/ApprovedMatchLinkService.php';

class ApprovedMatchLinkServiceTest extends TestCase
{
	public function testCreatesOneNativePaymentForSplitInvoicesAndUsesExistingBankEntry(): void
	{
		$store = new LinkWorkflowStore($this->match([
			$this->candidate(10, 'customer_invoice', 600),
			$this->candidate(11, 'customer_invoice', 700),
		]));
		$gateway = new LinkWorkflowGateway();
		$service = new ApprovedMatchLinkService($store, $gateway);
		$user = (object)['id' => 9];

		$service->link(12, 4, 2, $user);

		$this->assertSame(7631, $gateway->validatedBankEntry);
		$this->assertSame([['id' => 10, 'amount' => 600.0], ['id' => 11, 'amount' => 400.0]], $gateway->allocations);
		$this->assertSame([['customer_payment', 901, 7631, 1000.0, 2]], $gateway->links);
		$this->assertSame('linked', $store->completedState);
		$this->assertSame(31, $store->completedTransaction);
	}

	public function testLinksExistingPaymentsOnlyWhenTheirTotalMatches(): void
	{
		$store = new LinkWorkflowStore($this->match([
			$this->candidate(20, 'supplier_payment', 1000),
		], -1000));
		$gateway = new LinkWorkflowGateway();

		(new ApprovedMatchLinkService($store, $gateway))->link(12, 4, 2, (object)['id' => 9]);

		$this->assertSame([
			['supplier_payment', 20, 7631, 1000.0, 2],
		], $gateway->links);
		$this->assertNull($gateway->allocations);
	}

	public function testFailsClosedForMixedCandidatesAndKeepsRetryState(): void
	{
		$store = new LinkWorkflowStore($this->match([
			$this->candidate(10, 'customer_invoice', 500),
			$this->candidate(20, 'customer_payment', 500),
		]));
		$service = new ApprovedMatchLinkService($store, new LinkWorkflowGateway());

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('cannot be mixed');
		try {
			$service->link(12, 4, 2, (object)['id' => 9]);
		} finally {
			$this->assertStringContainsString('cannot be mixed', (string)$store->failedError);
			$this->assertNull($store->completedState);
		}
	}

	private function match(array $candidates, float $amount = 1000): array
	{
		return [
			'match_rowid' => 12, 'transaction_rowid' => 31, 'link_state' => 'pending',
			'fk_bankentry' => 7631, 'fk_bank_account' => 4, 'tx_date' => '2026-09-22',
			'amount' => $amount, 'currency' => 'DKK', 'candidates' => $candidates,
		];
	}

	private function candidate(int $id, string $type, float $amount): array
	{
		return ['candidate_id' => (string)$id, 'candidate_type' => $type, 'amount' => $amount, 'selected' => 1];
	}
}

class LinkWorkflowStore extends BankConnectStore
{
	private array $match;
	public ?string $completedState = null;
	public ?int $completedTransaction = null;
	public ?string $failedError = null;

	public function __construct(array $match) { $this->match = $match; }
	public function approvedMatchForLink(int $matchRowid, int $bankAccountId): array { return $this->match; }
	public function claimMatchLink(int $matchRowid): string { return 'claim-token'; }
	public function completeMatchLink(int $matchRowid, string $token, int $transactionId, int $userId, string $detail): void
	{
		$this->completedState = 'linked';
		$this->completedTransaction = $transactionId;
	}
	public function failMatchLink(int $matchRowid, string $token, string $error): void { $this->failedError = $error; }
}

class LinkWorkflowGateway implements DolibarrPaymentLinkGatewayInterface
{
	public ?int $validatedBankEntry = null;
	public ?array $allocations = null;
	public array $links = [];
	public function baseCurrency(): string { return 'DKK'; }
	public function validateBankEntry(int $bankEntryId, int $bankAccountId, float $amount): void { $this->validatedBankEntry = $bankEntryId; }
	public function createOrFindInvoicePayment(int $matchId, string $invoiceType, array $allocations, array $transaction, int $bankAccountId, $user): int
	{
		$this->allocations = $allocations;
		return 901;
	}
	public function linkPayment(string $paymentType, int $paymentId, int $bankEntryId, float $expectedAmount, int $entity): void
	{
		$this->links[] = [$paymentType, $paymentId, $bankEntryId, $expectedAmount, $entity];
	}
}
