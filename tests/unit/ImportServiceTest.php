<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectStore.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/ImportService.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankTransaction.php';

class ImportServiceTest extends TestCase
{
	private function makeStore(?array &$captured = null): BankConnectStore
	{
		return new class($captured) extends BankConnectStore {
			private $txs = [];
			private $captured;
			public function __construct(&$captured) { $this->captured =& $captured; }
			public function upsertTransactionDetailed(array $t, int $fkBankAccount, string $sourceFile): array
			{
				if ($this->captured !== null) {
					$this->captured[] = $t;
				}
				$hash = $t['hash'] !== '' ? $t['hash'] : hash('sha256', implode('|', [$t['date'], $t['amount'], $t['reference'] ?? '', $t['counterparty'] ?? '']));
				if (isset($this->txs[$hash])) {
					return ['rowid' => $this->txs[$hash], 'duplicate' => true];
				}
				$this->txs[$hash] = count($this->txs) + 1;
				return ['rowid' => $this->txs[$hash], 'duplicate' => false];
			}
		};
	}

	public function testCountsNewAndDuplicatesPerImport(): void
	{
		$service = new ImportService($this->makeStore());
		$xml = $this->camt([[1, '2026-09-01'], [2, '2026-09-02']]);
		$r = $service->import($xml);
		$this->assertSame(['imported' => 2, 'duplicates' => 0, 'total' => 2], $r);
	}

	public function testSecondImportCountsDuplicates(): void
	{
		$service = new ImportService($this->makeStore());
		$xml = $this->camt([[1, '2026-09-01']]);
		$service->import($xml);
		$r = $service->import($this->camt([[1, '2026-09-01'], [3, '2026-09-03']]));
		$this->assertSame(1, $r['imported']);
		$this->assertSame(1, $r['duplicates']);
		$this->assertSame(2, $r['total']);
	}

	public function testImportPropagatesManualReviewAndReversalProvenance(): void
	{
		$captured = [];
		$tx = new BankTransaction();
		$tx->date = '2026-09-19';
		$tx->amount = 18900.0;
		$tx->currency = 'DKK';
		$tx->text = 'Samleindbetaling';
		$tx->acctSvcrRef = 'BANK-123';
		$tx->isReversal = true;
		$tx->requiresManualReview = true;
		$tx->hash = hash('sha256', 'manual-review');

		$parser = new class($tx) {
			private $tx;
			public function __construct($tx) { $this->tx = $tx; }
			public function parse(string $xml): array { return [$this->tx]; }
		};

		$service = new ImportService($this->makeStore($captured), $parser);
		$result = $service->import('<Document/>');

		$this->assertSame(1, $result['imported']);
		$this->assertCount(1, $captured);
		$this->assertTrue($captured[0]['requiresManualReview']);
		$this->assertTrue($captured[0]['isReversal']);
		$this->assertSame('BANK-123', $captured[0]['acctSvcrRef']);
	}

	public function testImportFileRejectsOversizedFile(): void
	{
		$path = tempnam(sys_get_temp_dir(), 'bankconnect-import-');
		$this->assertNotFalse($path);

		try {
			$this->assertNotFalse(file_put_contents($path, str_repeat('x', ImportService::MAX_IMPORT_BYTES + 1)));
			$this->expectException(RuntimeException::class);
			$this->expectExceptionMessage('10 MB limit');
			(new ImportService($this->makeStore()))->importFile($path);
		} finally {
			@unlink($path);
		}
	}

	public function testImportFileRejectsMissingFile(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('not readable');
		(new ImportService($this->makeStore()))->importFile('/definitely/not/a/file.xml');
	}

	public function testEmptyXmlThrowsSingleExceptionType(): void
	{
		$this->expectException(RuntimeException::class);
		(new ImportService($this->makeStore()))->import('');
	}

	private function camt(array $entries): string
	{
		$ntries = '';
		foreach ($entries as [$id, $date]) {
			$ntries .= "<Ntry><BookgDt><Dt>{$date}</Dt></BookgDt><Amt Ccy=\"DKK\">{$id}.00</Amt><CdtDbtInd>CRDT</CdtDbtInd></Ntry>";
		}
		return "<Document><BkToCstmrAcctRpt><Rpt>{$ntries}</Rpt></BkToCstmrAcctRpt></Document>";
	}
}
