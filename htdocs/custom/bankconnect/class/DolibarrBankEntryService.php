<?php
/**
 * Adapter for creating real Dolibarr bank entries from imported transactions.
 *
 * This is the only BankConnect class allowed to create an llx_bank row. It uses
 * Dolibarr's Account domain object and never writes directly to llx_bank.
 */
class DolibarrBankEntryService
{
	private $db;
	private $accountFactory;

	public function __construct($db, ?callable $accountFactory = null)
	{
		$this->db = $db;
		$this->accountFactory = $accountFactory;
	}

	/**
	 * @param array<string,mixed> $transaction Normalized BankConnect transaction
	 * @param int                 $bankAccountId Dolibarr bank account id
	 * @param object              $user Dolibarr user creating the entry
	 * @return int Dolibarr bank entry rowid
	 */
	public function create(array $transaction, int $bankAccountId, $user): int
	{
		if ($bankAccountId <= 0) {
			throw new RuntimeException('BankConnect: invalid Dolibarr bank account');
		}
		if (!is_object($user) || empty($user->id)) {
			throw new RuntimeException('BankConnect: a Dolibarr user is required to create a bank entry');
		}

		$account = $this->createAccountObject();
		if ($account->fetch($bankAccountId) <= 0) {
			throw new RuntimeException('BankConnect: Dolibarr bank account not found: '.$bankAccountId);
		}

		$date = $this->toTimestamp((string)($transaction['date'] ?? ''));
		$amount = (float)($transaction['amount'] ?? 0);
		$reference = (string)($transaction['acctSvcrRef'] ?? $transaction['reference'] ?? '');
		$statementId = (string)($transaction['statement_id'] ?? '');
		$label = $this->buildLabel($transaction);
		$sender = (string)($transaction['counterparty'] ?? '');
		$marker = $this->idempotencyMarker($transaction);
		$existingId = $this->findExistingEntry($bankAccountId, $marker);
		if ($existingId > 0) {
			return $existingId;
		}

		$bankEntryId = $account->addline(
			$date,
			'VIR',
			$label,
			$amount,
			$reference,
			0,
			$user,
			$sender,
			'',
			'',
			$date,
			$statementId,
			null,
			$marker
		);
		if ($bankEntryId <= 0) {
			$error = !empty($account->error) ? ': '.$account->error : '';
			throw new RuntimeException('BankConnect: Dolibarr Account::addline failed'.$error);
		}

		return (int)$bankEntryId;
	}

	/** @param array<string,mixed> $transaction */
	private function idempotencyMarker(array $transaction): string
	{
		$hash = trim((string)($transaction['hash'] ?? ''));
		if ($hash === '') {
			$hash = hash('sha256', implode('|', [
				$transaction['statement_id'] ?? '',
				$transaction['transaction_id'] ?? '',
				$transaction['date'] ?? '',
				$transaction['amount'] ?? '',
				$transaction['acctSvcrRef'] ?? '',
			]));
		}
		return 'BANKCONNECT:'.$hash;
	}

	private function findExistingEntry(int $bankAccountId, string $marker): int
	{
		if (!method_exists($this->db, 'query')) {
			return 0;
		}
		$prefix = defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_';
		$sql = 'SELECT rowid FROM '.$prefix.'bank WHERE fk_account='.(int)$bankAccountId
			." AND note_private='".$this->db->escape($marker)."' ORDER BY rowid ASC LIMIT 1";
		$res = $this->db->query($sql);
		$row = $res ? $this->db->fetch_object($res) : false;
		return $row ? (int)$row->rowid : 0;
	}

	private function createAccountObject()
	{
		if ($this->accountFactory !== null) {
			return ($this->accountFactory)($this->db);
		}

		if (!defined('DOL_DOCUMENT_ROOT')) {
			throw new RuntimeException('BankConnect: Dolibarr runtime is not loaded');
		}
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		return new Account($this->db);
	}

	private function toTimestamp(string $date): int
	{
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)) {
			throw new RuntimeException('BankConnect: invalid bank transaction date');
		}
		return gmmktime(12, 0, 0, (int)$parts[2], (int)$parts[3], (int)$parts[1]);
	}

	/** @param array<string,mixed> $transaction */
	private function buildLabel(array $transaction): string
	{
		foreach (['text', 'reference', 'counterparty'] as $field) {
			$value = trim((string)($transaction[$field] ?? ''));
			if ($value !== '') {
				return function_exists('dol_trunc') ? dol_trunc($value, 255) : substr($value, 0, 255);
			}
		}
		return 'BankConnect transaction';
	}
}
