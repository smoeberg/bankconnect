<?php
/**
 * Real-database concurrency regression for supplier invoice reservations.
 *
 * Uses two independent PDO connections. The parent transaction reserves the
 * invoice while the child calls the real PaymentBatchService; the child must
 * wait for the invoice lock and reject the reservation after the parent commits.
 */
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/Pain001Builder.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/PaymentBatchService.php';

final class BankConnectConcurrencyDoliDB
{
    private PDO $pdo;
    private string $marker;
    private string $lastError = '';

    public function __construct(PDO $pdo, string $marker = '')
    {
        $this->pdo = $pdo;
        $this->marker = $marker;
    }

    public function query(string $sql)
    {
        try {
            if ($this->marker !== '' && strpos($sql, 'SELECT rowid, total_ttc, multicurrency_total_ttc') !== false) {
                file_put_contents($this->marker, 'lock-attempted');
            }
            return $this->pdo->query($sql);
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function fetch_object(&$result)
    {
        if (!$result instanceof PDOStatement) return false;
        $row = $result->fetch(PDO::FETCH_OBJ);
        return $row === false ? false : $row;
    }

    public function escape($value): string
    {
        return substr($this->pdo->quote((string)$value), 1, -1);
    }

    public function lasterror(): string { return $this->lastError; }
    public function begin(): bool { return $this->pdo->beginTransaction(); }
    public function commit(): bool { return $this->pdo->commit(); }
    public function rollback(): bool { return !$this->pdo->inTransaction() || $this->pdo->rollBack(); }
    public function last_insert_id(string $table): int { return (int)$this->pdo->lastInsertId(); }
    public function affected_rows(): int { return 1; }
}

function connectToTestDatabase(): PDO
{
    $dsn = getenv('BANKCONNECT_TEST_DSN') ?: '';
    if ($dsn === '') {
        fwrite(STDERR, "BANKCONNECT_TEST_DSN is required\n");
        exit(2);
    }
    return new PDO($dsn, getenv('BANKCONNECT_TEST_USER') ?: 'root', getenv('BANKCONNECT_TEST_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function sampleConcurrencyBuilder(): Pain001Builder
{
    return (new Pain001Builder())
        ->setInitiatingParty('Concurrency Test ApS')
        ->setDebtor('Concurrency Test ApS', 'DK5089000000012345', 'SXPYDKKK')
        ->setExecutionDate(new DateTimeImmutable('2026-10-20'))
        ->addTransaction([
            'endToEndId' => 'E2E-CONCURRENCY-42',
            'amount' => 100.00,
            'currency' => 'DKK',
            'creditorName' => 'Supplier A/S',
            'creditorIban' => 'DK0689000000054321',
            'fk_facture_fourn' => 42,
        ]);
}

if (($argv[1] ?? '') === 'worker') {
    $marker = getenv('BANKCONNECT_LOCK_MARKER') ?: '';
    $db = new BankConnectConcurrencyDoliDB(connectToTestDatabase(), $marker);
    $service = new PaymentBatchService($db, new Conf(), null);
    try {
        $service->createBatch(sampleConcurrencyBuilder(), 1, 1);
        fwrite(STDERR, "BUG: concurrent worker created a second reservation\n");
        exit(1);
    } catch (BankConnectException $e) {
        if ($e->getPrevious() === null
            || $e->getPrevious()->getMessage() !== 'Supplier invoice already belongs to a payment batch') {
            fwrite(STDERR, "Unexpected rejection: ".$e->getMessage()." / ".($e->getPrevious()?->getMessage() ?? 'no cause')."\n");
            exit(1);
        }
        echo "PASS: concurrent reservation was rejected after lock wait\n";
        exit(0);
    }
}

$pdo = connectToTestDatabase();
$marker = sys_get_temp_dir().'/bankconnect-lock-'.bin2hex(random_bytes(8));
$workerResult = null;
$process = null;
$pipes = [];
try {
    // Isolated minimal schema: the service executes its real SQL against the
    // database engine, including SELECT ... FOR UPDATE and transaction commit.
    $pdo->exec('DROP TABLE IF EXISTS llx_bankconnect_batch_line');
    $pdo->exec('DROP TABLE IF EXISTS llx_bankconnect_batch');
    $pdo->exec('DROP TABLE IF EXISTS llx_paiementfourn_facturefourn');
    $pdo->exec('DROP TABLE IF EXISTS llx_facture_fourn');
    $pdo->exec('CREATE TABLE llx_facture_fourn (
        rowid INT PRIMARY KEY, entity INT NOT NULL, fk_statut INT NOT NULL, paye TINYINT NOT NULL,
        total_ttc DECIMAL(15,2) NOT NULL, multicurrency_total_ttc DECIMAL(15,2) NOT NULL,
        multicurrency_code VARCHAR(3) NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE llx_paiementfourn_facturefourn (
        rowid INT AUTO_INCREMENT PRIMARY KEY, fk_facturefourn INT NOT NULL,
        amount DECIMAL(15,2) NOT NULL, multicurrency_amount DECIMAL(15,2) NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE llx_bankconnect_batch (
        rowid INT AUTO_INCREMENT PRIMARY KEY, entity INT NOT NULL, fk_agreement INT NOT NULL,
        end_to_end_message_id VARCHAR(35) NOT NULL, msg_id VARCHAR(35) NOT NULL,
        status VARCHAR(20) NOT NULL, pain001_xml LONGTEXT NOT NULL, control_sum DECIMAL(15,2) NOT NULL,
        nb_of_txs INT NOT NULL, date_sent DATETIME NULL, date_status DATETIME NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE llx_bankconnect_batch_line (
        rowid INT AUTO_INCREMENT PRIMARY KEY, fk_batch INT NOT NULL, end_to_end_id VARCHAR(35) NOT NULL,
        amount DECIMAL(15,2) NOT NULL, currency VARCHAR(3) NOT NULL,
        fk_facture_fourn INT NULL, fk_facture INT NULL, status VARCHAR(20) NOT NULL,
        INDEX idx_supplier_invoice (fk_facture_fourn), INDEX idx_batch (fk_batch)
    ) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO llx_facture_fourn VALUES (42, 1, 1, 0, 100.00, 100.00, 'DKK')");

    // Hold the invoice row lock as the winning transaction would. Start the
    // actual service in a second process/connection, then commit a reservation.
    $pdo->beginTransaction();
    $locked = $pdo->query('SELECT rowid FROM llx_facture_fourn WHERE rowid = 42 FOR UPDATE')->fetch();
    if (!$locked) throw new RuntimeException('Could not lock seeded invoice');

    $command = [PHP_BINARY, __FILE__, 'worker'];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = array_merge($_ENV, [
        'BANKCONNECT_TEST_DSN' => getenv('BANKCONNECT_TEST_DSN'),
        'BANKCONNECT_TEST_USER' => getenv('BANKCONNECT_TEST_USER') ?: 'root',
        'BANKCONNECT_TEST_PASSWORD' => getenv('BANKCONNECT_TEST_PASSWORD') ?: '',
        'BANKCONNECT_LOCK_MARKER' => $marker,
    ]);
    $process = proc_open($command, $descriptors, $pipes, __DIR__, $env);
    if (!is_resource($process)) throw new RuntimeException('Could not start concurrent worker');
    fclose($pipes[0]);

    $deadline = microtime(true) + 10;
    while (!is_file($marker) && microtime(true) < $deadline) usleep(10000);
    if (!is_file($marker)) throw new RuntimeException('Worker did not reach the invoice-lock query');

    // This is the winner's uncommitted reservation. The worker cannot pass its
    // invoice FOR UPDATE until this transaction commits.
    $pdo->exec("INSERT INTO llx_bankconnect_batch
        (entity, fk_agreement, end_to_end_message_id, msg_id, status, pain001_xml, control_sum, nb_of_txs, date_sent)
        VALUES (1, 1, 'winner-message', 'winner-msg', 'draft', '<Document/>', 100.00, 1, NULL)");
    $batchId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO llx_bankconnect_batch_line
        (fk_batch, end_to_end_id, amount, currency, fk_facture_fourn, fk_facture, status)
        VALUES ($batchId, 'E2E-WINNER', 100.00, 'DKK', 42, NULL, 'draft')");
    $pdo->commit();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $process = null;
    if ($exitCode !== 0) throw new RuntimeException("Worker failed (exit $exitCode): $stdout $stderr");

    $count = (int)$pdo->query('SELECT COUNT(*) FROM llx_bankconnect_batch_line WHERE fk_facture_fourn = 42')->fetchColumn();
    if ($count !== 1) throw new RuntimeException("Expected exactly one invoice reservation; found $count");
    echo "PASS: $stdout";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (is_resource($process)) {
        proc_terminate($process);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($process);
    }
    fwrite(STDERR, "FAIL: ".$e->getMessage()."\n");
    exit(1);
} finally {
    @unlink($marker);
    $pdo->exec('DROP TABLE IF EXISTS llx_bankconnect_batch_line');
    $pdo->exec('DROP TABLE IF EXISTS llx_bankconnect_batch');
    $pdo->exec('DROP TABLE IF EXISTS llx_paiementfourn_facturefourn');
    $pdo->exec('DROP TABLE IF EXISTS llx_facture_fourn');
}
