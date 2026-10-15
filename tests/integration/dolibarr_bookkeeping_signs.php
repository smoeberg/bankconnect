<?php
/**
 * Opt-in integration test for CAMT signed amounts against a real Dolibarr 24 runtime.
 *
 * Run from the BankConnect repository root:
 *   DOLIBARR_MAIN_INC=/var/www/dolibarr/htdocs/main.inc.php \
 *   BANKCONNECT_TEST_BANK_ACCOUNT_ID=1 \
 *   php tests/integration/dolibarr_bookkeeping_signs.php
 *
 * The selected bank account must be a test account with an accounting journal.
 * This script creates temporary bank entries and bookkeeping rows, verifies the
 * persisted values, then removes only the rows it created. Never point it at a
 * production database.
 *
 * This exercises Dolibarr's Account::addline() and BookKeeping::createFromValues()
 * against the real database. It does not automate the web UI's journal-transfer
 * screen; that remains a separate end-to-end acceptance check.
 */

$mainInc = getenv('DOLIBARR_MAIN_INC');
if (!$mainInc || !is_file($mainInc)) {
    fwrite(STDERR, "Set DOLIBARR_MAIN_INC to a Dolibarr htdocs/main.inc.php file.\n");
    exit(2);
}

$bankAccountId = (int) (getenv('BANKCONNECT_TEST_BANK_ACCOUNT_ID') ?: 0);
if ($bankAccountId <= 0) {
    fwrite(STDERR, "Set BANKCONNECT_TEST_BANK_ACCOUNT_ID to a dedicated test bank account.\n");
    exit(2);
}

require $mainInc;

if (!defined('DOL_DOCUMENT_ROOT') || !isset($db) || !is_object($db)) {
    fwrite(STDERR, "Dolibarr main.inc.php did not initialize DOL_DOCUMENT_ROOT and DB.\n");
    exit(2);
}

require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/bookkeeping.class.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/DolibarrBankEntryService.php';

$userId = (int) (getenv('BANKCONNECT_TEST_USER_ID') ?: 1);
$user = new User($db);
if ($user->fetch($userId) <= 0) {
    fwrite(STDERR, "Could not fetch Dolibarr test user ID {$userId}.\n");
    exit(2);
}

global $conf, $langs;
$prefix = MAIN_DB_PREFIX;
$bankEntryIds = [];
$bookkeepingIds = [];
$checks = 0;

