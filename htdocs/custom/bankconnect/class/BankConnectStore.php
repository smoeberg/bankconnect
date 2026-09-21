<?php
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

    /** @return array{rowid:int,duplicate:bool} */
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

        $res = $this->db->query("SELECT rowid FROM llx_bankconnect_transaction WHERE hash='".$this->db->escape($hash)."'");
        if (!$res || !($obj=$this->db->fetch_object($res))) {
            throw new RuntimeException('BankConnect: transaction upsert succeeded but row cannot be reloaded');
        }
        return ['rowid'=>(int)$obj->rowid,'duplicate'=>$duplicate];
    }

    public function saveMatch(int $txRowid,string $matchType,?string $ruleName,?int $fkBankentry,float $score,string $reason): void
    {
        $sql="INSERT INTO llx_bankconnect_match (fk_transaction,match_type,rule_name,fk_bankentry,score,reason)
              VALUES (".$txRowid.",'".$this->db->escape($matchType)."',".$this->nullable($ruleName).",
              ".$this->nullableInt($fkBankentry).",".(float)$score.",'".$this->db->escape($reason)."')";
        if(!$this->db->query($sql)) throw new RuntimeException('BankConnect: save match failed: '.$this->db->lasterror());
        $this->setTransactionState($txRowid,'proposed');
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
        $sql="SELECT rowid,tx_date,amount,currency,reference,counterparty,acct_svcr_ref,is_reversal,requires_manual_review,cam_file
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
