<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/MockDoliDB.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectStore.php';

class BankConnectStoreTest extends TestCase
{
    private $db;
    private $store;

    protected function setUp(): void
    {
        $this->db = new MockDoliDB();
        $this->store = new BankConnectStore($this->db);
    }

    public function testUpsertReturnsExistingTransactionAsDuplicate(): void
    {
        $tx = [
            'date' => '2026-09-19',
            'amount' => -125.50,
            'currency' => 'DKK',
            'reference' => 'INV-100',
            'counterparty' => 'Example ApS',
            'text' => 'Payment',
            'acctSvcrRef' => 'BANK-1',
        ];

        $first = $this->store->upsertTransactionDetailed($tx, 1, 'one.xml');
        $second = $this->store->upsertTransactionDetailed($tx, 1, 'two.xml');

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['rowid'], $second['rowid']);
        $this->assertSame(1, $this->db->countRows('llx_bankconnect_transaction'));
    }

    public function testUpsertUsesAtomicDuplicateKeyPathAfterInitialMiss(): void
    {
        $tx = [
            'date' => '2026-09-19',
            'amount' => 42.00,
            'currency' => 'DKK',
            'reference' => 'RACE-1',
            'counterparty' => 'Concurrent ApS',
            'text' => 'Concurrent import',
            'acctSvcrRef' => 'BANK-RACE-1',
        ];

        $hash = hash('sha256', 'bank-account|1|account-service-reference|'.$tx['acctSvcrRef']);

        // The initial SELECT misses. The DB double injects a competing
        // insert immediately before our INSERT, exercising the unique-key
        // arbitration path without changing production code semantics.
        $this->db->simulateConcurrentTransactionInsert($hash, $tx['date'], $tx['amount'], $tx['reference'], $tx['counterparty']);

        $result = $this->store->upsertTransactionDetailed($tx, 1, 'race.xml');

        $this->assertTrue($result['duplicate']);
        $this->assertSame(1, $result['rowid']);
        $this->assertSame(1, $this->db->countRows('llx_bankconnect_transaction'));
    }

    public function testSameTransactionAcrossStatementsIsImportedOnlyOnce(): void
    {
        $base = [
            'date' => '2026-09-19',
            'amount' => 550.00,
            'currency' => 'DKK',
            'reference' => 'INV-7631',
            'counterparty' => 'TechCorp Retail',
            'text' => 'Customer payment',
            'acctSvcrRef' => 'BANK-STABLE-7631',
            'transaction_id' => 'TX-7631',
        ];

        $notification = $base + ['statement_id' => 'CAMT054-NOTIFICATION'];
        $statement = $base + ['statement_id' => 'CAMT053-STATEMENT'];

        $first = $this->store->upsertTransactionDetailed($notification, 1, 'notification.xml');
        $second = $this->store->upsertTransactionDetailed($statement, 1, 'statement.xml');

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['rowid'], $second['rowid']);
        $this->assertSame(1, $this->db->countRows('llx_bankconnect_transaction'));
    }

    public function testSameBankReferenceOnDifferentAccountsDoesNotCollide(): void
    {
        $tx = [
            'date' => '2026-09-19',
            'amount' => 100.00,
            'currency' => 'DKK',
            'reference' => 'SHARED',
            'counterparty' => 'Example ApS',
            'text' => 'Shared bank reference',
            'acctSvcrRef' => 'BANK-SHARED-1',
        ];

        $first = $this->store->upsertTransactionDetailed($tx, 1, 'account-one.xml');
        $second = $this->store->upsertTransactionDetailed($tx, 2, 'account-two.xml');

        $this->assertFalse($first['duplicate']);
        $this->assertFalse($second['duplicate']);
        $this->assertNotSame($first['rowid'], $second['rowid']);
        $this->assertSame(2, $this->db->countRows('llx_bankconnect_transaction'));
    }

    public function testLegacyStatementScopedRowIsReusedByStableReference(): void
    {
        $this->db->seedLegacyTransaction(
            str_repeat('a', 64),
            'LEGACY-BANK-REF',
            '2026-09-19',
            125.00,
            1
        );

        $result = $this->store->upsertTransactionDetailed([
            'statement_id' => 'A-NEW-STATEMENT',
            'transaction_id' => 'TX-LEGACY',
            'date' => '2026-09-19',
            'amount' => 125.00,
            'currency' => 'DKK',
            'reference' => 'INV-OLD',
            'counterparty' => 'Legacy ApS',
            'text' => 'Already imported',
            'acctSvcrRef' => 'LEGACY-BANK-REF',
        ], 1, 'new-statement.xml');

        $this->assertTrue($result['duplicate']);
        $this->assertSame(1, $result['rowid']);
        $this->assertSame(1, $this->db->countRows('llx_bankconnect_transaction'));
    }
}
