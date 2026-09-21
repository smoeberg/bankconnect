<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectLogger.php';

final class BankConnectLoggerSecurityTest extends TestCase
{
    public function testSecretsAreRedactedRecursively(): void
    {
        $stream = fopen('php://memory', 'w+');
        $logger = new BankConnectLogger($stream);
        $logger->error('operation failed', [
            'operation' => 'getStatus',
            'api_key' => 'super-secret',
            'nested' => ['private_key_pem' => 'PRIVATE KEY', 'safe' => 'value'],
        ]);
        rewind($stream);
        $line = stream_get_contents($stream);
        fclose($stream);

        $this->assertStringContainsString('[REDACTED]', $line);
        $this->assertStringNotContainsString('super-secret', $line);
        $this->assertStringNotContainsString('PRIVATE KEY', $line);
        $this->assertStringContainsString('value', $line);
    }
}
