<?php
/* Persistence layer for BankConnect: transactions, matches, audit events. */

class BankConnectStore
{
	/** @var DoliDB */
	private $db;

	public function __construct($db)
	{
		$this->db = $db;
	}

	/** Insert or update a transaction keyed by its content hash. Returns rowid. */
	public function upsertTransaction(array $t, int $fkBankAccount, string $sourceFile): int
	{
		return $this->upsertTransactionDetailed($t, $fkBankAccount, $sourceFile)['rowid'];
	}

	/**
	 * Prefer hash computed by CamtParser (includes text + acctSvcrRef).
	 * Fallback hash only when parser did not supply one.
	 *
	 * @return array{rowid:int, duplicate:bool}
	 */
	public function upsertTransactionDetailed(array $t, int $fkBankAccount, string $sourceFile): array
	{
		if (!empty($t['hash'])) {
			$hash = (string) $t['hash'];
		} else {
			$hash = hash('sha256', implode('|', [
				$t['date'] ?? '',
				$t['amount'] ?? '',
				$t['reference'] ?? '',
				$t['counterparty'] ?? '',
				$t['text'] ?? '',
				$t['acctSvcrRef'] ?? '',
			]));
		}

		$sql = "SELECT rowid FROM llx_bankconnect_transaction WHERE hash = '".$this->db->escape($hash)."'";
		$res = $this->db->query($sql);
		if ($res && $obj = $this->db->fetch_object($res)) {
			return ['rowid' => (int)$obj->rowid, 'duplicate' => true];
		}
		$sql = "INSERT INTO llx_bankconnect_transaction (fk_bank_account, hash, tx_date, amount, currency, reference, counterparty, cam_file, state, created_at)
				VALUES (".(int)$fkBankAccount.", '".$this->db->escape($hash)."', '".$this->db->escape($t['date'])."', ".(float)$t['amount'].", '".$this->db->escape($t['currency'] ?? 'DKK')."', '".$this->db->escape($t['reference'] ?? '')."', '".$this->db->escape($t['counterparty'] ?? '')."', '".$this->db->escape($sourceFile)."', 'unmatched', NOW())";
		if (!$this->db->query($sql)) {
			throw new RuntimeException('BankConnect: insert transaction failed: '.$this->db->lasterror());
		}
		return ['rowid' => (int)$this->db->last_insert_id('llx_bankconnect_transaction'), 'duplicate' => false];
	}

	/** Persist a proposed match (rule or AI). */
	public function saveMatch(int $txRowid, string $matchType, ?string $ruleName, ?int $fkBankentry, float $score, string $reason): void
	{
		$sql = "INSERT INTO llx_bankconnect_match (fk_transaction, match_type, rule_name, fk_bankentry, score, reason)
				VALUES (".(int)$txRowid.", '".$this->db->escape($matchType)."', ".$this->nullable($ruleName).", ".$this->nullableInt($fkBankentry).", ".(float)$score.", '".$this->db->escape($reason)."')";
		if (!$this->db->query($sql)) {
			throw new RuntimeException('BankConnect: save match failed: '.$this->db->lasterror());
		}
		$this->setTransactionState($txRowid, 'proposed');
	}

	/** Approve a match: user + audit event. Does NOT post — posting is separate step. */
	public function approveMatch(int $matchRowid, int $userId): void
	{
		$sql = "UPDATE llx_bankconnect_match SET approved_by = ".(int)$userId.", approved_at = NOW() WHERE rowid = ".(int)$matchRowid;
		if (!$this->db->query($sql)) {
			throw new RuntimeException('BankConnect: approve failed: '.$this->db->lasterror());
		}
		$txid = $this->txOfMatch($matchRowid);
		$this->setTransactionState($txid, 'approved');
		$this->audit($userId, 'match_approved', 'match rowid '.$matchRowid.' tx '.$txid);
	}

	public function rejectMatch(int $matchRowid, int $userId): void
	{
		$txid = $this->txOfMatch($matchRowid);
		$this->setTransactionState($txid, 'rejected');
		$this->audit($userId, 'match_rejected', 'match rowid '.$matchRowid.' tx '.$txid);
	}

	public function setTransactionState(int $txRowid, string $state): void
	{
		$sql = "UPDATE llx_bankconnect_transaction SET state = '".$this->db->escape($state)."' WHERE rowid = ".(int)$txRowid;
		if (!$this->db->query($sql)) {
			throw new RuntimeException('BankConnect: state update failed: '.$this->db->lasterror());
		}
	}

	public function transactionState(int $txRowid): string
	{
		$sql = "SELECT state FROM llx_bankconnect_transaction WHERE rowid = ".(int)$txRowid;
		$res = $this->db->query($sql);
		if ($res && $o = $this->db->fetch_object($res)) {
			return (string)$o->state;
		}
		throw new RuntimeException('BankConnect: transaction not found: '.$txRowid);
	}

	public function audit(int $userId, string $eventType, string $detail): void
	{
		$sql = "INSERT INTO llx_bankconnect_audit (datetime_event, fk_user, event_type, detail)
				VALUES (NOW(), ".(int)$userId.", '".$this->db->escape($eventType)."', '".$this->db->escape($detail)."')";
		if (!$this->db->query($sql)) {
			throw new RuntimeException('BankConnect: audit insert failed: '.$this->db->lasterror());
		}
	}

	/** All unmatched transactions for an account, newest first. */
	public function unmatchedTransactions(int $fkBankAccount): array
	{
		$sql = "SELECT rowid, tx_date, amount, currency, reference, counterparty, cam_file FROM llx_bankconnect_transaction
				WHERE fk_bank_account = ".(int)$fkBankAccount." AND state = 'unmatched' ORDER BY tx_date DESC";
		$res = $this->db->query($sql);
		$out = [];
		while ($res && $obj = $this->db->fetch_object($res)) {
			$out[] = (array)$obj;
		}
		return $out;
	}

	/** Count of unmatched — for the "differences visible" requirement. */
	public function unmatchedCount(int $fkBankAccount): int
	{
		$sql = "SELECT COUNT(*) AS c FROM llx_bankconnect_transaction WHERE fk_bank_account = ".(int)$fkBankAccount." AND state = 'unmatched'";
		$res = $this->db->query($sql);
		return $res ? (int)$this->db->fetch_object($res)->c : 0;
	}

	private function txOfMatch(int $matchRowid): int
	{
		$sql = "SELECT fk_transaction FROM llx_bankconnect_match WHERE rowid = ".(int)$matchRowid;
		$res = $this->db->query($sql);
		if (!$res || !($obj = $this->db->fetch_object($res))) {
			throw new RuntimeException('BankConnect: match not found: '.$matchRowid);
		}
		return (int)$obj->fk_transaction;
	}

	private function nullable(?string $v): string
	{
		return $v === null ? 'NULL' : "'".$this->db->escape($v)."'";
	}

	private function nullableInt(?int $v): string
	{
		return $v === null ? 'NULL' : (string)$v;
	}
}
