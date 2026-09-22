<?php
require_once __DIR__.'/BankConnectStore.php';
require_once __DIR__.'/DolibarrPaymentLinkGateway.php';

/** Turns an approved reconciliation decision into native Dolibarr payment links. */
class ApprovedMatchLinkService
{
	private BankConnectStore $store;
	private DolibarrPaymentLinkGatewayInterface $gateway;

	public function __construct(BankConnectStore $store, DolibarrPaymentLinkGatewayInterface $gateway)
	{
		$this->store = $store;
		$this->gateway = $gateway;
	}

	public function link(int $matchId, int $bankAccountId, int $entity, $user): void
	{
		$data = $this->store->approvedMatchForLink($matchId, $bankAccountId);
		if (($data['link_state'] ?? '') === 'linked') return;
		$token = $this->store->claimMatchLink($matchId);
		try {
			if (empty($data['fk_bankentry'])) throw new RuntimeException('BankConnect: transaction has no imported Dolibarr bank entry');
			if (strtoupper((string)$data['currency']) !== $this->gateway->baseCurrency()) throw new RuntimeException('BankConnect: foreign-currency payment linking requires manual review');
			$candidates = $data['candidates'];
			if (!$candidates) throw new RuntimeException('BankConnect: approved match has no selected candidates');
			$this->gateway->validateBankEntry((int)$data['fk_bankentry'], $bankAccountId, (float)$data['amount']);
			$types = array_values(array_unique(array_column($candidates, 'candidate_type')));
			$detail = '';
			if (count($types) === 1 && in_array($types[0], ['customer_invoice', 'supplier_invoice'], true)) {
				$expected = $types[0] === 'customer_invoice' ? 1 : -1;
				if (((float)$data['amount'] <=> 0) !== $expected) throw new RuntimeException('BankConnect: invoice direction does not match bank entry direction');
				$allocations = $this->allocate(abs((float)$data['amount']), $candidates);
				$data['entity'] = $entity;
				$paymentId = $this->gateway->createOrFindInvoicePayment($matchId, $types[0], $allocations, $data, $bankAccountId, $user);
				$paymentType = $types[0] === 'customer_invoice' ? 'customer_payment' : 'supplier_payment';
				$this->gateway->linkPayment($paymentType, $paymentId, (int)$data['fk_bankentry'], abs((float)$data['amount']), $entity);
				$detail = 'match '.$matchId.' bank entry '.(int)$data['fk_bankentry'].' -> '.$paymentType.' '.$paymentId;
			} elseif (!array_diff($types, ['customer_payment', 'supplier_payment', 'various_payment'])) {
				if (count($types) !== 1) throw new RuntimeException('BankConnect: different payment types cannot share one bank entry');
				if (count($candidates) !== 1) throw new RuntimeException('BankConnect: one bank entry can link to only one existing payment');
				if ($types[0] === 'customer_payment' && (float)$data['amount'] <= 0) throw new RuntimeException('BankConnect: customer payment direction does not match the bank entry');
				if ($types[0] === 'supplier_payment' && (float)$data['amount'] >= 0) throw new RuntimeException('BankConnect: supplier payment direction does not match the bank entry');
				$total = array_sum(array_map(static fn (array $candidate): float => abs((float)$candidate['amount']), $candidates));
				if (abs($total - abs((float)$data['amount'])) > 0.005) throw new RuntimeException('BankConnect: selected payment total does not equal the bank entry');
				foreach ($candidates as $candidate) $this->gateway->linkPayment($types[0], (int)$candidate['candidate_id'], (int)$data['fk_bankentry'], (float)$candidate['amount'], $entity);
				$detail = 'match '.$matchId.' bank entry '.(int)$data['fk_bankentry'].' -> '.$types[0].' '.implode(',', array_column($candidates, 'candidate_id'));
			} else throw new RuntimeException('BankConnect: invoice and existing-payment candidates cannot be mixed');
			$this->store->completeMatchLink($matchId, $token, (int)$data['transaction_rowid'], (int)$user->id, $detail);
		} catch (Throwable $e) {
			$this->store->failMatchLink($matchId, $token, $e->getMessage());
			throw $e;
		}
	}

	/** @return list<array{id:int,amount:float}> */
	private function allocate(float $total, array $candidates): array
	{
		$remaining = $total;
		$out = [];
		foreach ($candidates as $candidate) {
			$amount = min($remaining, abs((float)$candidate['amount']));
			if ($amount > 0.005) $out[] = ['id' => (int)$candidate['candidate_id'], 'amount' => $amount];
			$remaining -= $amount;
		}
		if ($remaining > 0.005) throw new RuntimeException('BankConnect: selected invoices do not cover the bank entry amount');
		return $out;
	}
}
