<?php
/**
 * Real-database concurrency regression for unknown batch reconciliation.
 *
 * Two independent PDO connections race to reconcile the same UNKNOWN batch:
 * the parent transaction holds the batch-row lock (as a slow refresh or UI
 * reader would), worker A reconciles with an ACSC report and worker B with a
 * stale RJCT report. The compare-and-set guard in updateBatchStatus() must
 * reject exactly one worker and roll its line updates back, so the persisted
 * state stays consistent with a single winning report.
 *
 * Requires a real MariaDB/MySQL server. Configure via environment variables:
 *   BANKCONNECT_DSN        PDO DSN, e.g. mysql:dbname=bankconnect;host=127.0.0.1
 *   BANKCONNECT_USER       DB user
 *   BANKCONNECT_PASS       DB password
 * Exits 0 with PASS lines on success, 1 with FAIL lines on regression.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__, 2);
require_once $root.'/htdocs/custom/bankconnect/class/PaymentBatchService.php';
require_once $root.'/htdocs/custom/bankconnect/class/PaymentStateMachine.php';
require_once $root.'/htdocs/custom/bankconnect/class/Pain001Builder.php';

$dsn = getenv('BANKCONNECT_DSN');
$user = getenv('BANKCONNECT_USER') ?: 'root';
$pass = getenv('BANKCONNECT_PASS') ?: '';
if (!$dsn) {
    fwrite(STDERR, "BANKCONNECT_DSN is required\n");
    exit(1);
}

// Safety guard: this test drops and recreates the BankConnect tables. Refuse to
// run against a DSN that does not clearly name a dedicated test database.
if (!preg_match('/(?:dbname|Database)=([A-Za-z0-9_]+)/i', $dsn, $m) || stripos($m[1], 'test') === false) {
    fwrite(STDERR, "Refusing to run: BANKCONNECT_DSN must point at a database whose name contains 'test' (this script DROPs and recreates llx_bankconnect_* tables)\n");
    exit(1);
}

$markers = [
    'A' => [getenv('BANKCONNECT_MARKER_A') ?: '/tmp/bk-marker-a', 'UPDATE llx_bankconnect_batch SET', true],
    'B' => [getenv('BANKCONNECT_MARKER_B') ?: '/tmp/bk-marker-b', 'UPDATE llx_bankconnect_batch_line', true],
];
foreach ($markers as $m) { @unlink($m[0]); }
// Parent wipes both. A worker only wipes its own marker: another worker may
// already have signalled, and wiping both here would erase its 'x'.
$role = getenv('BANKCONNECT_WORKER_ROLE') ?: '';
if ($role !== '') {
    @unlink($markers[$role][0]);
    file_put_contents($markers[$role][0], '');
} else {
    file_put_contents('/tmp/bk-marker-a', '');
    file_put_contents('/tmp/bk-marker-b', '');
}

/** Null client: every worker injects its pain.002 directly. */
class NullClient
{
    public function __construct($conf) {}
    public function getStatus(string $serviceHeaderXml): string { return ''; }
}

/** Instrumented db wrapper that emits real rowCount() for CAS detection. */
class ConcurrencyPdoDb
{
    private $pdo;
    private $lastStmt;

    public function __construct($pdo) { $this->pdo = $pdo; }

    public function query($sql)
    {
        if (isset($GLOBALS['active_marker'])) {
            signalMarkerIf($sql, $GLOBALS['active_marker']);
        }
        if (getenv('BANKCONNECT_DEBUG_SQL')) file_put_contents('/tmp/bk-sql-'.(getenv('BANKCONNECT_WORKER_ROLE') ?: 'parent').'.log', sprintf("[%s] %.6f %s\n", getenv('BANKCONNECT_WORKER_ROLE') ?: 'P', microtime(true), substr($sql, 0, 140)), FILE_APPEND);
        $this->lastStmt = $this->pdo->query($sql);
        if ($this->lastStmt === false) return false;
        return $this;
    }

