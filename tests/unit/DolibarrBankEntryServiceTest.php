<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/DolibarrBankEntryService.php';

class DolibarrBankEntryServiceTest extends TestCase
{
	public function testCreatesEntryThroughAccountAddline(): void
	{
		$account = new class {
			public int $id = 0;
			public array $arguments = [];
			public string $error = '';

			public function fetch(int $id): int
			{
				$this->id = $id;
				return 1;
			}

			public function addline(...$arguments): int
			{
				$this->arguments = $arguments;
				return 7631;
			}
		};
		$service = new DolibarrBankEntryService(new stdClass(), fn() => $account);
		$user = (object)['id' => 42];

		$id = $service->create([
			'date' => '2026-09-17',
			'amount' => 550.0,
			'text' => 'Customer payment',
			'reference' => 'PAY2609-6603',
			'acctSvcrRef' => 'BANK-REF-1',
			'counterparty' => 'TechCorp Retail',
			'statement_id' => 'STMT-17',
		], 1004, $user);

		$this->assertSame(7631, $id);
		$this->assertSame(1004, $account->id);
		$this->assertSame('VIR', $account->arguments[1]);
		$this->assertSame('Customer payment', $account->arguments[2]);
		$this->assertSame(550.0, $account->arguments[3]);
		$this->assertSame('BANK-REF-1', $account->arguments[4]);
		$this->assertSame($user, $account->arguments[6]);
		$this->assertSame('TechCorp Retail', $account->arguments[7]);
		$this->assertSame('STMT-17', $account->arguments[11]);
	}

	public function testRejectsUnknownBankAccount(): void
	{
		$account = new class {
			public function fetch(int $id): int { return 0; }
		};
		$service = new DolibarrBankEntryService(new stdClass(), fn() => $account);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('not found');
		$service->create(['date' => '2026-09-17', 'amount' => 1], 999, (object)['id' => 1]);
	}

	public function testPropagatesDolibarrAddlineFailure(): void
	{
		$account = new class {
			public string $error = 'Database rejected entry';
			public function fetch(int $id): int { return 1; }
			public function addline(...$arguments): int { return -1; }
		};
		$service = new DolibarrBankEntryService(new stdClass(), fn() => $account);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Database rejected entry');
		$service->create(['date' => '2026-09-17', 'amount' => 1], 1, (object)['id' => 1]);
	}

	public function testRecoversExistingEntryByPrivateIdempotencyMarker(): void
	{
		$db = new class {
			public function escape($value): string { return addslashes((string)$value); }
			public function query($sql): array { return [['rowid' => 7631]]; }
			public function fetch_object(&$result)
			{
				$row = array_shift($result);
				return $row ? (object)$row : false;
			}
		};
		$account = new class {
			public int $addlineCalls = 0;
			public function fetch(int $id): int { return 1; }
			public function addline(...$arguments): int { $this->addlineCalls++; return 9999; }
		};
		$service = new DolibarrBankEntryService($db, fn() => $account);

		$id = $service->create([
			'date' => '2026-09-17',
			'amount' => 550,
			'hash' => str_repeat('a', 64),
		], 1004, (object)['id' => 42]);

		$this->assertSame(7631, $id);
		$this->assertSame(0, $account->addlineCalls);
	}
}
