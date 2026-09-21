<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/MockDoliDB.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankAccountMappingStore.php';

class BankAccountMappingStoreTest extends TestCase
{
    private $db;
    private $store;
    private $agreementId;
    private $bankAccountId;

    protected function setUp(): void
    {
        $this->db = new MockDoliDB();
        $this->store = new BankAccountMappingStore($this->db);

        $this->agreementId = $this->seedAgreement(1, 'BC-10');
        $this->bankAccountId = $this->seedBankAccount(1, 'Account 20', 0);
    }

    public function testMapCreatesEntityScopedMapping(): void
    {
        $id = $this->store->map(1, $this->agreementId, $this->bankAccountId, 7);

        $this->assertSame(1, $id);
        $mapping = $this->store->findByAgreement(1, $this->agreementId);

        $this->assertNotNull($mapping);
        $this->assertSame($this->bankAccountId, (int) $mapping['fk_bank_account']);
        $this->assertSame(1, (int) $mapping['entity']);
        $this->assertSame(7, (int) $mapping['fk_user_creat']);
    }

    public function testClosedBankAccountCannotBeMapped(): void
    {
        $closedId = $this->seedBankAccount(1, 'Closed account', 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist, is closed');

        $this->store->map(1, $this->agreementId, $closedId);
    }

    public function testCrossEntityAgreementCannotBeMapped(): void
    {
        $otherAgreement = $this->seedAgreement(2, 'BC-11');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('agreement does not exist in this entity');

        $this->store->map(1, $otherAgreement, $this->bankAccountId);
    }

    public function testCrossEntityBankAccountCannotBeMapped(): void
    {
        $otherAccount = $this->seedBankAccount(2, 'Other entity account', 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist, is closed');

        $this->store->map(1, $this->agreementId, $otherAccount);
    }

    public function testRemappingAgreementReplacesPreviousMapping(): void
    {
        $replacementId = $this->seedBankAccount(1, 'Replacement', 0);

        $this->store->map(1, $this->agreementId, $this->bankAccountId);
        $this->store->map(1, $this->agreementId, $replacementId);

        $mapping = $this->store->findByAgreement(1, $this->agreementId);
        $this->assertSame($replacementId, (int) $mapping['fk_bank_account']);
        $this->assertCount(1, $this->store->listMappings(1));
    }

    public function testUnmapRemovesMapping(): void
    {
        $this->store->map(1, $this->agreementId, $this->bankAccountId);
        $this->store->unmap(1, $this->agreementId);

        $this->assertNull($this->store->findByAgreement(1, $this->agreementId));
    }

    private function seedAgreement(int $entity, string $bankConnectId): int
    {
        $this->db->query(
            "INSERT INTO llx_bankconnect_agreement (entity, label, bank_connect_id, status)"
            ." VALUES (".$entity.", 'Agreement', '".$bankConnectId."', 'active')"
        );
        return (int) $this->db->last_insert_id('llx_bankconnect_agreement');
    }

    private function seedBankAccount(int $entity, string $label, int $closed): int
    {
        $this->db->query(
            "INSERT INTO llx_bank_account (entity, label, clos)"
            ." VALUES (".$entity.", '".$label."', ".$closed.")"
        );
        return (int) $this->db->last_insert_id('llx_bank_account');
    }
}
