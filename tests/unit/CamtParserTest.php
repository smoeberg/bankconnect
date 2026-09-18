<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankTransaction.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/CamtParser.php';

class CamtParserTest extends TestCase
{
    private const CAMT = <<<'XML'
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
  <BkToCstmrAcctRpt>
    <Rpt>
      <Id>STMT-2026-09-12</Id>
      <Ntry>
        <Amt Ccy="DKK">12450.00</Amt>
        <CdtDbtInd>DBIT</CdtDbtInd>
        <BookgDt><Dt>2026-09-12</Dt></BookgDt>
        <AddtlNtryInf>BETALING LEVERANDOR ABC A/S 784512</AddtlNtryInf>
        <NtryDtls>
          <TxDtls>
            <RmtInf><Ustrd>FAKTURA 784512</Ustrd></RmtInf>
            <RltdPties><Nm>ABC A/S</Nm></RltdPties>
          </TxDtls>
        </NtryDtls>
      </Ntry>
      <Ntry>
        <Amt Ccy="DKK">5000.00</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <BookgDt><Dt>2026-09-13</Dt></BookgDt>
        <AddtlNtryInf>INDBETALING KUNDE X 010280-1234</AddtlNtryInf>
      </Ntry>
    </Rpt>
  </BkToCstmrAcctRpt>
</Document>
XML;

    public function testParsesAmountSignAndDate(): void
    {
        $txs = (new CamtParser())->parse(self::CAMT);
        $this->assertCount(2, $txs);
        $this->assertSame(-12450.00, $txs[0]->amount);
        $this->assertSame(5000.00, $txs[1]->amount);
        $this->assertSame('2026-09-12', $txs[0]->date);
    }

    public function testExtractsReferenceAndCounterparty(): void
    {
        $txs = (new CamtParser())->parse(self::CAMT);
        $this->assertSame('784512', $txs[0]->reference);
        $this->assertSame('ABC A/S', $txs[0]->counterparty);
    }

    public function testMalformedXmlThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new CamtParser())->parse('<Document><broken>');
    }
}
