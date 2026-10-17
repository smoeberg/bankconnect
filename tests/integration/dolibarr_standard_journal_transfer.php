<?php
/**
 * Read-only acceptance probe for a bank entry transferred through Dolibarr's
 * normal Accountancy > Bank journal > Transfer workflow.
 *
 * Preparation:
 *  1. Use a dedicated Dolibarr test database and a dedicated test bank account.
 *  2. Create/import a test bank entry linked to a valid payment record.
 *  3. Transfer it through the normal UI (accountancy/journal/bankjournal.php,
 *     action=writebookkeeping); do not create accounting_bookkeeping rows by hand.
 *  4. Run:
 *     DOLIBARR_MAIN_INC=/path/to/htdocs/main.inc.php \
 *     BANKCONNECT_TEST_BANK_ACCOUNT_ID=123 \
 *     BANKCONNECT_TEST_TRANSFERRED_BANK_ENTRY_ID=456 \
 *     php tests/integration/dolibarr_standard_journal_transfer.php
 *
 * This script is intentionally read-only. It does not create, alter, or delete
 * Dolibarr records. Never run acceptance tests against production data.
 */

$mainInc = getenv('DOLIBARR_MAIN_INC');
if (!$mainInc || !is_file($mainInc)) {
    fwrite(STDERR, "Set DOLIBARR_MAIN_INC to a Dolibarr htdocs/main.inc.php file.\n");
    exit(2);
}

$bankAccountId = (int) (getenv('BANKCONNECT_TEST_BANK_ACCOUNT_ID') ?: 0);
$bankEntryId = (int) (getenv('BANKCONNECT_TEST_TRANSFERRED_BANK_ENTRY_ID') ?: 0);
if ($bankAccountId <= 0 || $bankEntryId <= 0) {
    fwrite(STDERR, "Set BANKCONNECT_TEST_BANK_ACCOUNT_ID and BANKCONNECT_TEST_TRANSFERRED_BANK_ENTRY_ID.\n");
    exit(2);
}

require $mainInc;

if (!defined('DOL_DOCUMENT_ROOT') || !isset($db) || !is_object($db)) {
    fwrite(STDERR, "Dolibarr main.inc.php did not initialize DOL_DOCUMENT_ROOT and DB.\n");
    exit(2);
}

require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankJournalHandoffService.php';

function bcStandardTransferAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function bcStandardTransferOneRow($db, string $sql)
{
    $result = $db->query($sql);
    if (!$result) {
        throw new RuntimeException('Dolibarr SQL query failed: '.$db->lasterror());
    }
    $row = $db->fetch_object($result);
    if (!$row) {
        throw new RuntimeException('Expected a database row; query returned none.');
    }
    return $row;
}

$prefix = MAIN_DB_PREFIX;
try {
    $account = new Account($db);
    bcStandardTransferAssert($account->fetch($bankAccountId) > 0, "Test bank account {$bankAccountId} was not found.");
    bcStandardTransferAssert((int) $account->fk_accountancy_journal > 0, 'Test bank account has no accounting journal assigned.');

    $bank = bcStandardTransferOneRow(
        $db,
        'SELECT b.rowid, b.fk_account, b.amount, b.amount_main_currency, ba.entity AS account_entity FROM '.$prefix.'bank b'
        .' JOIN '.$prefix.'bank_account ba ON ba.rowid=b.fk_account'
        .' WHERE b.rowid='.(int) $bankEntryId.' AND b.fk_account='.(int) $bankAccountId
        .' AND ba.entity='.(int) $conf->entity.' LIMIT 1'
    );
    bcStandardTransferAssert((int) $bank->fk_account === $bankAccountId, 'Bank entry belongs to a different bank account.');
    bcStandardTransferAssert((int) $bank->account_entity === (int) $conf->entity, 'Bank account belongs to a different Dolibarr entity.');

    $ledger = bcStandardTransferOneRow(
        $db,
        "SELECT COUNT(rowid) AS line_count, COALESCE(SUM(debit), 0) AS debit_total,"
        ." COALESCE(SUM(credit), 0) AS credit_total"
        ." FROM ".$prefix."accounting_bookkeeping WHERE entity=".(int) $conf->entity
        ." AND doc_type='bank' AND fk_doc=".(int) $bankEntryId
    );
    $lineCount = (int) $ledger->line_count;
    $debit = (float) $ledger->debit_total;
    $credit = (float) $ledger->credit_total;
    $amount = !empty($bank->amount_main_currency) ? (float) $bank->amount_main_currency : (float) $bank->amount;
    $expected = abs($amount);
    $tolerance = 0.00001;

    bcStandardTransferAssert($lineCount >= 2, "Expected at least two bookkeeping lines from standard transfer; found {$lineCount}.");
    bcStandardTransferAssert($debit > $tolerance && $credit > $tolerance, 'Standard transfer must contain both debit and credit amounts.');
    bcStandardTransferAssert(abs($debit - $credit) <= $tolerance, 'Standard transfer bookkeeping totals are not balanced.');
    bcStandardTransferAssert(abs($debit - $expected) <= $tolerance, sprintf('Debit total %.5f does not match absolute bank amount %.5f.', $debit, $expected));
    bcStandardTransferAssert(abs($credit - $expected) <= $tolerance, sprintf('Credit total %.5f does not match absolute bank amount %.5f.', $credit, $expected));

    $handoff = new BankJournalHandoffService($db);
    $status = $handoff->status($bankEntryId, $bankAccountId, (int) $conf->entity);
    bcStandardTransferAssert($status['state'] === 'transferred' && $status['transferred'] === true,
        'BankConnect handoff status should be transferred; got state='.$status['state'].'.');
    bcStandardTransferAssert((int) $status['journal_id'] === (int) $account->fk_accountancy_journal,
        'BankConnect reported a different accounting journal.');
    bcStandardTransferAssert($status['payment_id'] > 0 && $status['payment_type'] !== '',
        'BankConnect did not resolve the bank entry payment link.');

    printf(
        "PASS: normal Dolibarr journal transfer accepted by BankConnect; bank_entry=%d; journal=%d; payment_type=%s; payment_id=%d; lines=%d; debit=%.2f; credit=%.2f; signed_bank_amount=%.2f\n",
        $bankEntryId,
        $status['journal_id'],
        $status['payment_type'],
        $status['payment_id'],
        $lineCount,
        $debit,
        $credit,
        (float) $bank->amount
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: '.$e->getMessage()."\n");
    exit(1);
}
