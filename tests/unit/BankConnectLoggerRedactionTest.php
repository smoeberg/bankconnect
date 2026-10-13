<?php

use PHPUnit\\Framework\\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectLogger.php';

final class BankConnectLoggerRedactionTest extends TestCase
{
    public function testFreeFormErrorContextIsRedacted(): void
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertIsResource($stream);

        $logger = new BankConnectLogger($stream);
        $logger->error('soap_call_failed', [
            'operation' => 'transferPayments',
            'error_class' => 'SoapFault',
            'error' => 'SOAP fault includes SECRET_ACCOUNT_123 and private material',
        ]);

        rewind($stream);
        $line = stream_get_contents($stream);
        fclose($stream);

        $this->assertIsString($line);
        $this->assertStringNotContainsString('SECRET_ACCOUNT_123', $line);
        $this->assertStringNotContainsString('private material', $line);
        $this->assertStringContainsString('[REDACTED]', $line);
        $this->assertStringContainsString('SoapFault', $line);
        $this->assertStringContainsString('transferPayments', $line);
    }
}
