<?php
require_once __DIR__.'/Candidate.php';
/**
 * BankConnectStore – persistence boundary for imported bank transactions.
 *
 * The database UNIQUE(hash) constraint is the concurrency/idempotency boundary.
 */
class BankConnectStore
{
    private $db;

    public function __construct($db) { $this->db = $db; }

    public function upsertTransaction(array $t, int $fkBankAccount, string $sourceFile): int
    {
        return $this->upsertTransactionDetailed($t, $fkBankAccount, $sourceFile)['rowid'];
    }

    /** @return array{rowid:int,duplicate:bool,fk_bankentry:int,bank_entry_state:string} */
    public function upsertTransactionDetailed(array $t, int $fkBankAccount, string $sourceFile): array
    {
        if ($fkBankAccount <= 0) {
            throw new RuntimeException('BankConnect: a valid bank account is required for CAMT import');
        }

        $hash = !empty($t['hash'])
            ? (string)$t['hash']
            : hash('sha256', implode('|', [
                $fkBankAccount,
                $t['statement_id'] ?? '',
                $t['transaction_id'] ?? '',
                $t['date'] ?? '',
                $t['amount'] ?? '',
                $t['reference'] ?? '',
                $t['counterparty'] ?? '',
                $t['text'] ?? '',
                $t['acctSvcrRef'] ?? '',
            ]));

        $manualReview = !empty($t['requiresManualReview']) ? 1 : 0;
        $isReversal = !empty($t['isReversal']) ? 1 : 0;
        $acctSvcrRef = (string)($t['acctSvcrRef'] ?? '');

        $sql = "INSERT INTO llx_bankconnect_transaction
            (fk_bank_account,hash,statement_id,transaction_id,tx_date,amount,currency,reference,counterparty,
             acct_svcr_ref,is_reversal,requires_manual_review,cam_file,state,created_at)
            VALUES (".(int)$fkBankAccount.",
            '".$this->db->escape($hash)."',
            '".$this->db->escape((string)($t['statement_id'] ?? ''))."',
            '".$this->db->escape((string)($t['transaction_id'] ?? ''))."',
            '".$this->db->escape((string)$t['date'])."',
            ".(float)$t['amount'].",
            '".$this->db->escape($t['currency'] ?? 'DKK')."',
            '".$this->db->escape($t['reference'] ?? '')."',
            '".$this->db->escape($t['counterparty'] ?? '')."',
            '".$this->db->escape($acctSvcrRef)."',
            ".$isReversal.",
            ".$manualReview.",
            '".$this->db->escape($sourceFile)."',
            'unmatched',NOW())
            ON DUPLICATE KEY UPDATE rowid=rowid";

        if (!$this->db->query($sql)) {
            throw new RuntimeException('BankConnect: transaction upsert failed: '.$this->db->lasterror());
        }

        $affectedRes = $this->db->query('SELECT ROW_COUNT() AS affected');
        $affected = $affectedRes ? $this->db->fetch_object($affectedRes) : false;
        $duplicate = $affected && (int)$affected->affected === 0;

        $res = $this->db->query("SELECT rowid, fk_bankentry, bank_entry_state FROM llx_bankconnect_transaction WHERE hash='".$this->db->escape($hash)."'");
        if (!$res || !($obj=$this->db->fetch_object($res))) {
            throw new RuntimeException('BankConnect: transaction upsert succeeded but row cannot be reloaded');
        }
        return [
            'rowid' => (int)$obj->rowid,
            'duplicate' => $duplicate,
            'fk_bankentry' => (int)($obj->fk_bankentry ?? 0),
            'bank_entry_state' => (string)($obj->bank_entry_state ?? 'pending'),
        ];
    }

    /**
     * Atomically reserve creation of the Dolibarr bank entry.
     *
     * @return bool True only for the importer that owns the reservation.
     */
    public function claimBankEntry(int $transactionId): bool
    {
        $res = $this->db->query('SELECT fk_bankentry, bank_entry_state FROM llx_bankconnect_transaction WHERE rowid='.(int)$transactionId);
        $row = $res ? $this->db->fetch_object($res) : false;
        if (!$row || !empty($row->fk_bankentry) || !in_array((string)$row->bank_entry_state, ['pending', 'error'], true)) {
            return false;
        }

        $previousState = (string)($row->bank_entry_state ?? '');
		if ($previousState === '') {
			$previousState = 'pending';
		}
        $sql = "UPDATE llx_bankconnect_transaction SET bank_entry_state='creating', bank_entry_error=NULL"
            ." WHERE rowid=".(int)$transactionId
            ." AND fk_bankentry IS NULL AND bank_entry_state='".$this->db->escape($previousState)."'";
        if (!$this->db->query($sql)) {
            throw new RuntimeException('BankConnect: bank entry reservation failed: '.$this->db->lasterror());
        }

        $affectedRes = $this->db->query('SELECT ROW_COUNT() AS affected');
        $affected = $affectedRes ? $this->db->fetch_object($affectedRes) : false;
        return $affected && (int)$affected->affected === 1;
    }

