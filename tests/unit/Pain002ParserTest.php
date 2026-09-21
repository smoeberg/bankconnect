<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectException.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/Pain002Parser.php';

class Pain002ParserTest extends TestCase
{
    private const PAIN002_ACCP = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.002.001.03">
  <CstmrPmtStsRpt>
    <GrpHdr>
      <MsgId>STATUS-001</MsgId>
      <CreDtTm>2026-09-19T10:00:00</CreDtTm>
    </GrpHdr>
    <OrgnlGrpInfAndSts>
      <OrgnlMsgId>MSGABC123</OrgnlMsgId>
      <GrpSts>ACCP</GrpSts>
    </OrgnlGrpInfAndSts>
    <OrgnlPmtInfAndSts>
      <TxInfAndSts>
        <OrgnlEndToEndId>E2E-FA240891</OrgnlEndToEndId>
        <TxSts>ACCP</TxSts>
      </TxInfAndSts>
      <TxInfAndSts>
        <OrgnlEndToEndId>E2E-FA240892</OrgnlEndToEndId>
        <TxSts>ACCP</TxSts>
      </TxInfAndSts>
    </OrgnlPmtInfAndSts>
  </CstmrPmtStsRpt>
</Document>
XML;

    private const PAIN002_RJCT = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.002.001.03">
  <CstmrPmtStsRpt>
    <GrpHdr>
      <MsgId>STATUS-002</MsgId>
      <CreDtTm>2026-09-19T11:00:00</CreDtTm>
    </GrpHdr>
    <OrgnlGrpInfAndSts>
      <OrgnlMsgId>MSGXYZ789</OrgnlMsgId>
      <GrpSts>PART</GrpSts>
    </OrgnlGrpInfAndSts>
    <OrgnlPmtInfAndSts>
      <TxInfAndSts>
        <OrgnlEndToEndId>E2E-BAD</OrgnlEndToEndId>
        <TxSts>RJCT</TxSts>
        <StsRsnInf>
          <Rsn><Cd>AC01</Cd></Rsn>
          <AddtlInf>IncorrectAccountNumber</AddtlInf>
        </StsRsnInf>
      </TxInfAndSts>
      <TxInfAndSts>
        <OrgnlEndToEndId>E2E-OK</OrgnlEndToEndId>
        <TxSts>ACCP</TxSts>
      </TxInfAndSts>
    </OrgnlPmtInfAndSts>
  </CstmrPmtStsRpt>
</Document>
XML;

    public function testParseAccepted(): void
    {
        $p = new Pain002Parser();
        $r = $p->parse(self::PAIN002_ACCP);

        $this->assertSame('STATUS-001', $r['msg_id']);
        $this->assertSame('MSGABC123', $r['original_msg_id']);
        $this->assertSame('ACCP', $r['group_status']);
        $this->assertCount(2, $r['transactions']);
        $this->assertSame('E2E-FA240891', $r['transactions'][0]['end_to_end_id']);
        $this->assertSame('ACCP', $r['transactions'][0]['status']);
    }

    public function testParseRejectedWithReason(): void
    {
        $p = new Pain002Parser();
        $r = $p->parse(self::PAIN002_RJCT);

        $this->assertSame('PART', $r['group_status']);
        $this->assertCount(2, $r['transactions']);

        $rej = $r['transactions'][0];
        $this->assertSame('E2E-BAD', $rej['end_to_end_id']);
        $this->assertSame('RJCT', $rej['status']);
        $this->assertSame('AC01', $rej['reason_code']);
        $this->assertSame('IncorrectAccountNumber', $rej['reason_text']);

        $this->assertSame('ACCP', $r['transactions'][1]['status']);
    }

    public function testEmptyThrows(): void
    {
        $this->expectException(BankConnectException::class);
        (new Pain002Parser())->parse('');
    }

    public function testUnknownStatusRequiresManualReview(): void
    {
        $xml = str_replace('<TxSts>ACCP</TxSts>', '<TxSts>WTF1</TxSts>', self::PAIN002_ACCP);
        $r = (new Pain002Parser())->parse($xml);
        $this->assertSame('unknown', $r['transactions'][0]['semantic_status']);
        $this->assertTrue($r['transactions'][0]['requires_manual_review']);
    }

    public function testMalformedThrows(): void
    {
        $this->expectException(BankConnectException::class);
        (new Pain002Parser())->parse('<Document><broken>');
    }

    public function testMapToInternalStatus(): void
    {
        $this->assertSame('accepted', Pain002Parser::mapToInternalStatus('ACCP'));
        $this->assertSame('accepted', Pain002Parser::mapToInternalStatus('ACSP'));
        $this->assertSame('rejected', Pain002Parser::mapToInternalStatus('RJCT'));
        $this->assertSame('pending', Pain002Parser::mapToInternalStatus('PDNG'));
        $this->assertSame('pending', Pain002Parser::mapToInternalStatus('RCVD'));
        $this->assertSame('partial', Pain002Parser::mapToInternalStatus('PART'));
        $this->assertSame('unknown', Pain002Parser::mapToInternalStatus('WTF1'));
    }
}
