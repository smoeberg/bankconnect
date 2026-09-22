<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectException.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectStatementResponseParser.php';

class BankConnectStatementResponseParserTest extends TestCase
{
	private string $camt = '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt/></BkToCstmrStmt></Document>';

	public function testExtractsBase64CamtContent(): void
	{
		$soap = '<Envelope><Body><getCustomerStatementResponse><statementMessage>'
			.'<format>ISO20022</format><content>'.base64_encode($this->camt).'</content>'
			.'</statementMessage></getCustomerStatementResponse></Body></Envelope>';
		$result = (new BankConnectStatementResponseParser())->extract($soap);
		$this->assertStringContainsString('BkToCstmrStmt', $result);
	}

	public function testExtractsGzipCamtContent(): void
	{
		$soap = '<Envelope><Body><content>'.base64_encode(gzencode($this->camt)).'</content></Body></Envelope>';
		$result = (new BankConnectStatementResponseParser())->extract($soap);
		$this->assertStringContainsString('camt.053.001.02', $result);
	}

	public function testRejectsResponseWithoutCamt(): void
	{
		$this->expectException(BankConnectException::class);
		$this->expectExceptionMessage('no CAMT');
		(new BankConnectStatementResponseParser())->extract('<Envelope><Body><content>'.base64_encode('<status>OK</status>').'</content></Body></Envelope>');
	}

	public function testRejectsAmbiguousMultipleStatements(): void
	{
		$this->expectException(BankConnectException::class);
		$this->expectExceptionMessage('multiple CAMT');
		$content = base64_encode($this->camt);
		(new BankConnectStatementResponseParser())->extract('<Envelope><content>'.$content.'</content><content>'.$content.'</content></Envelope>');
	}
}