    public function fetch_object($res)
    {
        return $this->lastStmt ? $this->lastStmt->fetch(PDO::FETCH_OBJ) : null;
    }

    public function affected_rows()
    {
        return $this->lastStmt ? $this->lastStmt->rowCount() : 0;
    }

    public function escape($s) { return addslashes((string)$s); }

    public function begin() { $this->pdo->beginTransaction(); return true; }
    public function commit() { $this->pdo->commit(); return true; }
    public function rollback() { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); return true; }
}

function signalMarkerIf(string $sql, array $marker): void
{
    if ($marker[2] && strpos($sql, $marker[1]) !== false) {
        @file_put_contents($marker[0], 'x');
    }
}

function fail(string $message): void
{
    fwrite(STDOUT, "FAIL: {$message}\n");
    exit(1);
}

/** Kill any workers still running so a failed run cannot leak locks into the next one. */
function killWorkers(?array &$processes, array &$pipes): void
{
    foreach ($processes as $process) {
        if (is_resource($process)) {
            proc_terminate($process, 9);
        }
    }
    foreach ($pipes as $ps) {
        foreach ([1, 2] as $fd) {
            if (is_resource($ps[$fd] ?? null)) {
                fclose($ps[$fd]);
            }
        }
    }
}
$processes = [];
$pipes = [];
register_shutdown_function(function () use (&$processes, &$pipes) {
    killWorkers($processes, $pipes);
});

function pass(string $message): void
{
    fwrite(STDOUT, "PASS: {$message}\n");
    exit(0);
}

$parentPdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 20,
]);

