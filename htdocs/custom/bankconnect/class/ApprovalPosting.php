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

		if ($match['state'] === 'rejected') {
			throw new RuntimeException('BankConnect: refusing to post rejected match '.$matchRowid);
		}
		if ($match['approved_by'] === null) {
			throw new RuntimeException('BankConnect: refusing to post unapproved match '.$matchRowid);
		}
		if ($match['state'] === 'posted') {
			// Persisted bank-entry identity is the primary idempotency record.
			$entryId = (int)($match['fk_bankentry'] ?? 0);
			if ($entryId <= 0) {
				$entryId = $this->existingBankEntryForMatch($matchRowid);
			}
			($this->logger)('postApprovedMatch: match '.$matchRowid.' already posted, skipping');
			return $entryId;
		}

		$transactionStarted = false;
		try {
			$this->db->begin();
			$transactionStarted = true;

			// Lock the economic transaction, not only the match row. This prevents
			// two approved matches for the same transaction from both posting it.
			$tx = $this->loadTransaction((int)$match['fk_transaction'], true);
			if ($tx['state'] === 'posted') {
				$this->db->rollback();
				$transactionStarted = false;
				return (int)($match['fk_bankentry'] ?? 0) ?: $this->existingBankEntryForMatch($matchRowid);
			}
			if ($tx['state'] !== 'approved') {
				throw new RuntimeException('BankConnect: refusing to post transaction '.$match['fk_transaction'].' in state '.$tx['state']);
			}

			$label = $label ?: trim(($tx['counterparty'] ?: 'BankConnect').' '.($tx['reference'] ?: ''));
			$entryId = $this->insertBankEntry($bankAccountId, (float)$tx['amount'], $tx['tx_date'], $label, (string)($tx['reference'] ?? ''));
			if ($entryId <= 0) {
				throw new RuntimeException('BankConnect: bank entry insert failed: '.$this->db->lasterror());
			}

			// Persist the bank entry identity in the same transaction as the ledger
			// write and state transition. A retry can therefore never create a second
			// economic movement after a successful commit.
			$sql = "UPDATE llx_bankconnect_match SET fk_bankentry = ".(int)$entryId." WHERE rowid = ".(int)$matchRowid." AND fk_bankentry IS NULL";
			if (!$this->db->query($sql)) {
				throw new RuntimeException('BankConnect: posting identity update failed: '.$this->db->lasterror());
			}
			$this->store->setTransactionState((int)$match['fk_transaction'], 'posted');
			$this->db->commit();
			$transactionStarted = false;
		} catch (Throwable $e) {
			if ($transactionStarted) {
				$this->db->rollback();
			}
			throw $e;
		}

		// Audit only after the economic transition has committed. An audit failure
		// must not roll back an already committed bank movement.
		$this->store->audit($userId, 'posted', 'match '.$matchRowid.' -> bank entry '.$entryId.' amount '.$tx['amount'].' '.$tx['currency'].' date '.$tx['tx_date']);
		($this->logger)('posted match '.$matchRowid.' as bank entry '.$entryId);
		return $entryId;
	}

	/** Post all approved-but-unposted matches for an account. Returns count posted. */
	public function postAllApproved(int $userId, int $bankAccountId): int
	{
		$sql = "SELECT rowid FROM llx_bankconnect_transaction WHERE fk_bank_account = ".(int)$bankAccountId." AND state = 'approved' ORDER BY tx_date ASC";
		$res = $this->db->query($sql);
		$txids = [];
		$n = 0;
		while ($res && $o = $this->db->fetch_object($res)) {
			$txids[] = (int)$o->rowid;
		}
		foreach ($txids as $txid) {
			$mres = $this->db->query("SELECT rowid FROM llx_bankconnect_match WHERE fk_transaction = ".$txid." AND approved_by IS NOT NULL ORDER BY rowid ASC");
			while ($mres && $mo = $this->db->fetch_object($mres)) {
				$this->postApprovedMatch((int)$mo->rowid, $userId, $bankAccountId);
				$n++;
			}
		}
		if ($n > 0) {
			$this->store->audit($userId, 'posted_batch', $n.' approved matches posted for account '.$bankAccountId);
		}
		return $n;
	}

	// ---------------------------------------------------------------- internals

	private function loadMatch(int $rowid): array
	{
		$sql = "SELECT rowid, fk_transaction, approved_by, fk_bankentry FROM llx_bankconnect_match WHERE rowid = ".(int)$rowid;
		$res = $this->db->query($sql);
		if (!$res || !($o = $this->db->fetch_object($res))) {
			throw new RuntimeException('BankConnect: match not found: '.$rowid);
		}
		$state = $this->store->transactionState((int)$o->fk_transaction);
		return ['rowid' => (int)$o->rowid, 'fk_transaction' => (int)$o->fk_transaction, 'approved_by' => $o->approved_by, 'fk_bankentry' => (int)($o->fk_bankentry ?? 0), 'state' => $state];
	}

	private function loadTransaction(int $rowid, bool $forUpdate = false): array
	{
		$sql = "SELECT rowid, tx_date, amount, currency, reference, counterparty, state FROM llx_bankconnect_transaction WHERE rowid = ".(int)$rowid.($forUpdate ? ' FOR UPDATE' : '');
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
