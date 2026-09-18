<?php
/* Posting of approved BankConnect matches into Dolibarr's bank ledger.
 * Strictly approval-gated: nothing posts unless the match has approved_by set
 * and the transaction is in state 'approved'. Idempotent: re-posting an
 * already posted match is a no-op returning the existing bank entry id.
 * Every posting writes an audit event (evidence for DK-ACC-004/005). */

class ApprovalPosting
{
	/** @var DoliDB */
	private $db;
	/** @var BankConnectStore */
	private $store;
	/** @var callable */
	private $logger;

	public function __construct($db, BankConnectStore $store)
	{
		$this->db = $db;
		$this->store = $store;
		$this->logger = function ($m) { dol_syslog('BankConnect: '.$m); };
	}

	public function setLogger(callable $c): void
	{
		$this->logger = $c;
	}

	/**
	 * Post ONE approved match.
	 *
	 * @param int    $matchRowid    rowid from llx_bankconnect_match with approved_by set
	 * @param int    $userId        acting user (for audit)
	 * @param int    $bankAccountId target Dolibarr bank account rowid
	 * @param string $label         posting label (default: counterparty + reference)
	 * @return int   bank entry rowid posted (or already existing)
	 * @throws RuntimeException if not approved, rejected, or ledger write fails
	 */
	public function postApprovedMatch(int $matchRowid, int $userId, int $bankAccountId, ?string $label = null): int
	{
		$match = $this->loadMatch($matchRowid);

		if ($match['approved_by'] === null) {
			throw new RuntimeException('BankConnect: refusing to post unapproved match '.$matchRowid);
		}
		if ($match['state'] === 'rejected') {
			throw new RuntimeException('BankConnect: refusing to post rejected match '.$matchRowid);
		}
		if ($match['state'] === 'posted') {
			// idempotent re-entry
			($this->logger)('postApprovedMatch: match '.$matchRowid.' already posted, skipping');
			return $this->existingBankEntryForMatch($matchRowid) ?: 0;
		}

		$tx = $this->loadTransaction((int)$match['fk_transaction']);
		$label = $label ?: trim(($tx['counterparty'] ?: 'BankConnect').' '.($tx['reference'] ?: ''));

		$entryId = $this->insertBankEntry($bankAccountId, (float)$tx['amount'], $tx['tx_date'], $label, (string)($tx['reference'] ?? ''));
		if ($entryId <= 0) {
			throw new RuntimeException('BankConnect: bank entry insert failed: '.$this->db->lasterror());
		}

		// Mark posted + audit the full chain: which match, which user, which
		// ledger entry, which amount. This is the DK-ACC-004/005 evidence point.
		$this->store->setTransactionState((int)$match['fk_transaction'], 'posted');
		$this->store->audit($userId, 'posted', 'match '.$matchRowid.' -> bank entry '.$entryId.' amount '.$tx['amount'].' '.$tx['currency'].' date '.$tx['tx_date']);

		($this->logger)('posted match '.$matchRowid.' as bank entry '.$entryId);
		return $entryId;
	}

	/** Post all approved-but-unposted matches for an account. Returns count posted. */
	public function postAllApproved(int $userId, int $bankAccountId): int
	{
		$sql = "SELECT m.rowid AS mid
				FROM llx_bankconnect_match m
				JOIN llx_bankconnect_transaction t ON t.rowid = m.fk_transaction
				WHERE t.fk_bank_account = ".(int)$bankAccountId."
				  AND m.approved_by IS NOT NULL
				  AND t.state = 'approved'
				ORDER BY t.tx_date ASC";
		$res = $this->db->query($sql);
		$n = 0;
		while ($res && $o = $this->db->fetch_object($res)) {
			$this->postApprovedMatch((int)$o->mid, $userId, $bankAccountId);
			$n++;
		}
		if ($n > 0) {
			$this->store->audit($userId, 'posted_batch', $n.' approved matches posted for account '.$bankAccountId);
		}
		return $n;
	}

	// ---------------------------------------------------------------- internals

	private function loadMatch(int $rowid): array
	{
		$sql = "SELECT m.rowid, m.fk_transaction, m.approved_by, t.state";
		$sql .= " FROM llx_bankconnect_match m";
		$sql .= " JOIN llx_bankconnect_transaction t ON t.rowid = m.fk_transaction";
		$sql .= " WHERE m.rowid = ".(int)$rowid;
		$res = $this->db->query($sql);
		if (!$res || !($o = $this->db->fetch_object($res))) {
			throw new RuntimeException('BankConnect: match not found: '.$rowid);
		}
		return ['rowid' => (int)$o->rowid, 'fk_transaction' => (int)$o->fk_transaction, 'approved_by' => $o->approved_by, 'state' => $o->state];
	}

	private function loadTransaction(int $rowid): array
	{
		$sql = "SELECT rowid, tx_date, amount, currency, reference, counterparty FROM llx_bankconnect_transaction WHERE rowid = ".(int)$rowid;
		$res = $this->db->query($sql);
		if (!$res || !($o = $this->db->fetch_object($res))) {
			throw new RuntimeException('BankConnect: transaction not found: '.$rowid);
		}
		return (array)$o;
	}

	private function existingBankEntryForMatch(int $matchRowid): int
	{
		$sql = "SELECT detail FROM llx_bankconnect_audit WHERE event_type = 'posted' AND detail LIKE '%match ".(int)$matchRowid." ->%' ORDER BY rowid DESC LIMIT 1";
		$res = $this->db->query($sql);
		if ($res && ($o = $this->db->fetch_object($res)) && preg_match('/bank entry (\d+)/', (string)$o->detail, $m)) {
			return (int)$m[1];
		}
		return 0;
	}

	private function insertBankEntry(int $bankAccountId, float $amount, string $date, string $label, string $ref): int
	{
		// Dolibarr stores bank movements in llx_bank with fk_account. Amounts
		// are stored as cents (integer) to avoid float drift on reruns.
		$amountc = (int)round($amount * 100);
		$userId = 0;
		if (isset($GLOBALS['user']) && is_object($GLOBALS['user']) && !empty($GLOBALS['user']->id)) {
			$userId = (int)$GLOBALS['user']->id;
		}
		$sql = "INSERT INTO llx_bank (fk_account, datec, dateo, amountc, label, num, fk_user_author)
				VALUES (".(int)$bankAccountId.", NOW(), '".$this->db->escape($date)."', ".(int)$amountc.", '".$this->db->escape($label)."', '".$this->db->escape($ref)."', ".(int)$userId.")";
		if (!$this->db->query($sql)) {
			return 0;
		}
		return (int)$this->db->last_insert_id('llx_bank');
	}
}
