<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectException.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/Pain001Builder.php';

class Pain001BuilderTest extends TestCase
{
    private function baseBuilder(): Pain001Builder
    {
        return (new Pain001Builder())
            ->setInitiatingParty('Test ApS')
            ->setDebtor('Test ApS', 'DK5089000000012345', 'SXPYDKKK')
            ->setExecutionDate(new DateTimeImmutable('2026-09-20'));
    }

    private function sampleTx(string $endToEndId = 'E2E001'): array
    {
        return [
            'endToEndId'   => $endToEndId,
            'amount'       => 12450.00,
            'currency'     => 'DKK',
            'creditorName' => 'ABC A/S',
            'creditorIban' => 'DK0689000000054321',
            'creditorBic'  => 'NDEADKKK',
            'remittance'   => 'Faktura FA240891',
        ];
    }

    public function testMsgIdMax35Chars(): void
    {
        $b = $this->baseBuilder();
        $this->assertLessThanOrEqual(35, strlen($b->getMsgId()));
    }

    public function testCustomMsgIdTooLongThrows(): void
    {
        $this->expectException(BankConnectException::class);
        $this->baseBuilder()->setMsgId(str_repeat('X', 36));
    }

    public function testControlSum(): void
    {
        $b = $this->baseBuilder()
            ->addTransaction($this->sampleTx('E2E001'))
            ->addTransaction(array_merge($this->sampleTx('E2E002'), ['amount' => 550.50]));

        $this->assertEqualsWithDelta(13000.50, $b->getControlSum(), 0.001);
    }

    public function testBuildContainsRequiredElements(): void
    {
        $xml = $this->baseBuilder()
            ->addTransaction($this->sampleTx())
            ->build();

        $this->assertStringContainsString('pain.001.001.03', $xml);
        $this->assertStringContainsString('<MsgId>', $xml);
        $this->assertStringContainsString('<NbOfTxs>1</NbOfTxs>', $xml);
        $this->assertStringContainsString('<CtrlSum>12450.00</CtrlSum>', $xml);
        $this->assertStringContainsString('<PmtMtd>TRF</PmtMtd>', $xml);
        $this->assertStringContainsString('<IBAN>DK5089000000012345</IBAN>', $xml);
        $this->assertStringContainsString('<EndToEndId>E2E001</EndToEndId>', $xml);
        $this->assertStringContainsString('<InstdAmt Ccy="DKK">12450.00</InstdAmt>', $xml);
        $this->assertStringContainsString('ABC A/S', $xml);
        $this->assertStringContainsString('<Ustrd>Faktura FA240891</Ustrd>', $xml);
    }

    public function testSepaServiceLevel(): void
    {
        $xml = $this->baseBuilder()
            ->setPaymentType(Pain001Builder::TYPE_SEPA)
            ->addTransaction(array_merge($this->sampleTx(), [
                'currency' => 'EUR',
                'amount'   => 100.00,
            ]))
            ->build();

        $this->assertStringContainsString('<Cd>SEPA</Cd>', $xml);
        $this->assertStringContainsString('Ccy="EUR"', $xml);
    }

    public function testDkTransferDefaultServiceLevel(): void
    {
        $xml = $this->baseBuilder()
            ->addTransaction($this->sampleTx())
            ->build();

        $this->assertStringContainsString('<Cd>NURG</Cd>', $xml);
    }

    public function testMissingTransactionFieldThrows(): void
    {
        $this->expectException(BankConnectException::class);
        $this->baseBuilder()->addTransaction([
            'endToEndId' => 'X',
            // amount missing
            'currency'     => 'DKK',
            'creditorName' => 'A',
            'creditorIban' => 'DK00',
        ]);
    }

    public function testEndToEndIdTooLongThrows(): void
    {
        $this->expectException(BankConnectException::class);
        $this->baseBuilder()->addTransaction($this->sampleTx(str_repeat('Y', 36)));
    }

    public function testBuildWithoutTransactionsThrows(): void
    {
        $this->expectException(BankConnectException::class);
        $this->baseBuilder()->build();
    }

    public function testBuildWithoutDebtorThrows(): void
    {
        $this->expectException(BankConnectException::class);
        (new Pain001Builder())
            ->addTransaction($this->sampleTx())
            ->build();
    }

    public function testExecutionDateInXml(): void
    {
        $xml = $this->baseBuilder()
            ->setExecutionDate(new DateTimeImmutable('2026-10-01'))
            ->addTransaction($this->sampleTx())
            ->build();

        $this->assertStringContainsString('<ReqdExctnDt>2026-10-01</ReqdExctnDt>', $xml);
    }

    public function testMultipleTransactionsNbOfTxs(): void
    {
        $b = $this->baseBuilder()
            ->addTransaction($this->sampleTx('A'))
            ->addTransaction($this->sampleTx('B'))
            ->addTransaction($this->sampleTx('C'));

        $xml = $b->build();
        $this->assertStringContainsString('<NbOfTxs>3</NbOfTxs>', $xml);
        $this->assertEquals(3, substr_count($xml, '<CdtTrfTxInf>'));
    }
}
