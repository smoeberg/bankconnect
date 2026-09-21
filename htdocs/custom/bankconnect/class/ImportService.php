<?php
/* ImportService - single tested path for importing camt files into the store. */

require_once __DIR__.'/CamtParser.php';
require_once __DIR__.'/BankTransaction.php';

class ImportService
{
	public const MAX_IMPORT_BYTES = 10 * 1024 * 1024;

	/** @var BankConnectStore */
	private $store;

	/** @var CamtParser */
	private $parser;

	public function __construct(BankConnectStore $store, ?CamtParser $parser = null)
	{
		$this->store = $store;
		$this->parser = $parser ?? new CamtParser();
	}

	/**
	 * Parse camt XML and upsert each transaction. Dedup counts are exact.
	 * @return array{imported:int, duplicates:int, total:int}
	 */
	public function import(string $xml, int $fkBankAccount = 0, string $sourceFile = 'import'): array
	{
		$txs = $this->parser->parse($xml);
		$imported = 0;
		$duplicates = 0;
		foreach ($txs as $t) {
			$arr = $t instanceof BankTransaction ? [
				'date' => $t->date,
				'amount' => $t->amount,
				'currency' => $t->currency,
				'text' => $t->text,
				'reference' => $t->reference,
				'counterparty' => $t->counterparty,
				'acctSvcrRef' => $t->acctSvcrRef,
				'statement_id' => $t->statementId,
				'transaction_id' => $t->transactionId,
				'isReversal' => $t->isReversal,
				'requiresManualReview' => $t->requiresManualReview,
				'hash' => $t->hash,
			] : (array)$t;
			$r = $this->store->upsertTransactionDetailed($arr, $fkBankAccount, $sourceFile);
			if ($r['duplicate']) {
				$duplicates++;
			} else {
				$imported++;
			}
		}
		return ['imported' => $imported, 'duplicates' => $duplicates, 'total' => count($txs)];
	}

	/**
	 * Import from a file path.
	 * @return array{imported:int, duplicates:int, total:int}
	 */
	public function importFile(string $path, int $fkBankAccount = 0, string $sourceFile = 'import'): array
	{
		if ($path === '' || !is_file($path) || !is_readable($path)) {
			throw new RuntimeException('Import file is not readable');
		}

		$size = filesize($path);
		if ($size === false) {
			throw new RuntimeException('Unable to determine import file size');
		}
		if ($size > self::MAX_IMPORT_BYTES) {
			throw new RuntimeException('Import file exceeds the 10 MB limit');
		}

		$xml = file_get_contents($path);
		if ($xml === false) {
			throw new RuntimeException('Unable to read import file');
		}
		return $this->import($xml, $fkBankAccount, $sourceFile);
	}
}
