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

    public function testAgreementValidationQueryFailureStopsMapping(): void
    {
        $this->db->failNextQueryContaining('FROM llx_bankconnect_agreement', 'database unavailable; password=secret');

        try {
            $this->store->map(1, $this->agreementId, $this->bankAccountId);
            $this->fail('A failed agreement validation must stop mapping');
        } catch (RuntimeException $e) {
            $this->assertSame('BankConnect: agreement validation query failed', $e->getMessage());
        }
        $this->assertSame(0, $this->db->countRows('llx_bankconnect_account_mapping'));
    }

    public function testBankAccountValidationQueryFailureStopsMapping(): void
    {
        $this->db->failNextQueryContaining('FROM llx_bank_account', 'database unavailable; password=secret');

        try {
            $this->store->map(1, $this->agreementId, $this->bankAccountId);
            $this->fail('A failed bank account validation must stop mapping');
        } catch (RuntimeException $e) {
            $this->assertSame('BankConnect: bank account validation query failed', $e->getMessage());
        }
        $this->assertSame(0, $this->db->countRows('llx_bankconnect_account_mapping'));
    }

    public function testMappedBankAccountClosedLaterIsRejectedBeforeImport(): void
    {
        $this->store->map(1, $this->agreementId, $this->bankAccountId);
        $this->store->assertUsableBankAccount(1, $this->bankAccountId);
        $this->db->query('UPDATE llx_bank_account SET clos = 1 WHERE rowid = '.$this->bankAccountId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist, is closed');
        $this->store->assertUsableBankAccount(1, $this->bankAccountId);
    }

    public function testMappedBankAccountMovedToAnotherEntityIsRejectedBeforeImport(): void
    {
        $this->store->map(1, $this->agreementId, $this->bankAccountId);
        $this->db->query('UPDATE llx_bank_account SET entity = 2 WHERE rowid = '.$this->bankAccountId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belongs to another entity');
        $this->store->assertUsableBankAccount(1, $this->bankAccountId);
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

    public function testFailedTransactionStartKeepsExistingMapping(): void
    {
        $db = new class extends MockDoliDB {
            public bool $failBegin = false;
            public function begin(): bool
            {
                if ($this->failBegin) {
                    $this->failNext('transaction unavailable');
                    return false;
                }
                return parent::begin();
            }
        };
        $db->query("INSERT INTO llx_bankconnect_agreement (entity, label, bank_connect_id, status) VALUES (1, 'Agreement', 'BC-10', 'active')");
        $db->query("INSERT INTO llx_bank_account (entity, label, clos) VALUES (1, 'Original', 0)");
        $db->query("INSERT INTO llx_bank_account (entity, label, clos) VALUES (1, 'Replacement', 0)");
        $store = new BankAccountMappingStore($db);
        $mappingId = $store->map(1, 1, 1);

        $db->failBegin = true;
        try {
            $store->map(1, 1, 2);
            $this->fail('Remapping must not delete the original row without a transaction');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('mapping transaction could not start: transaction unavailable', $e->getMessage());
        }

        $mapping = $store->findByAgreement(1, 1);
        $this->assertSame($mappingId, (int)$mapping['rowid']);
        $this->assertSame(1, (int)$mapping['fk_bank_account']);
        $this->assertCount(1, $store->listMappings(1));
    }

    public function testRemappingLeavesExistingMappingOnLookupFailure(): void
    {
        $replacementId = $this->seedBankAccount(1, 'Replacement', 0);
        $mappingId = $this->store->map(1, $this->agreementId, $this->bankAccountId);
        $this->db->failNextQueryContaining('FROM llx_bankconnect_account_mapping', 'database unavailable');

        try {
            $this->store->map(1, $this->agreementId, $replacementId);
            $this->fail('A failed mapping lookup must not proceed with remapping');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('mapping lookup by agreement failed: database unavailable', $e->getMessage());
        }

        $mapping = $this->store->findByAgreement(1, $this->agreementId);
        $this->assertSame($mappingId, (int)$mapping['rowid']);
        $this->assertSame($this->bankAccountId, (int)$mapping['fk_bank_account']);
        $this->assertCount(1, $this->store->listMappings(1));
    }

    public function testBankAccountLookupDistinguishesMissingMappingFromReadFailure(): void
    {
        $this->assertNull($this->store->findByBankAccount(1, $this->bankAccountId));
        $this->db->failNextQueryContaining('FROM llx_bankconnect_account_mapping', 'database unavailable');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mapping lookup by bank account failed: database unavailable');
        $this->store->findByBankAccount(1, $this->bankAccountId);
    }

    public function testUnmapRemovesMapping(): void
    {
        $this->store->map(1, $this->agreementId, $this->bankAccountId);
        $this->store->unmap(1, $this->agreementId);

        $this->assertNull($this->store->findByAgreement(1, $this->agreementId));
    }

    public function testEmptyMappingListIsDistinctFromReadFailure(): void
    {
        $this->assertSame([], $this->store->listMappings(1));

        $this->db->failNextQueryContaining('FROM llx_bankconnect_account_mapping', 'database unavailable');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('listMappings failed: database unavailable');
        $this->store->listMappings(1);
    }

    public function testMappingUsesConfiguredDolibarrDatabasePrefix(): void
    {
        $db = new MockDoliDB();
        $db->query("INSERT INTO tenant_bankconnect_agreement (entity, label, bank_connect_id, status) VALUES (1, 'Tenant agreement', 'BC-TENANT', 'active')");
        $agreementId = (int)$db->last_insert_id('tenant_bankconnect_agreement');
        $db->query("INSERT INTO tenant_bank_account (entity, label, clos) VALUES (1, 'Tenant account', 0)");
        $bankAccountId = (int)$db->last_insert_id('tenant_bank_account');

        $store = new BankAccountMappingStore($db, 'tenant_');
        $mappingId = $store->map(1, $agreementId, $bankAccountId, 9);

        $this->assertSame(1, $mappingId);
        $this->assertSame(1, $db->countRows('tenant_bankconnect_account_mapping'));
        $this->assertSame(0, $db->countRows('llx_bankconnect_account_mapping'));
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
