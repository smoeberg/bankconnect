<?php
require_once __DIR__.'/AgreementStore.php';
require_once __DIR__.'/BankAccountMappingStore.php';
require_once __DIR__.'/BankConnectClientFactory.php';
require_once __DIR__.'/BankConnectStatementResponseParser.php';
require_once __DIR__.'/ServiceHeaderBuilder.php';
require_once __DIR__.'/ImportService.php';
require_once __DIR__.'/BankConnectException.php';

/** Runs automatic BankConnect statement retrieval through the shared import path. */
class BankConnectAutomaticImportService
{
	private AgreementStore $agreements;
	private BankAccountMappingStore $mappings;
	private ImportService $importer;
	private BankConnectClientFactory $clients;
	private BankConnectStatementResponseParser $responses;

	public function __construct(
		AgreementStore $agreements,
		BankAccountMappingStore $mappings,
		ImportService $importer,
		BankConnectClientFactory $clients,
		?BankConnectStatementResponseParser $responses = null,
		?array $settings = null
	) {
		$this->settings = $settings ?? [];
		$this->agreements = $agreements;
		$this->mappings = $mappings;
		$this->importer = $importer;
		$this->clients = $clients;
		$this->responses = $responses ?? new BankConnectStatementResponseParser();
	}

	/** @return array{agreements:int,imported:int,duplicates:int,total:int,errors:list<string>} */
	public function run(int $entity, $user): array
	{
		$result = ['agreements' => 0, 'imported' => 0, 'duplicates' => 0, 'total' => 0, 'errors' => [], 'skipped' => 0];
		if ($this->inServiceWindow()) {
			// BankConnect service window (e.g. nightly maintenance): skip this
			// run entirely — the next cron execution picks up afterwards.
			$result['skipped'] = 'service_window';
			return $result;
		}
		foreach ($this->mappings->listMappings(max(1, $entity)) as $mapping) {
			$agreement = $this->agreements->getAgreement((int)$mapping['fk_agreement']);
			if ($agreement === null || (string)($agreement['status'] ?? '') !== 'active') {
				continue;
			}
			$result['agreements']++;
			try {
				$one = $this->runAgreement($agreement, (int)$mapping['fk_bank_account'], $user);
				$result['imported'] += $one['imported'];
				$result['duplicates'] += $one['duplicates'];
				$result['total'] += $one['total'];
				$summary = $one['imported'].' imported, '.$one['duplicates'].' duplicates, '
					.(isset($one['deferred_reversals']) ? $one['deferred_reversals'].' deferred reversals, ' : '')
					.$one['total'].' total (camt.053/052/054)';
				$this->agreements->recordSyncResult((int)$agreement['rowid'], $summary);
				// Cursor only advances after a fully successful agreement run —
				// on error the cursor stays put so the next run re-fetches.
				$this->agreements->advanceImportCursor(
					(int)$agreement['rowid'],
					gmdate('Y-m-d H:i:s')
				);
			} catch (Throwable $e) {
				$this->agreements->recordSyncResult((int)$agreement['rowid'], '', $e->getMessage());
				$result['errors'][] = 'Agreement #'.(int)$agreement['rowid'].': '.$e->getMessage();
			}
		}
		return $result;
	}

	/** @param array<string,mixed> $agreement
	 * @return array{imported:int,duplicates:int,total:int}
	 */
	private function runAgreement(array $agreement, int $bankAccountId, $user): array
	{
		$client = $this->clients->create($agreement);
		$sum = ['imported' => 0, 'duplicates' => 0, 'deferred_reversals' => 0, 'total' => 0];
		// Task 24: transient failures (network blip, BankConnect timeout, 5xx)
		// get limited retries with linear backoff before failing the agreement.
		$retriesLeft = (int) ($this->settings['transient_retries'] ?? 2);
		$retryDelay = (int) ($this->settings['retry_delay_seconds'] ?? 5);
		$transient = static function (\Throwable $e): bool {
			$msg = $e->getMessage();
			return (bool) preg_match('/(timed out|timeout|connection reset|temporar|5[0-9]{2}|502|503|504)/i', $msg);
		};

		// Fetch all three statement/report/notification flavours in order:
		// camt.053 (end-of-day statement), camt.052 (intraday account report)
		// and camt.054 (debit/credit notifications).
		$requests = [
			['format' => 'camt.053.001.02', 'call' => static fn(BankConnectClient $c, string $h) => $c->getCustomerStatement($h)],
			['format' => 'camt.052.001.02', 'call' => static fn(BankConnectClient $c, string $h) => $c->getCustomerAccountReport($h)],
			['format' => 'camt.054.001.02', 'call' => static fn(BankConnectClient $c, string $h) => $c->getDebitCreditNotification($h)],
		];
		foreach ($requests as $request) {
			$header = (new ServiceHeaderBuilder())
				->setOrganisation((string)$agreement['main_registration_number'], 'DK')
				->setFunctionIdentification((string)$agreement['bank_connect_id'])
				->setErp('Dolibarr', defined('DOL_VERSION') ? DOL_VERSION : '')
				->setFormat($request['format']);
			$response = null;
			for ($attempt = 0; ; $attempt++) {
				try {
					$response = $request['call']($client, $header->build());
					break;
				} catch (\Throwable $e) {
					if ($attempt >= $retriesLeft || !$transient($e)) {
						throw $e;
					}
					if ($retryDelay > 0) {
						sleep($retryDelay);
					}
				}
			}
			try {
				$camt = $this->responses->extract($response);
			} catch (BankConnectException $e) {
				// An OK response without a CAMT payload is legitimate for camt.052
				// and camt.054 when there are no intraday/ notification entries —
				// only surface a real transport failure for the mandatory 053 call.
				if ($request['format'] !== 'camt.053.001.02') {
					continue;
				}
				throw $e;
			}
			if (trim($camt) === '') {
				continue;
			}
			$source = 'bankconnect:'.(int)$agreement['rowid'].':'.$header->getEndToEndMessageId();
			$one = $this->importer->import($camt, $bankAccountId, $source, $user);
			$sum['imported'] += $one['imported'];
			$sum['duplicates'] += $one['duplicates'];
			$sum['deferred_reversals'] += $one['deferred_reversals'] ?? 0;
			$sum['total'] += $one['total'];
		}
		return $sum;
	}

	/**
	 * Service window check (task 24): BANKCONNECT_SERVICE_WINDOWS holds a
	 * comma-separated list of "HH:MM-HH:MM" ranges (server time) during which
	 * BankConnect is unavailable (bank maintenance). Outside config = always on.
	 */
	private function inServiceWindow(): bool
	{
		$spec = trim((string) ($this->settings['service_windows'] ?? ''));
		if ($spec === '') {
			return false;
		}
		$now = (int) date('Hi');
		foreach (explode(',', $spec) as $window) {
			$parts = explode('-', trim($window));
			if (count($parts) !== 2) {
				continue;
			}
			$from = (int) str_replace(':', '', trim($parts[0]));
			$to = (int) str_replace(':', '', trim($parts[1]));
			if ($from <= $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to)) {
				return true;
			}
		}
		return false;
	}
}
