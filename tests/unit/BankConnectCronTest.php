<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/MockDoliDB.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectCron.php';

class BankConnectCronTest extends TestCase
{
	public function testRepeatedRunsReportOnlyCurrentOutcome(): void
	{
		$previous = [];
		foreach (['db', 'conf', 'user'] as $key) {
			$previous[$key] = [array_key_exists($key, $GLOBALS), $GLOBALS[$key] ?? null];
		}
		try {
			$db = new MockDoliDB();
			$db->query("INSERT INTO llx_bankconnect_agreement (entity, label, bank_connect_id, status) VALUES (1, 'Test', 'BC1', 'active')");
			$db->query("INSERT INTO llx_bank_account (entity, label, clos) VALUES (1, 'Account', 0)");
			$mapping = new BankAccountMappingStore($db);
			$mapping->map(1, 1, 1);
			$db->query('UPDATE llx_bank_account SET clos = 1 WHERE rowid = 1');
			$GLOBALS['db'] = $db;
			$GLOBALS['conf'] = (object)['entity' => 1];
			$GLOBALS['user'] = (object)['id' => 7];
			$cron = new BankConnectCron();

			$this->assertSame(-1, $cron->importStatements());
			$this->assertNotEmpty($cron->errors);
			$this->assertStringContainsString('is closed', $cron->error);
			$this->assertStringContainsString('1 agreement(s)', $cron->output);

			$mapping->unmap(1, 1);
			$this->assertSame(0, $cron->importStatements());
			$this->assertSame([], $cron->errors);
			$this->assertSame('', $cron->error);
			$this->assertStringContainsString('0 agreement(s)', $cron->output);

			$GLOBALS['user'] = (object)['id' => 0];
			$this->assertSame(-1, $cron->importStatements());
			$this->assertSame([], $cron->errors);
			$this->assertSame('', $cron->output);
			$this->assertStringContainsString('requires the Dolibarr database', $cron->error);
		} finally {
			foreach ($previous as $key => [$exists, $value]) {
				if ($exists) {
					$GLOBALS[$key] = $value;
				} else {
					unset($GLOBALS[$key]);
				}
			}
		}
	}
}
