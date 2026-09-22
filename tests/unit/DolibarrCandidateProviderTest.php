<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/DolibarrCandidateProvider.php';

class DolibarrCandidateProviderTest extends TestCase
{
	public function testCreditLoadsOpenCustomerObjectsAndFiltersCurrencyAndPaidInvoices(): void
	{
		$db = new CandidateProviderDb();
		$db->customerInvoices = [
			$this->invoice(10, 'INV-10', 'RF71-10', 1000, 250, 'DKK', 'Customer A'),
			$this->invoice(11, 'INV-11', '', 500, 500, 'DKK', 'Customer B'),
			$this->invoice(12, 'INV-12', '', 100, 0, 'EUR', 'Customer C'),
		];
		$db->customerPayments = [[
			'rowid' => 20, 'ref' => 'PAY-20', 'num_paiement' => 'FI-20',
			'datep' => '2026-09-20 12:00:00', 'amount' => 750, 'thirdparty' => 'Customer A',
		]];
		$db->variousCredits = [[
			'rowid' => 30, 'ref' => 'VAR-30', 'num_payment' => '', 'label' => 'Interest',
			'datep' => '2026-09-19', 'amount' => 25,
		]];
		$transaction = BankTransaction::fromArray(['tx_date' => '2026-09-22', 'amount' => 750, 'currency' => 'DKK']);

		$candidates = (new DolibarrCandidateProvider($db, 'DKK'))->forTransaction($transaction, 3);

		$this->assertSame(['customer_invoice', 'customer_payment', 'various_payment'], array_column(array_map(fn($c) => (array)$c, $candidates), 'type'));
		$invoice = $candidates[0];
		$this->assertSame(10, $invoice->id);
		$this->assertSame('RF71-10', $invoice->ref);
		$this->assertSame(750.0, $invoice->remaining);
		$this->assertSame('Customer A', $invoice->thirdparty);
		$this->assertStringContainsString('f.entity=3', $db->queries[0]);
	}

	public function testDebitLoadsSupplierObjectsOnly(): void
	{
		$db = new CandidateProviderDb();
		$db->supplierInvoices = [$this->invoice(40, 'SUP-40', 'SUPPLIER-REF', 1200, 200, 'DKK', 'Supplier A')];
		$db->supplierPayments = [[
			'rowid' => 41, 'ref' => 'SPAY-41', 'num_paiement' => '',
			'datep' => '2026-09-21', 'amount' => 1000, 'thirdparty' => 'Supplier A',
		]];
		$db->variousDebits = [];
		$transaction = BankTransaction::fromArray(['tx_date' => '2026-09-22', 'amount' => -1000, 'currency' => 'DKK']);

		$candidates = (new DolibarrCandidateProvider($db, 'DKK'))->forTransaction($transaction, 1);

		$this->assertSame(['supplier_invoice', 'supplier_payment'], array_column(array_map(fn($c) => (array)$c, $candidates), 'type'));
		$this->assertSame('SUPPLIER-REF', $candidates[0]->ref);
		$this->assertSame(1000.0, $candidates[0]->remaining);
		$this->assertStringContainsString('facture_fourn', $db->queries[0]);
	}

	private function invoice(int $id, string $ref, string $paymentReference, float $total, float $paid, string $currency, string $thirdparty): array
	{
		return [
			'rowid' => $id, 'ref' => $ref, 'ref_client' => $ref.'-EXT',
			'payment_reference' => $paymentReference, 'datef' => '2026-09-01',
			'date_lim_reglement' => '2026-09-22', 'total_ttc' => $total,
			'multicurrency_total_ttc' => $currency === 'DKK' ? 0 : $total,
			'multicurrency_code' => $currency === 'DKK' ? '' : $currency,
			'thirdparty' => $thirdparty, 'paid_main' => $paid,
			'paid_multi' => $currency === 'DKK' ? 0 : $paid,
		];
	}
}

class CandidateProviderDb
{
	public array $customerInvoices = [];
	public array $supplierInvoices = [];
	public array $customerPayments = [];
	public array $supplierPayments = [];
	public array $variousCredits = [];
	public array $variousDebits = [];
	public array $queries = [];

	public function query(string $sql): array
	{
		$this->queries[] = $sql;
		if (str_contains($sql, 'FROM llx_facture_fourn ')) return $this->supplierInvoices;
		if (str_contains($sql, 'FROM llx_facture ')) return $this->customerInvoices;
		if (str_contains($sql, 'FROM llx_paiementfourn ')) return $this->supplierPayments;
		if (str_contains($sql, 'FROM llx_paiement ')) return $this->customerPayments;
		if (str_contains($sql, 'FROM llx_payment_various')) return str_contains($sql, 'sens=1') ? $this->variousCredits : $this->variousDebits;
		return [];
	}

	public function fetch_object(&$result)
	{
		$row = array_shift($result);
		return $row ? (object)$row : false;
	}

	public function lasterror(): string { return ''; }
}