function bcAssert(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function bcQueryRows($db, string $sql): array
{
    $result = $db->query($sql);
    if (!$result) {
        throw new RuntimeException('Dolibarr SQL query failed: '.$db->lasterror().' SQL='.$sql);
    }
    $rows = [];
    while ($row = $db->fetch_object($result)) {
        $rows[] = $row;
    }
    return $rows;
}

try {
    $account = new Account($db);
    bcAssert($account->fetch($bankAccountId) > 0, "Test bank account {$bankAccountId} was not found.");
    bcAssert(!empty($account->fk_accountancy_journal), 'Test bank account must have an accounting journal assigned.');

    $amounts = [1234.56, -1234.56];
    $date = dol_mktime(12, 0, 0, (int) dol_print_date(dol_now(), '%m'), (int) dol_print_date(dol_now(), '%d'), (int) dol_print_date(dol_now(), '%Y'));
    $dateText = dol_print_date($date, '%Y-%m-%d');
    $runToken = 'BCIT-'.strtoupper(bin2hex(random_bytes(6)));
    $bankService = new DolibarrBankEntryService($db);

    foreach ($amounts as $index => $amount) {
        $ref = $runToken.'-'.$index;
        $bankId = $bankService->create([
            'date' => $dateText,
            'amount' => $amount,
            'text' => 'BankConnect signed amount integration test '.$ref,
            'statement_id' => $runToken,
            'transaction_id' => $ref,
            'acctSvcrRef' => $ref,
            'hash' => hash('sha256', $ref),
        ], $bankAccountId, $user);
        $bankEntryIds[] = $bankId;

        $bankRows = bcQueryRows(
            $db,
            'SELECT rowid, amount, fk_account, note_private FROM '.$prefix.'bank WHERE rowid='.(int) $bankId.' LIMIT 1'
        );
        bcAssert(count($bankRows) === 1, "Dolibarr bank row {$bankId} was not persisted.");
        bcAssert((int) $bankRows[0]->fk_account === $bankAccountId, 'Bank entry is linked to the wrong bank account.');
        bcAssert(abs((float) $bankRows[0]->amount - $amount) < 0.00001, 'Persisted llx_bank amount/sign differs from CAMT amount.');
        bcAssert(str_starts_with((string) $bankRows[0]->note_private, 'BANKCONNECT:'), 'BankConnect idempotency marker was not persisted.');

        // Create ledger rows through Dolibarr's own bookkeeping domain class.
        // The bank-side line follows the CAMT sign; the counter-line balances it.
        foreach ([
            ['account' => 'BCIT-BANK', 'label' => 'BankConnect integration bank line '.$ref, 'amount' => $amount],
            ['account' => 'BCIT-COUNTER', 'label' => 'BankConnect integration counter line '.$ref, 'amount' => -$amount],
        ] as $line) {
            $bookkeeping = new BookKeeping($db);
            $result = $bookkeeping->createFromValues(
                $date,
                $ref,
                'bank',
                $bankId,
                0,
                $line['account'],
                'BankConnect integration test',
                $line['label'],
                $line['amount'],
                (string) $account->fk_accountancy_journal,
                'BankConnect integration test',
                ''
            );
            if ($result < 0) {
                throw new RuntimeException('Dolibarr BookKeeping::createFromValues failed: '.implode('; ', $bookkeeping->errors ?? []));
            }
            bcAssert((int) $bookkeeping->id > 0, 'Dolibarr did not return a persisted bookkeeping row ID.');
            $bookkeepingIds[] = (int) $bookkeeping->id;
        }

        $ledgerRows = bcQueryRows(
            $db,
            'SELECT rowid, fk_doc, numero_compte, debit, credit, montant, sens'
            .' FROM '.$prefix.'accounting_bookkeeping'
            .' WHERE doc_type=\'bank\' AND fk_doc='.(int) $bankId
            .' AND label_operation LIKE \'BankConnect integration %\' ORDER BY rowid ASC'
        );
        bcAssert(count($ledgerRows) === 2, 'Expected exactly two persisted bookkeeping rows for the test bank entry.');

        $bankLedger = null;
        $debitTotal = 0.0;
        $creditTotal = 0.0;
        foreach ($ledgerRows as $line) {
            if ($line->numero_compte === 'BCIT-BANK') {
                $bankLedger = $line;
            }
            $debitTotal += (float) $line->debit;
            $creditTotal += (float) $line->credit;
        }

        bcAssert($bankLedger !== null, 'Missing bank-side bookkeeping row.');
        bcAssert(abs((float) $bankLedger->montant - $amount) < 0.00001, 'Bookkeeping amount/sign differs from the signed CAMT amount.');
        if ($amount > 0) {
            bcAssert(abs((float) $bankLedger->debit - $amount) < 0.00001 && abs((float) $bankLedger->credit) < 0.00001 && $bankLedger->sens === 'D', 'Positive CAMT amount must be a debit on the bank-side bookkeeping line.');
        } else {
            bcAssert(abs((float) $bankLedger->credit - abs($amount)) < 0.00001 && abs((float) $bankLedger->debit) < 0.00001 && $bankLedger->sens === 'C', 'Negative CAMT amount must be a credit on the bank-side bookkeeping line.');
        }
        bcAssert(abs($debitTotal - $creditTotal) < 0.00001, 'Bookkeeping debit and credit totals do not balance.');

        echo sprintf(
            "PASS %s: bank amount=%0.2f; bank ledger debit=%0.2f credit=%0.2f; balanced total=%0.2f\n",
            $ref,
            (float) $bankRows[0]->amount,
            (float) $bankLedger->debit,
            (float) $bankLedger->credit,
            $debitTotal
        );
    }

    echo "PASS: {$checks} assertions against Dolibarr's live database.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: '.$e->getMessage()."\n");
    exitCode:
    $exitCode = 1;
} finally {
    // Cleanup is restricted to IDs created during this run.
    if ($bookkeepingIds) {
        $db->query('DELETE FROM '.$prefix.'accounting_bookkeeping WHERE rowid IN ('.implode(',', array_map('intval', $bookkeepingIds)).')');
    }
    if ($bankEntryIds) {
        $db->query('DELETE FROM '.$prefix.'bank WHERE rowid IN ('.implode(',', array_map('intval', $bankEntryIds)).')');
    }
}

if (isset($exitCode)) {
    exit($exitCode);
}
