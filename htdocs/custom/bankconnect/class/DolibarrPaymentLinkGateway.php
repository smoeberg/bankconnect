<?php

interface DolibarrPaymentLinkGatewayInterface
{
	public function baseCurrency(): string;
	public function validateBankEntry(int $bankEntryId, int $bankAccountId, float $amount): void;
	/** @param list<array{id:int,amount:float}> $allocations */
	public function createOrFindInvoicePayment(int $matchId, string $invoiceType, array $allocations, array $transaction, int $bankAccountId, $user): int;
	public function linkPayment(string $paymentType, int $paymentId, int $bankEntryId, float $expectedAmount, int $entity): void;
}

/** Adapter around Dolibarr's native payment, bank-link and invoice APIs. */
class DolibarrPaymentLinkGateway implements DolibarrPaymentLinkGatewayInterface
{
	private $db;
	private string $prefix;

	public function __construct($db)
	{
		$this->db = $db;
		$this->prefix = defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_';
	}

	public function baseCurrency(): string
	{
		global $conf;
		return strtoupper((string)($conf->currency ?? 'DKK'));
	}

	public function validateBankEntry(int $bankEntryId, int $bankAccountId, float $amount): void
	{
		$res = $this->db->query('SELECT rowid, amount FROM '.$this->prefix.'bank WHERE rowid='.(int)$bankEntryId.' AND fk_account='.(int)$bankAccountId.' LIMIT 1');
		$row = $res ? $this->db->fetch_object($res) : false;
		if (!$row) throw new RuntimeException('BankConnect: imported Dolibarr bank entry was not found on this account');
		if (abs((float)$row->amount - $amount) > 0.005) throw new RuntimeException('BankConnect: bank entry amount no longer matches the imported transaction');
	}

	public function createOrFindInvoicePayment(int $matchId, string $invoiceType, array $allocations, array $transaction, int $bankAccountId, $user): int
	{
		$marker = 'BANKCONNECT-M'.$matchId;
		$table = $invoiceType === 'customer_invoice' ? 'paiement' : 'paiementfourn';
		$res = $this->db->query('SELECT rowid, amount FROM '.$this->prefix.$table." WHERE num_paiement='".$this->db->escape($marker)."' AND entity=".(int)$transaction['entity'].' LIMIT 1');
		if ($res && ($existing = $this->db->fetch_object($res))) {
			if (abs(abs((float)$existing->amount) - abs((float)$transaction['amount'])) > 0.005) throw new RuntimeException('BankConnect: idempotency marker belongs to another payment amount');
			return (int)$existing->rowid;
		}

		if (!defined('DOL_DOCUMENT_ROOT')) throw new RuntimeException('BankConnect: Dolibarr runtime is required to create a payment');
		require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';

		$isCustomer = $invoiceType === 'customer_invoice';
		$payment = $isCustomer ? new Paiement($this->db) : new PaiementFourn($this->db);
		$payment->datepaye = strtotime((string)$transaction['tx_date']);
		$payment->paiementcode = 'VIR';
		$payment->paiementid = (int)dol_getIdFromCode($this->db, 'VIR', 'c_paiement', 'code', 'id', 1);
		if ($payment->paiementid <= 0) throw new RuntimeException('BankConnect: Dolibarr bank-transfer payment type VIR was not found');
		$payment->num_payment = $marker;
		$payment->note_private = 'BankConnect match '.$matchId;
		$payment->fk_account = $bankAccountId;
		$payment->amounts = [];
		$payment->multicurrency_amounts = [];
		$payment->multicurrency_code = [];
		$payment->multicurrency_tx = [];
		foreach ($allocations as $allocation) {
			$invoice = $isCustomer ? new Facture($this->db) : new FactureFournisseur($this->db);
			if ($invoice->fetch($allocation['id']) <= 0) throw new RuntimeException('BankConnect: selected Dolibarr invoice cannot be loaded');
			$payment->amounts[$allocation['id']] = $allocation['amount'];
			$payment->multicurrency_amounts[$allocation['id']] = 0;
			$payment->multicurrency_code[$allocation['id']] = (string)($invoice->multicurrency_code ?? $this->baseCurrency());
			$payment->multicurrency_tx[$allocation['id']] = (float)($invoice->multicurrency_tx ?? 1);
		}
		$id = $payment->create($user, 1);
		if ($id <= 0) throw new RuntimeException('BankConnect: Dolibarr payment creation failed: '.($payment->error ?: 'unknown error'));
		return (int)$id;
	}