    public function linkBankEntry(int $transactionId, int $bankEntryId): void
    {
        if ($bankEntryId <= 0) {
            throw new RuntimeException('BankConnect: invalid Dolibarr bank entry id');
        }
        $sql = "UPDATE llx_bankconnect_transaction SET fk_bankentry=".(int)$bankEntryId
            .", bank_entry_state='linked', bank_entry_error=NULL"
            ." WHERE rowid=".(int)$transactionId." AND bank_entry_state='creating' AND fk_bankentry IS NULL";
        if (!$this->db->query($sql)) {
            throw new RuntimeException('BankConnect: bank entry link failed: '.$this->db->lasterror());
        }
		$affectedRes = $this->db->query('SELECT ROW_COUNT() AS affected');
		$affected = $affectedRes ? $this->db->fetch_object($affectedRes) : false;
		if (!$affected || (int)$affected->affected !== 1) {
			throw new RuntimeException('BankConnect: bank entry link lost its reservation');
		}
    }

    public function failBankEntry(int $transactionId, string $error): void
    {
        $error = substr($error, 0, 255);
        $sql = "UPDATE llx_bankconnect_transaction SET bank_entry_state='error', bank_entry_error='"
            .$this->db->escape($error)."' WHERE rowid=".(int)$transactionId." AND fk_bankentry IS NULL";
        if (!$this->db->query($sql)) {
            throw new RuntimeException('BankConnect: bank entry failure state could not be saved: '.$this->db->lasterror());
        }
    }

