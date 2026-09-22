<?php
require_once __DIR__.'/Candidate.php';
require_once __DIR__.'/BankTransaction.php';

/** Reads reconciliation candidates from Dolibarr's native accounting objects. */
class DolibarrCandidateProvider
{
	private $db;
	private string $baseCurrency;
	private string $prefix;

	public function __construct($db, string $baseCurrency = 'DKK')
	{
		$this->db = $db;
		$this->baseCurrency = strtoupper($baseCurrency ?: 'DKK');
		$this->prefix = defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_';
	}

	/** @return Candidate[] */
	public function forTransaction(BankTransaction $transaction, int $entity): array
	{
		$entity = max(1, $entity);
		$currency = strtoupper($transaction->currency ?: $this->baseCurrency);
		$candidates = $transaction->amount >= 0
			? array_merge($this->customerInvoices($entity, $currency), $this->customerPayments($entity, $currency), $this->variousPayments($entity, true, $currency))
			: array_merge($this->supplierInvoices($entity, $currency), $this->supplierPayments($entity, $currency), $this->variousPayments($entity, false, $currency));

		usort($candidates, static function (Candidate $left, Candidate $right): int {
			return strcmp($right->date, $left->date);
		});
		return $candidates;
	}

	/** @return Candidate[] */
	private function customerInvoices(int $entity, string $currency): array
	{
		$sql = 'SELECT f.rowid, f.ref, f.ref_client, f.payment_reference, f.datef, f.date_lim_reglement,'
			.' f.total_ttc, f.multicurrency_total_ttc, f.multicurrency_code, s.nom AS thirdparty,'
			.' COALESCE((SELECT SUM(pf.amount) FROM '.$this->prefix.'paiement_facture pf WHERE pf.fk_facture=f.rowid), 0) AS paid_main,'
			.' COALESCE((SELECT SUM(pf.multicurrency_amount) FROM '.$this->prefix.'paiement_facture pf WHERE pf.fk_facture=f.rowid), 0) AS paid_multi'
			.' FROM '.$this->prefix.'facture f'
			.' JOIN '.$this->prefix.'societe s ON s.rowid=f.fk_soc'
			.' WHERE f.entity='.$entity.' AND f.fk_statut=1 AND f.paye=0';
		return $this->invoiceRows($sql, 'customer_invoice', $currency, false);
	}

	/** @return Candidate[] */
	private function supplierInvoices(int $entity, string $currency): array
	{
		$sql = 'SELECT f.rowid, f.ref, f.ref_supplier AS ref_client, f.payment_reference, f.datef, f.date_lim_reglement,'
			.' f.total_ttc, f.multicurrency_total_ttc, f.multicurrency_code, s.nom AS thirdparty,'
			.' COALESCE((SELECT SUM(pf.amount) FROM '.$this->prefix.'paiementfourn_facturefourn pf WHERE pf.fk_facturefourn=f.rowid), 0) AS paid_main,'
			.' COALESCE((SELECT SUM(pf.multicurrency_amount) FROM '.$this->prefix.'paiementfourn_facturefourn pf WHERE pf.fk_facturefourn=f.rowid), 0) AS paid_multi'
			.' FROM '.$this->prefix.'facture_fourn f'
			.' JOIN '.$this->prefix.'societe s ON s.rowid=f.fk_soc'
			.' WHERE f.entity='.$entity.' AND f.fk_statut=1 AND f.paye=0';
		return $this->invoiceRows($sql, 'supplier_invoice', $currency, true);
	}

	/** @return Candidate[] */
	private function invoiceRows(string $sql, string $type, string $currency, bool $supplier): array
	{
		$res = $this->query($sql, 'invoice candidates');
		$out = [];
		while ($res && ($row = $this->db->fetch_object($res))) {
			$rowCurrency = strtoupper((string)($row->multicurrency_code ?: $this->baseCurrency));
			if ($rowCurrency !== $currency) {
				continue;
			}
			$multi = $rowCurrency !== $this->baseCurrency;
			$total = $multi ? (float)$row->multicurrency_total_ttc : (float)$row->total_ttc;
			$paid = $multi ? (float)$row->paid_multi : (float)$row->paid_main;
			if ($total <= 0.005) {
				continue;
			}
			$remaining = abs($total) - abs($paid);
			if ($remaining <= 0.005) {
				continue;
			}
			$candidate = new Candidate();
			$candidate->id = (int)$row->rowid;
			$candidate->type = $type;
			$candidate->ref = $this->firstNonEmpty([$row->payment_reference, $supplier ? $row->ref_client : null, $row->ref]);
			$candidate->amount = abs($total);
			$candidate->remaining = $remaining;
			$candidate->date = (string)($row->date_lim_reglement ?: $row->datef);
			$candidate->thirdparty = (string)$row->thirdparty;
			$candidate->currency = $rowCurrency;
			$out[] = $candidate;
		}
		return $out;
	}

