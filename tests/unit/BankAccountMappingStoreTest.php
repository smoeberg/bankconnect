<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/MockDoliDB.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankAccountMappingStore.php';

class BankAccountMappingStoreTest extends TestCase
{
    private $db;
    private $store;

    protected function setUp(): void
    {
        $this->db = new MockDoliDB();
        $this->store = new BankAccountMappingStore($this->db);

        $this->seedAgreement(1, 10);
        $this->seedBankAccount(1, 20, 0);
    }

    public function testMapCreatesEntityScopedMapping(): void
    {
        $id = $this->store->map(1, 10, 20, 7);

        $this->assertSame(1, $id);
        $mapping = $this->store->findByAgreement(1, 10);

        $this->assertNotNull($mapping);
        $this->assertSame(20, (int) $mapping['fk_bank_account']);
        $this->assertSame(1, (int) $mapping['entity']);
        $this->assertSame(7, (int) $mapping['fk_user_creat']);
    }

    public function testClosedBankAccountCannotBeMapped(): void
    {
        $this->seedBankAccount(1, 21, 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist, is closed');

        $this->store->map(1, 10, 21);
    }

    public function testCrossEntityAgreementCannotBeMapped(): void
    {
        $this->seedAgreement(2, 11);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('agreement does not exist in this entity');

        $this->store->map(1, 11, 20);
    }

    public function testCrossEntityBankAccountCannotBeMapped(): void
    {
        $this->seedBankAccount(2, 22, 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist, is closed');

        $this->store->map(1, 10, 22);
    }

    public function testRemappingAgreementReplacesPreviousMapping(): void
    {
        $this->seedBankAccount(1, 23, 0);

        $this->store->map(1, 10, 20);
        $this->store->map(1, 10, 23);

        $mapping = $this->store->findByAgreement(1, 10);
        $this->assertSame(23, (int) $mapping['fk_bank_account']);
        $this->assertCount(1, $this->store->listMappings(1));
    }

    public function testUnmapRemovesMapping(): void
    {
        $this->store->map(1, 10, 20);
        $this->store->unmap(1, 10);

        $this->assertNull($this->store->findByAgreement(1, 10));
    }

    private function seedAgreement(int $entity, int $id): void
    {
        $this->db->query(
            "INSERT INTO llx_bankconnect_agreement (entity, label, bank_connect_id, status)"
            ." VALUES (".$entity.", 'Agreement ".$id."', 'BC".$id."', 'active')"
        );
    }

    private function seedBankAccount(int $entity, int $id, int $closed): void
    {
        $this->db->query(
            "INSERT INTO llx_bank_account (rowid, entity, label, clos)"
            ." VALUES (".$id.", ".$entity.", 'Account ".$id."', ".$closed.")"
        );
    }
}
