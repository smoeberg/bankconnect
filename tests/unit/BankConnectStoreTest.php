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

        $hash = hash('sha256', implode('|', [
            1, '', '', $tx['date'], $tx['amount'], $tx['reference'],
            $tx['counterparty'], $tx['text'], $tx['acctSvcrRef'],
        ]));

        // The initial SELECT misses. The DB double injects a competing
        // insert immediately before our INSERT, exercising the unique-key
        // arbitration path without changing production code semantics.
        $this->db->simulateConcurrentTransactionInsert($hash, $tx['date'], $tx['amount'], $tx['reference'], $tx['counterparty']);

        $result = $this->store->upsertTransactionDetailed($tx, 1, 'race.xml');

        $this->assertTrue($result['duplicate']);
        $this->assertSame(1, $result['rowid']);
        $this->assertSame(1, $this->db->countRows('llx_bankconnect_transaction'));
    }
}