	/** @return Candidate[] */
	private function customerPayments(int $entity, string $currency): array
	{
		if ($currency !== $this->baseCurrency) {
			return [];
		}
		$sql = 'SELECT p.rowid, p.ref, p.num_paiement, p.datep, p.amount, MIN(s.nom) AS thirdparty'
			.' FROM '.$this->prefix.'paiement p'
			.' LEFT JOIN '.$this->prefix.'paiement_facture pf ON pf.fk_paiement=p.rowid'
			.' LEFT JOIN '.$this->prefix.'facture f ON f.rowid=pf.fk_facture'
			.' LEFT JOIN '.$this->prefix.'societe s ON s.rowid=f.fk_soc'
			.' WHERE p.entity='.$entity.' AND p.statut=1 AND p.fk_bank=0'
			.' GROUP BY p.rowid, p.ref, p.num_paiement, p.datep, p.amount';
		return $this->paymentRows($sql, 'customer_payment', $currency);
	}

	/** @return Candidate[] */
	private function supplierPayments(int $entity, string $currency): array
	{
		if ($currency !== $this->baseCurrency) {
			return [];
		}
		$sql = 'SELECT p.rowid, p.ref, p.num_paiement, p.datep, p.amount, MIN(s.nom) AS thirdparty'
			.' FROM '.$this->prefix.'paiementfourn p'
			.' LEFT JOIN '.$this->prefix.'paiementfourn_facturefourn pf ON pf.fk_paiementfourn=p.rowid'
			.' LEFT JOIN '.$this->prefix.'facture_fourn f ON f.rowid=pf.fk_facturefourn'
			.' LEFT JOIN '.$this->prefix.'societe s ON s.rowid=f.fk_soc'
			.' WHERE p.entity='.$entity.' AND p.statut=1 AND (p.fk_bank=0 OR p.fk_bank IS NULL)'
			.' GROUP BY p.rowid, p.ref, p.num_paiement, p.datep, p.amount';
		return $this->paymentRows($sql, 'supplier_payment', $currency);
	}

	/** @return Candidate[] */
	private function paymentRows(string $sql, string $type, string $currency): array
	{
		$res = $this->query($sql, 'payment candidates');
		$out = [];
		while ($res && ($row = $this->db->fetch_object($res))) {
			if (abs((float)$row->amount) <= 0.005) {
				continue;
			}
			$candidate = new Candidate();
			$candidate->id = (int)$row->rowid;
			$candidate->type = $type;
			$candidate->ref = $this->firstNonEmpty([$row->num_paiement, $row->ref]);
			$candidate->amount = abs((float)$row->amount);
			$candidate->remaining = $candidate->amount;
			$candidate->date = substr((string)$row->datep, 0, 10);
			$candidate->thirdparty = (string)($row->thirdparty ?? '');
			$candidate->currency = $currency;
			$out[] = $candidate;
		}
		return $out;
	}

	/** @return Candidate[] */
	private function variousPayments(int $entity, bool $credit, string $currency): array
	{
		if ($currency !== $this->baseCurrency) {
			return [];
		}
		$sql = 'SELECT rowid, ref, num_payment, label, datep, amount FROM '.$this->prefix.'payment_various'
			.' WHERE entity='.$entity.' AND sens='.($credit ? 1 : 0).' AND (fk_bank=0 OR fk_bank IS NULL)';
		$res = $this->query($sql, 'various payment candidates');
		$out = [];
		while ($res && ($row = $this->db->fetch_object($res))) {
			if (abs((float)$row->amount) <= 0.005) {
				continue;
			}
			$candidate = new Candidate();
			$candidate->id = (int)$row->rowid;
			$candidate->type = 'various_payment';
			$candidate->ref = $this->firstNonEmpty([$row->num_payment, $row->ref]);
			$candidate->amount = abs((float)$row->amount);
			$candidate->remaining = $candidate->amount;
			$candidate->date = (string)$row->datep;
			$candidate->thirdparty = (string)$row->label;
			$candidate->currency = $currency;
			$out[] = $candidate;
		}
		return $out;
	}

	/** @param array<int,mixed> $values */
	private function firstNonEmpty(array $values): string
	{
		foreach ($values as $value) {
			if (trim((string)$value) !== '') {
				return trim((string)$value);
			}
		}
		return '';
	}

	private function query(string $sql, string $context)
	{
		$result = $this->db->query($sql);
		if ($result === false) {
			throw new RuntimeException('BankConnect: unable to load '.$context.': '.$this->db->lasterror());
		}
		return $result;
	}
}
