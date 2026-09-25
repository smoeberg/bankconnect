<?php
require_once __DIR__.'/BankConnectDatabasePrefix.php';
/**
 * Maps a BankConnect agreement to a native Dolibarr bank account.
 *
 * The mapping is entity-scoped and deliberately references Dolibarr's
 * llx_bank_account instead of duplicating bank-account data.
 */
class BankAccountMappingStore
{
    use BankConnectDatabasePrefix;

    /** @var DoliDB */
    private $db;

    public function __construct($db, ?string $prefix = null)
    {
        $this->db = $db;
        $this->initializeDatabasePrefix($prefix);
    }

    /**
     * Create or replace the mapping for an agreement within an entity.
     *
     * Both sides are validated against the current entity before the write.
     */
    public function map(int $entity, int $agreementId, int $bankAccountId, int $userId = 0): int
    {
        $entity = max(1, $entity);
        $this->assertAgreement($entity, $agreementId);
        $this->assertBankAccount($entity, $bankAccountId);

        $existing = $this->findByAgreement($entity, $agreementId);
        if ($existing !== null && (int) $existing['fk_bank_account'] === $bankAccountId) {
            return (int) $existing['rowid'];
        }

        $this->db->begin();
        try {
            // A bank account can only belong to one BankConnect agreement in an
            // entity. Remove the previous mapping only after all references have
            // been validated.
            $deleteAgreement = "DELETE FROM llx_bankconnect_account_mapping"
                . " WHERE entity = ".$entity
                . " AND fk_agreement = ".(int) $agreementId;
            if (!$this->prefixQuery($deleteAgreement)) {
                throw new RuntimeException('BankConnect: mapping cleanup failed: '.$this->db->lasterror());
            }

            $deleteBankAccount = "DELETE FROM llx_bankconnect_account_mapping"
                . " WHERE entity = ".$entity
                . " AND fk_bank_account = ".(int) $bankAccountId;
            if (!$this->prefixQuery($deleteBankAccount)) {
                throw new RuntimeException('BankConnect: mapping cleanup failed: '.$this->db->lasterror());
            }

            $sql = "INSERT INTO llx_bankconnect_account_mapping"
                . " (entity, fk_agreement, fk_bank_account, date_creation, fk_user_creat)"
                . " VALUES (".$entity.", ".(int) $agreementId.", ".(int) $bankAccountId
                . ", NOW(), ".max(0, $userId).")";

            if (!$this->prefixQuery($sql)) {
                throw new RuntimeException('BankConnect: mapping insert failed: '.$this->db->lasterror());
            }

            if (!$this->db->commit()) {
                throw new RuntimeException('BankConnect: mapping commit failed: '.$this->db->lasterror());
            }

            return (int) $this->prefixLastInsertId('llx_bankconnect_account_mapping');
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function unmap(int $entity, int $agreementId): void
    {
        $sql = "DELETE FROM llx_bankconnect_account_mapping"
            . " WHERE entity = ".max(1, $entity)
            . " AND fk_agreement = ".(int) $agreementId;
        if (!$this->prefixQuery($sql)) {
            throw new RuntimeException('BankConnect: mapping delete failed: '.$this->db->lasterror());
        }
    }

    public function findByAgreement(int $entity, int $agreementId): ?array
    {
        $sql = "SELECT rowid, entity, fk_agreement, fk_bank_account, date_creation, fk_user_creat"
            . " FROM llx_bankconnect_account_mapping"
            . " WHERE entity = ".max(1, $entity)
            . " AND fk_agreement = ".(int) $agreementId
            . " LIMIT 1";
        $res = $this->prefixQuery($sql);
        if (!$res) {
            return null;
        }
        $obj = $this->db->fetch_object($res);
        return $obj ? (array) $obj : null;
    }

    public function findByBankAccount(int $entity, int $bankAccountId): ?array
    {
        $sql = "SELECT rowid, entity, fk_agreement, fk_bank_account, date_creation, fk_user_creat"
            . " FROM llx_bankconnect_account_mapping"
            . " WHERE entity = ".max(1, $entity)
            . " AND fk_bank_account = ".(int) $bankAccountId
            . " LIMIT 1";
        $res = $this->prefixQuery($sql);
        if (!$res) {
            return null;
        }
        $obj = $this->db->fetch_object($res);
        return $obj ? (array) $obj : null;
    }

    /** @return list<array> */
    public function listMappings(int $entity): array
    {
        $sql = "SELECT rowid, entity, fk_agreement, fk_bank_account, date_creation, fk_user_creat"
            . " FROM llx_bankconnect_account_mapping"
            . " WHERE entity = ".max(1, $entity)
            . " ORDER BY rowid DESC";
        $res = $this->prefixQuery($sql);
        $out = [];
        while ($res && ($obj = $this->db->fetch_object($res))) {
            $out[] = (array) $obj;
        }
        return $out;
    }

    private function assertAgreement(int $entity, int $agreementId): void
    {
        $sql = "SELECT rowid FROM llx_bankconnect_agreement"
            . " WHERE rowid = ".(int) $agreementId
            . " AND entity = ".max(1, $entity)
            . " LIMIT 1";
        $res = $this->prefixQuery($sql);
        if (!$res || !$this->db->fetch_object($res)) {
            throw new RuntimeException('BankConnect: agreement does not exist in this entity');
        }
    }

    private function assertBankAccount(int $entity, int $bankAccountId): void
    {
        $sql = "SELECT rowid FROM llx_bank_account"
            . " WHERE rowid = ".(int) $bankAccountId
            . " AND entity = ".max(1, $entity)
            . " AND clos = 0"
            . " LIMIT 1";
        $res = $this->prefixQuery($sql);
        if (!$res || !$this->db->fetch_object($res)) {
            throw new RuntimeException('BankConnect: bank account does not exist, is closed, or belongs to another entity');
        }
    }
}