function installSchema(PDO $pdo): void
{
    $pdo->exec('DROP TABLE IF EXISTS llx_bankconnect_batch_line');
    $pdo->exec('DROP TABLE IF EXISTS llx_bankconnect_batch');
    $pdo->exec('CREATE TABLE llx_bankconnect_batch (
        rowid INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        entity INT NOT NULL DEFAULT 1,
        fk_agreement INT NULL,
        msg_id VARCHAR(64) NULL,
        end_to_end_message_id VARCHAR(64) NULL,
        nb_of_txs INT NOT NULL DEFAULT 0,
        control_sum DECIMAL(20,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT "draft",
        pain001_xml LONGTEXT NULL,
        response_code VARCHAR(32) NULL,
        correlation_id VARCHAR(64) NULL,
        message TEXT NULL,
        date_sent DATETIME NULL,
        date_status DATETIME NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE llx_bankconnect_batch_line (
        rowid INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        fk_batch INT UNSIGNED NOT NULL,
        end_to_end_id VARCHAR(64) NOT NULL,
        fk_facture_fourn INT NULL,
        fk_facture INT NULL,
        amount DECIMAL(20,4) NOT NULL DEFAULT 0,
        currency VARCHAR(8) NULL,
        status VARCHAR(20) NOT NULL DEFAULT "draft",
        pain002_status VARCHAR(10) NULL,
        status_reason VARCHAR(255) NULL,
        requires_manual_review TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
}

$role = getenv('BANKCONNECT_WORKER_ROLE') ?: '';
if ($role === '') {
    installSchema($parentPdo);
    $parentPdo->exec("INSERT INTO llx_bankconnect_batch
    (entity, fk_agreement, msg_id, end_to_end_message_id, nb_of_txs, control_sum, status, pain001_xml, date_sent, date_status)
    VALUES (1, 1, 'CONC-MSG-42', 'CONC-MSG-42', 1, 100.00, 'unknown', '<Document/>', NOW(), NOW())");
    $parentPdo->exec("INSERT INTO llx_bankconnect_batch_line
    (fk_batch, end_to_end_id, amount, currency, status, pain002_status)
    VALUES (1, 'E2E-CONCURRENCY-42', 100.00, 'DKK', 'unknown', NULL)");
}

$marker = ($role !== '' ? $markers[$role] ?? null : null);
if ($role !== '' && $marker === null) {
    fwrite(STDERR, "BANKCONNECT_WORKER_ROLE must be A or B\n");
    exit(1);
}

$db = new ConcurrencyPdoDb(new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 20,
]));
if ($marker !== null) {
    $GLOBALS['active_marker'] = $marker;
}
$svc = new PaymentBatchService($db, new Conf(), null);

function pain002For(string $msgId, string $txStatus): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.002.001.03">'
        .'<CstmrPmtStsRpt><GrpHdr><MsgId>ST-'.$msgId.'</MsgId></GrpHdr>'
        .'<OrgnlGrpInfAndSts><OrgnlMsgId>'.$msgId.'</OrgnlMsgId><GrpSts>'.($txStatus === 'ACSC' ? 'ACCP' : 'RJCT').'</GrpSts></OrgnlGrpInfAndSts>'
        .'<OrgnlPmtInfAndSts><TxInfAndSts><OrgnlEndToEndId>E2E-CONCURRENCY-42</OrgnlEndToEndId><TxSts>'.$txStatus.'</TxSts></TxInfAndSts></OrgnlPmtInfAndSts>'
        .'</CstmrPmtStsRpt></Document>';
}

if ($role === 'A') {
    // Parent holds the batch-row lock; this CAS update must block on it.
    try {
        $svc->resolveUnknownBatch(1, '<serviceHeader/>', pain002For('CONC-MSG-42', 'ACSC'));
    } catch (Throwable $e) {
        fail('worker A: unexpected rejection: '.$e->getMessage());
    }
    pass('worker A: reconciled');
}

if ($role === 'B') {
    try {
        $svc->resolveUnknownBatch(1, '<serviceHeader/>', pain002For('CONC-MSG-42', 'RJCT'));
        fail('worker B: stale RJCT report unexpectedly reconciled the batch');
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (strpos($message, 'changed concurrently') !== false) {
            pass('worker B: stale report rejected by compare-and-set guard');
        }
        fail('worker B: unexpected error: '.$message);
    }
}

// ---- Parent orchestration (default when BANKCONNECT_WORKER_ROLE is unset) ----
if ($role === '') {
    // Parent holds the batch row FOR UPDATE, exactly like a slow reader or a
    // concurrent reconciliation already in flight. Workers block on it.
    // Reset must be committed before the lock, so workers' MVCC reads see
    // a committed 'unknown' status rather than their snapshot of an older state.
    $parentPdo->exec("UPDATE llx_bankconnect_batch SET status = 'unknown', message = NULL, response_code = NULL WHERE rowid = 1");
    $parentPdo->exec("UPDATE llx_bankconnect_batch_line SET status = 'unknown', pain002_status = NULL WHERE rowid = 1");
    $parentPdo->exec('SET autocommit = 0');
    $parentPdo->query('SELECT rowid FROM llx_bankconnect_batch WHERE rowid = 1 FOR UPDATE');

    // Worker processes. A barrier keeps the race deterministic: parent
    // locks the batch row, resets are committed, then A is parked on its
    // CAS, then B is spawned and parked on A's line lock.
    $processes = [];
    $pipes = [];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $spawn = function (string $workerRole) use (&$processes, &$pipes, $descriptors, $markers, $dsn, $user, $pass) {
        $env = array_merge(getenv(), [
            'BANKCONNECT_WORKER_ROLE' => $workerRole,
            'BANKCONNECT_MARKER_A' => $markers['A'][0],
            'BANKCONNECT_MARKER_B' => $markers['B'][0],
            'BANKCONNECT_DSN' => $dsn,
            'BANKCONNECT_USER' => $user,
            'BANKCONNECT_PASS' => $pass,
        ]);
        $processes[$workerRole] = proc_open(
            PHP_BINARY.' '.escapeshellarg(__FILE__),
            $descriptors,
            $pipes[$workerRole],
            null,
            $env
        );
        if (!is_resource($processes[$workerRole])) {
            fail("could not start worker {$workerRole}");
        }
        fclose($pipes[$workerRole][0]);
    };

    // Spawn A and wait until it is parked on its CAS. Its marker fires only
    // after A's line update has taken the line-row lock inside its
    // transaction, so ordering of the race is guaranteed, not lucky.
    $spawn('A');
    $deadline = microtime(true) + 10;
    $waitMarker = function (string $workerRole) use ($markers, $deadline) {
        $markerPath = $markers[$workerRole][0];
        while (!file_exists($markerPath) || filesize($markerPath) === 0) {
            clearstatcache(true, $markerPath);
            if (microtime(true) > $deadline) {
                fail("worker {$workerRole} never reached its blocking statement");
            }
            usleep(10000);
        }
    };
    $waitMarker('A');

    // Worker B reconciles with a stale RJCT report: it starts after A is
    // parked, so it blocks on the line-row lock held by A's in-flight
    // transaction. Its reads see the still-uncommitted state of A.
    $spawn('B');
    $waitMarker('B');

    // Parent releases the batch-row lock: worker A's CAS wins, worker B's
    // stale RJCT report must be rejected and rolled back.
    $parentPdo->commit();

    $results = [];
    foreach ($processes as $workerRole => $process) {
        // Poll until the process is observed running===false; after the process has
        // been reaped, proc_get_status may report exitcode -1 (classic PHP pitfall).
        $exit = null;
        $status = false;
        for ($t = 0; $t < 3000; $t++) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exit = $status['exitcode'];
                break;
            }
            if ($t % 10 === 0) {
                // Drain both pipes so the child cannot deadlock on a full buffer.
                @fread($pipes[$workerRole][1], 8192);
                @fread($pipes[$workerRole][2], 8192);
            }
            usleep(2000);
        }
        if ($exit === null) {
            $status = proc_get_status($process);
            $exit = $status['running'] ? 1 : ($status['exitcode'] >= 0 ? $status['exitcode'] : 1);
        }
        $out = stream_get_contents($pipes[$workerRole][1]);
        $err = stream_get_contents($pipes[$workerRole][2]);
        fclose($pipes[$workerRole][1]);
        fclose($pipes[$workerRole][2]);
        $results[$workerRole] = [$exit, trim($out."\n".$err)];
        proc_close($process);
        echo "[worker {$workerRole}] (exit={$results[$workerRole][0]}) {$results[$workerRole][1]}\n";
    }

    if ($results['A'][0] !== 0) {
        fail('worker A (first reconciliation) must succeed; got: '.($results['A'][1] ?: '(no output)'));
    }
    if ($results['B'][0] !== 0) {
        fail('worker B must detect the concurrent change and roll back; got: '.($results['B'][1] ?: '(no output)'));
    }

    $batch = $parentPdo->query('SELECT status, message, date_status FROM llx_bankconnect_batch WHERE rowid = 1')->fetch(PDO::FETCH_ASSOC);
    $line = $parentPdo->query('SELECT status, pain002_status, status_reason, requires_manual_review FROM llx_bankconnect_batch_line WHERE fk_batch = 1')->fetch(PDO::FETCH_ASSOC);

    if ($batch['status'] !== 'accepted') {
        fail('batch must be accepted after the winning reconciliation; got '.var_export($batch['status'], true));
    }
    if ($line['pain002_status'] !== 'ACSC') {
        fail('line must carry the winning ACSC report; got '.var_export($line['pain002_status'], true));
    }
    // The losing process (RJCT) must not leave its rejection reason behind.
    if ($line['status_reason'] !== null) {
        fail('losing RJCT reason must be rolled back; status_reason = '.var_export($line['status_reason'], true));
    }
    if ($line['status'] !== 'accepted') {
        fail('line status must be accepted; got '.var_export($line['status'], true));
    }
    if ($line['requires_manual_review'] != 0) {
        fail('accepted line must not require manual review');
    }
    if ($batch['message'] === null || $batch['message'] === '') {
        fail('winning reconciliation must leave its message on the batch');
    }
    if ($batch['date_status'] === null) {
        fail('winning reconciliation must stamp date_status');
    }

    pass('CAS guard rejected the stale RJCT report; persisted state reflects only the winning ACSC report');
}