    public function saveMatch(
        int $txRowid,
        string $matchType,
        ?string $ruleName,
        ?int $fkBankentry,
        float $score,
        string $reason,
        array $suggested = []
    ): int {
        $sql="INSERT INTO llx_bankconnect_match (fk_transaction,match_type,rule_name,fk_bankentry,score,reason)
              VALUES (".$txRowid.",'".$this->db->escape($matchType)."',".$this->nullable($ruleName).",
              ".$this->nullableInt($fkBankentry).",".(float)$score.",'".$this->db->escape($reason)."')";
        if(!$this->db->query($sql)) throw new RuntimeException('BankConnect: save match failed: '.$this->db->lasterror());

        $matchId = (int)$this->db->last_insert_id('llx_bankconnect_match');
        foreach ($suggested as $candidate) {
            $id = (string)($candidate['id'] ?? '');
            $type = (string)($candidate['type'] ?? '');
            if ($id === '' || $type === '') {
                continue;
            }
            $ref = array_key_exists('ref', $candidate) ? (string)$candidate['ref'] : null;
            $refSql = $ref === null ? 'NULL' : "'".$this->db->escape($ref)."'";
            $amount = (float)($candidate['amount'] ?? 0);
            $candidateSql = "INSERT INTO llx_bankconnect_match_candidate
                (fk_match,candidate_id,candidate_type,candidate_ref,amount,selected,created_at)
                VALUES (".$matchId.",'".$this->db->escape($id)."','".$this->db->escape($type)."',"
                .$refSql.",".$amount.",0,NOW())
                ON DUPLICATE KEY UPDATE candidate_ref=VALUES(candidate_ref), amount=VALUES(amount)";
            if (!$this->db->query($candidateSql)) {
                throw new RuntimeException('BankConnect: save match candidate failed: '.$this->db->lasterror());
            }
        }

        $this->setTransactionState($txRowid,'proposed');
        return $matchId;
    }

    /** @return list<array<string,mixed>> */
    public function matchCandidates(int $matchRowid): array
    {
        $sql = "SELECT rowid, candidate_id, candidate_type, candidate_ref, amount, selected
                FROM llx_bankconnect_match_candidate
                WHERE fk_match = ".(int)$matchRowid."
                ORDER BY rowid ASC";
        $res = $this->db->query($sql);
        $out = [];
        while ($res && ($o = $this->db->fetch_object($res))) {
            $out[] = (array)$o;
        }
        return $out;
    }

	/** @return list<array<string,mixed>> */
	public function proposedMatchesForAccount(int $bankAccountId): array
	{
		$prefix = defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_';
		$sql = 'SELECT m.rowid AS match_rowid, m.match_type, m.rule_name, m.score, m.reason,'
			.' t.rowid, t.tx_date, t.amount, t.currency, t.reference, t.counterparty, t.acct_svcr_ref,'
			.' t.is_reversal, t.requires_manual_review, t.fk_bankentry, t.hash, t.statement_id, t.transaction_id'
			.' FROM '.$prefix.'bankconnect_match m'
			.' JOIN '.$prefix.'bankconnect_transaction t ON t.rowid=m.fk_transaction'
			.' WHERE t.fk_bank_account='.(int)$bankAccountId." AND t.state='proposed' AND m.approved_by IS NULL"
			.' ORDER BY t.tx_date DESC, m.rowid DESC';
		$res = $this->db->query($sql);
		$out = [];
		while ($res && ($row = $this->db->fetch_object($res))) {
			$item = (array)$row;
			$item['candidates'] = $this->matchCandidates((int)$row->match_rowid);
			$out[] = $item;
		}
		return $out;
	}

	/** @return array<string,mixed> */
	public function transactionForMatch(int $matchRowid, int $bankAccountId): array
	{
		$prefix = defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_';
		$sql = 'SELECT t.* FROM '.$prefix.'bankconnect_match m'
			.' JOIN '.$prefix.'bankconnect_transaction t ON t.rowid=m.fk_transaction'
			.' WHERE m.rowid='.(int)$matchRowid.' AND t.fk_bank_account='.(int)$bankAccountId
			." AND t.state='proposed' AND m.approved_by IS NULL LIMIT 1";
		$res = $this->db->query($sql);
		$row = $res ? $this->db->fetch_object($res) : false;
		if (!$row) {
			throw new RuntimeException('BankConnect: proposed match does not belong to this bank account');
		}
		return (array)$row;
	}

	/** @return array<string,mixed> */
	public function transactionForAccount(int $transactionId, int $bankAccountId): array
	{
		$prefix = defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_';
		$res = $this->db->query('SELECT * FROM '.$prefix.'bankconnect_transaction WHERE rowid='.(int)$transactionId.' AND fk_bank_account='.(int)$bankAccountId.' LIMIT 1');
		$row = $res ? $this->db->fetch_object($res) : false;
		if (!$row) {
			throw new RuntimeException('BankConnect: transaction does not belong to this bank account');
		}
		return (array)$row;
	}

	public function ensureMatchCandidate(int $matchRowid, Candidate $candidate): int
	{
		$prefix = defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_';
		$sql = 'INSERT INTO '.$prefix.'bankconnect_match_candidate'
			.' (fk_match,candidate_id,candidate_type,candidate_ref,amount,selected,created_at) VALUES ('
			.(int)$matchRowid.",'".$this->db->escape((string)$candidate->id)."','".$this->db->escape($candidate->type)."','"
			.$this->db->escape($candidate->ref)."',".(float)$candidate->remaining.',0,NOW())'
			.' ON DUPLICATE KEY UPDATE candidate_ref=VALUES(candidate_ref), amount=VALUES(amount)';
		if (!$this->db->query($sql)) {
			throw new RuntimeException('BankConnect: candidate could not be attached to match: '.$this->db->lasterror());
		}
		$res = $this->db->query('SELECT rowid FROM '.$prefix.'bankconnect_match_candidate'
			.' WHERE fk_match='.(int)$matchRowid." AND candidate_id='".$this->db->escape((string)$candidate->id)."'"
			." AND candidate_type='".$this->db->escape($candidate->type)."' LIMIT 1");
		$row = $res ? $this->db->fetch_object($res) : false;
		if (!$row) {
			throw new RuntimeException('BankConnect: candidate attachment cannot be reloaded');
		}
		return (int)$row->rowid;
	}

    /**
     * Select the concrete candidate(s) to use for a proposed match.
     * Empty selection is rejected for deterministic safety.
     */
    public function selectMatchCandidates(int $matchRowid, array $candidateRowIds, int $userId): void
    {
        $candidateRowIds = array_values(array_unique(array_map('intval', $candidateRowIds)));
        if (empty($candidateRowIds)) {
            throw new RuntimeException('BankConnect: at least one match candidate must be selected');
        }

        $this->db->begin();
        try {
            $matchRes = $this->db->query("SELECT fk_transaction FROM llx_bankconnect_match WHERE rowid=".(int)$matchRowid." LIMIT 1");
            if (!$matchRes || !($match = $this->db->fetch_object($matchRes))) {
                throw new RuntimeException('BankConnect: match not found: '.$matchRowid);
            }

            if (!$this->db->query("UPDATE llx_bankconnect_match_candidate SET selected=0 WHERE fk_match=".(int)$matchRowid)) {
                throw new RuntimeException('BankConnect: candidate reset failed: '.$this->db->lasterror());
            }

            foreach ($candidateRowIds as $candidateRowId) {
                $sql = "UPDATE llx_bankconnect_match_candidate
                        SET selected=1
                        WHERE rowid=".(int)$candidateRowId." AND fk_match=".(int)$matchRowid;
                if (!$this->db->query($sql)) {
                    throw new RuntimeException('BankConnect: candidate selection failed: '.$this->db->lasterror());
                }
                $verify = $this->db->query("SELECT rowid FROM llx_bankconnect_match_candidate WHERE rowid=".(int)$candidateRowId." AND fk_match=".(int)$matchRowid." AND selected=1");
                if (!$verify || !$this->db->fetch_object($verify)) {
                    throw new RuntimeException('BankConnect: invalid match candidate '.$candidateRowId);
                }
            }

            if (!$this->db->commit()) {
                throw new RuntimeException('BankConnect: candidate selection commit failed: '.$this->db->lasterror());
            }
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $this->audit($userId, 'match_candidates_selected', 'match '.$matchRowid.' candidates '.implode(',', $candidateRowIds));
    }

    public function deferTransaction(int $txRowid, int $userId): void
    {
        $this->setTransactionState($txRowid, 'deferred');
        $this->audit($userId, 'match_deferred', 'transaction '.$txRowid);
    }

    public function approveMatch(int $matchRowid,int $userId): void
    {
        $sql="UPDATE llx_bankconnect_match SET approved_by=".(int)$userId.",approved_at=NOW() WHERE rowid=".(int)$matchRowid;
        if(!$this->db->query($sql)) throw new RuntimeException('BankConnect: approve failed: '.$this->db->lasterror());
        $txid=$this->txOfMatch($matchRowid);
        $this->setTransactionState($txid,'approved');
        $this->audit($userId,'match_approved','match rowid '.$matchRowid.' tx '.$txid);
    }

    public function rejectMatch(int $matchRowid,int $userId): void
    {
        $txid=$this->txOfMatch($matchRowid);
        $this->setTransactionState($txid,'rejected');
        $this->audit($userId,'match_rejected','match rowid '.$matchRowid.' tx '.$txid);
    }

    public function setTransactionState(int $txRowid,string $state): void
    {
        $sql="UPDATE llx_bankconnect_transaction SET state='".$this->db->escape($state)."' WHERE rowid=".(int)$txRowid;
        if(!$this->db->query($sql)) throw new RuntimeException('BankConnect: state update failed: '.$this->db->lasterror());
    }

    public function transactionState(int $txRowid): string
    {
        $res=$this->db->query('SELECT state FROM llx_bankconnect_transaction WHERE rowid='.(int)$txRowid);
        if($res && $o=$this->db->fetch_object($res)) return (string)$o->state;
        throw new RuntimeException('BankConnect: transaction not found: '.$txRowid);
    }

    public function audit(int $userId,string $eventType,string $detail): void
    {
        $sql="INSERT INTO llx_bankconnect_audit (datetime_event,fk_user,event_type,detail)
              VALUES (NOW(),".(int)$userId.",'".$this->db->escape($eventType)."','".$this->db->escape($detail)."')";
        if(!$this->db->query($sql)) throw new RuntimeException('BankConnect: audit insert failed: '.$this->db->lasterror());
    }

    public function unmatchedTransactions(int $fkBankAccount): array
    {
        $sql="SELECT rowid,tx_date,amount,currency,reference,counterparty,acct_svcr_ref,is_reversal,requires_manual_review,cam_file,fk_bankentry,bank_entry_state,bank_entry_error,hash,statement_id,transaction_id
              FROM llx_bankconnect_transaction WHERE fk_bank_account=".(int)$fkBankAccount." AND state='unmatched' ORDER BY tx_date DESC";
        $res=$this->db->query($sql); $out=[];
        while($res && $o=$this->db->fetch_object($res)) $out[]=(array)$o;
        return $out;
    }

    public function unmatchedCount(int $fkBankAccount): int
    {
        $res=$this->db->query('SELECT COUNT(*) AS c FROM llx_bankconnect_transaction WHERE fk_bank_account='.(int)$fkBankAccount." AND state='unmatched'");
        return $res ? (int)$this->db->fetch_object($res)->c : 0;
    }

    private function txOfMatch(int $matchRowid): int
    {
        $res=$this->db->query('SELECT fk_transaction FROM llx_bankconnect_match WHERE rowid='.(int)$matchRowid);
        if(!$res || !($o=$this->db->fetch_object($res))) throw new RuntimeException('BankConnect: match not found: '.$matchRowid);
        return (int)$o->fk_transaction;
    }

    private function nullable(?string $v): string { return $v===null?'NULL':"'".$this->db->escape($v)."'"; }
    private function nullableInt(?int $v): string { return $v===null?'NULL':(string)$v; }
}
