<?php

/** Verifies that a linked BankConnect entry is consumable by Dolibarr's standard bank journal. */
class BankJournalHandoffService
{
	private $db;
	private string $prefix;

	public function __construct($db)
	{
		$this->db = $db;
		$this->prefix = defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_';
	}

	/** @return array{state:string,journal_id:int,payment_type:string,payment_id:int,transferred:bool} */
	public function status(int $bankEntryId, int $bankAccountId, int $entity): array
	{
		$result = ['state' => 'missing_bank_entry', 'journal_id' => 0, 'payment_type' => '', 'payment_id' => 0, 'transferred' => false];
		$sql = 'SELECT b.rowid, ba.fk_accountancy_journal FROM '.$this->prefix.'bank b'
			.' JOIN '.$this->prefix.'bank_account ba ON ba.rowid=b.fk_account'
			.' WHERE b.rowid='.(int)$bankEntryId.' AND b.fk_account='.(int)$bankAccountId.' AND ba.entity='.(int)$entity.' LIMIT 1';
		$res = $this->db->query($sql);
		$bank = $res ? $this->db->fetch_object($res) : false;
		if (!$bank) return $result;
		$result['journal_id'] = (int)($bank->fk_accountancy_journal ?? 0);
		if ($result['journal_id'] <= 0) {
			$result['state'] = 'missing_journal';
			return $result;
		}
		if (function_exists('isModEnabled') && !isModEnabled('accounting')) {
			$result['state'] = 'accounting_disabled';
			return $result;
		}

		$linkSql = 'SELECT type, url_id FROM '.$this->prefix.'bank_url WHERE fk_bank='.(int)$bankEntryId
			." AND type IN ('payment','payment_supplier','payment_various') ORDER BY rowid ASC LIMIT 1";
		$linkRes = $this->db->query($linkSql);
		$link = $linkRes ? $this->db->fetch_object($linkRes) : false;
		if (!$link) {
			$result['state'] = 'missing_payment_link';
			return $result;
		}
		$result['payment_type'] = (string)$link->type;
		$result['payment_id'] = (int)$link->url_id;
		$tables = ['payment' => 'paiement', 'payment_supplier' => 'paiementfourn', 'payment_various' => 'payment_various'];
		$table = $tables[$result['payment_type']] ?? '';
		$paymentRes = $table !== '' ? $this->db->query('SELECT rowid FROM '.$this->prefix.$table.' WHERE rowid='.$result['payment_id'].' AND fk_bank='.(int)$bankEntryId.' AND entity='.(int)$entity.' LIMIT 1') : false;
		if (!$paymentRes || !$this->db->fetch_object($paymentRes)) {
			$result['state'] = 'broken_payment_link';
			return $result;
		}

		$bookRes = $this->db->query('SELECT rowid FROM '.$this->prefix."accounting_bookkeeping WHERE doc_type='bank' AND fk_doc=".(int)$bankEntryId.' LIMIT 1');
		if ($bookRes && $this->db->fetch_object($bookRes)) {
			$result['state'] = 'transferred';
			$result['transferred'] = true;
			return $result;
		}
		$result['state'] = 'ready';
		return $result;
	}
}
