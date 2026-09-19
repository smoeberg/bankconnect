<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectStore.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/ImportService.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankTransaction.php';

class ImportServiceTest extends TestCase
{
	private function makeStore(): BankConnectStore
	{
		return new class('') extends BankConnectStore {
			private $txs = [];
			public function __construct() { /* no db */ }
			public function upsertTransactionDetailed(array $t, int $fkBankAccount, string $sourceFile): array
			{
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