	public function linkPayment(string $paymentType, int $paymentId, int $bankEntryId, float $expectedAmount, int $entity): void
	{
		if (!defined('DOL_DOCUMENT_ROOT')) throw new RuntimeException('BankConnect: Dolibarr runtime is required to link a payment');
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/paymentvarious.class.php';

		$config = [
			'customer_payment' => ['table' => 'paiement', 'class' => 'Paiement', 'url' => DOL_URL_ROOT.'/compta/paiement/card.php?id=', 'link' => 'payment'],
			'supplier_payment' => ['table' => 'paiementfourn', 'class' => 'PaiementFourn', 'url' => DOL_URL_ROOT.'/fourn/paiement/card.php?id=', 'link' => 'payment_supplier'],
			'various_payment' => ['table' => 'payment_various', 'class' => 'PaymentVarious', 'url' => DOL_URL_ROOT.'/compta/bank/various_payment/card.php?id=', 'link' => 'payment_various'],
		];
		if (!isset($config[$paymentType])) throw new RuntimeException('BankConnect: unsupported payment type '.$paymentType);
		$one = $config[$paymentType];
		$res = $this->db->query('SELECT rowid, amount, fk_bank FROM '.$this->prefix.$one['table'].' WHERE rowid='.(int)$paymentId.' AND entity='.(int)$entity.' LIMIT 1');
		$row = $res ? $this->db->fetch_object($res) : false;
		if (!$row) throw new RuntimeException('BankConnect: selected Dolibarr payment no longer exists');
		if (abs(abs((float)$row->amount) - abs($expectedAmount)) > 0.005) throw new RuntimeException('BankConnect: selected Dolibarr payment amount has changed');
		if (!empty($row->fk_bank) && (int)$row->fk_bank !== $bankEntryId) throw new RuntimeException('BankConnect: selected Dolibarr payment is already linked to another bank entry');

		$className = $one['class'];
		$payment = new $className($this->db);
		if ($payment->fetch($paymentId) <= 0) throw new RuntimeException('BankConnect: selected Dolibarr payment cannot be loaded');
		if (empty($row->fk_bank) && $payment->update_fk_bank($bankEntryId) <= 0) throw new RuntimeException('BankConnect: Dolibarr payment could not be linked to the bank entry');
		$account = new Account($this->db);
		if ($account->add_url_line($bankEntryId, $paymentId, $one['url'], '(paiement)', $one['link']) <= 0) throw new RuntimeException('BankConnect: Dolibarr bank source link could not be created');
		$this->linkPaymentCompanies($account, $paymentType, $paymentId, $bankEntryId);
	}

	private function linkPaymentCompanies($account, string $paymentType, int $paymentId, int $bankEntryId): void
	{
		if ($paymentType === 'customer_payment') {
			$sql = 'SELECT DISTINCT s.rowid, s.nom FROM '.$this->prefix.'paiement_facture pf JOIN '.$this->prefix.'facture f ON f.rowid=pf.fk_facture JOIN '.$this->prefix.'societe s ON s.rowid=f.fk_soc WHERE pf.fk_paiement='.(int)$paymentId;
		} elseif ($paymentType === 'supplier_payment') {
			$sql = 'SELECT DISTINCT s.rowid, s.nom FROM '.$this->prefix.'paiementfourn_facturefourn pf JOIN '.$this->prefix.'facture_fourn f ON f.rowid=pf.fk_facturefourn JOIN '.$this->prefix.'societe s ON s.rowid=f.fk_soc WHERE pf.fk_paiementfourn='.(int)$paymentId;
		} else return;
		$res = $this->db->query($sql);
		while ($res && ($company = $this->db->fetch_object($res))) {
			if ($account->add_url_line($bankEntryId, (int)$company->rowid, DOL_URL_ROOT.'/comm/card.php?socid=', (string)$company->nom, 'company') <= 0) throw new RuntimeException('BankConnect: Dolibarr company link could not be created');
		}
	}
}
