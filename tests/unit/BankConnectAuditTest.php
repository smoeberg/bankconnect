<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectException.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectAudit.php';

final class BankConnectAuditTest extends TestCase
{
    public function testUnknownAuditEventIsRejected(): void
    {
        $db = new class {
            public function query($sql) { return true; }
            public function escape($value) { return $value; }
            public function lasterror() { return ''; }
        };

        $this->expectException(BankConnectException::class);
        (new BankConnectAudit($db))->record('arbitrary_event');
    }

    public function testSensitiveMetadataIsNotWritten(): void
    {
        $db = new class {
            public string $sql = '';
            public function query($sql) { $this->sql = $sql; return true; }
            public function escape($value) { return $value; }
            public function lasterror() { return ''; }
        };

        $audit = new BankConnectAudit($db);
        $audit->record('bankconnect_failure', 7, [
            'operation' => 'getStatus',
            'correlation_id' => 'abc',
            'api_key' => 'secret',
            'payload' => '<soap>secret</soap>',
        ]);

        $this->assertStringContainsString('correlation_id', $db->sql);
        $this->assertStringNotContainsString('secret', $db->sql);
        $this->assertStringNotContainsString('payload', $db->sql);
    }
}
