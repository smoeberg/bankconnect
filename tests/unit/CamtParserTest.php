<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankTransaction.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/CamtParser.php';

class CamtParserTest extends TestCase
{
    private const CAMT = <<<'XML'
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
  <BkToCstmrStmt>
    <Stmt>
      <Id>STMT-2026-09-12</Id>
      <Ntry>
        <Amt Ccy="DKK">12450.00</Amt>
        <CdtDbtInd>DBIT</CdtDbtInd>
        <BookgDt><Dt>2026-09-12</Dt></BookgDt>
        <AddtlNtryInf>BETALING LEVERANDOR</AddtlNtryInf>
        <NtryDtls>
          <TxDtls>
            <Refs><EndToEndId>E2E-FA240891</EndToEndId></Refs>
            <RmtInf><Ustrd>FAKTURA 784512</Ustrd></RmtInf>
            <RltdPties>
              <Dbtr><Nm>Vores Firm A/S</Nm></Dbtr>
              <Cdtr><Nm>ABC A/S</Nm></Cdtr>
            </RltdPties>
          </TxDtls>
        </NtryDtls>
      </Ntry>
      <Ntry>
        <Amt Ccy="DKK">5000.00</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <BookgDt><Dt>2026-09-13</Dt></BookgDt>
        <AddtlNtryInf>INDBETALING KUNDE X</AddtlNtryInf>
        <NtryDtls>
          <TxDtls>
            <RmtInf>
              <Strd>
                <CdtrRefInf><Ref>071234567890123</Ref></CdtrRefInf>
              </Strd>
            </RmtInf>
            <RltdPties>
              <Dbtr><Nm>Kunde X ApS</Nm></Dbtr>
              <Cdtr><Nm>Vores Firm A/S</Nm></Cdtr>
            </RltdPties>
          </TxDtls>
        </NtryDtls>
      </Ntry>
    </Stmt>
  </BkToCstmrStmt>
</Document>
XML;

    private const CAMT_BATCH = <<<'XML'
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
  <BkToCstmrStmt>
    <Stmt>
      <Ntry>
        <Amt Ccy="DKK">3000.00</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <BookgDt><Dt>2026-09-14</Dt></BookgDt>
        <AcctSvcrRef>BATCH-001</AcctSvcrRef>
        <NtryDtls>
          <TxDtls>
            <Amt Ccy="DKK">1000.00</Amt>
            <Refs><EndToEndId>INV-1</EndToEndId></Refs>
            <RmtInf><Ustrd>Faktura 1</Ustrd></RmtInf>
            <RltdPties><Dbtr><Nm>Kunde A</Nm></Dbtr></RltdPties>
          </TxDtls>
          <TxDtls>
            <Amt Ccy="DKK">2000.00</Amt>
            <Refs><EndToEndId>INV-2</EndToEndId></Refs>
            <RmtInf><Ustrd>Faktura 2</Ustrd></RmtInf>
            <RltdPties><Dbtr><Nm>Kunde B</Nm></Dbtr></RltdPties>
          </TxDtls>
        </NtryDtls>
      </Ntry>
    </Stmt>
  </BkToCstmrStmt>
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

    public function testPrefersEndToEndIdOverFreestyleDigits(): void
    {
        $txs = (new CamtParser())->parse(self::CAMT);
        $this->assertSame('E2E-FA240891', $txs[0]->reference);
    }

    public function testPrefersCdtrRefInfOcr(): void
    {
        $txs = (new CamtParser())->parse(self::CAMT);
        $this->assertSame('071234567890123', $txs[1]->reference);
    }

    public function testCounterpartyDirection(): void
    {
        $txs = (new CamtParser())->parse(self::CAMT);
        // DBIT → creditor (supplier)
        $this->assertSame('ABC A/S', $txs[0]->counterparty);
        // CRDT → debtor (customer)
        $this->assertSame('Kunde X ApS', $txs[1]->counterparty);
    }

    public function testBatchTxDtlsSplit(): void
    {
        $txs = (new CamtParser())->parse(self::CAMT_BATCH);
        $this->assertCount(2, $txs);
        $this->assertSame(1000.00, $txs[0]->amount);
        $this->assertSame(2000.00, $txs[1]->amount);
        $this->assertSame('INV-1', $txs[0]->reference);
        $this->assertSame('INV-2', $txs[1]->reference);
        $this->assertSame('Kunde A', $txs[0]->counterparty);
        $this->assertSame('Kunde B', $txs[1]->counterparty);
        // Distinct hashes despite same booking date
        $this->assertNotSame($txs[0]->hash, $txs[1]->hash);
    }

    public function testMalformedXmlThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new CamtParser())->parse('<Document><broken>');
    }

    public function testEmptyThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new CamtParser())->parse('');
    }
}
