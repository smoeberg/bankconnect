<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/MockDoliDB.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/CamtAccountVerifier.php';

final class CamtAccountVerifierTest extends TestCase
{
    private function db(): MockDoliDB
    {
        $db = new MockDoliDB();
        $db->tables['llx_bank_account'] = [[
            'rowid' => 7, 'entity' => 1, 'clos' => 0,
            'iban_prefix' => 'DK50 0040 0440 1162 43',
        ]];
        return $db;
    }

    private function camt(string $iban, string $container = 'Stmt', string $wrapper = 'BkToCstmrStmt'): string
    {
        return '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">'
            .'<'.$wrapper.'><'.$container.'><Acct><Id><IBAN>'.$iban.'</IBAN></Id></Acct></'.$container.'>'
            .'</'.$wrapper.'></Document>';
    }

    public function testStatementUsesMappedDolibarrIban(): void
    {
        (new CamtAccountVerifier($this->db()))->verify($this->camt('dk5000400440116243'), 7, 1);
        $this->assertTrue(true);
    }

    public function testForeignAccountFailsBeforeImport(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('differs from mapped');
        (new CamtAccountVerifier($this->db()))->verify($this->camt('DK0689000000054321'), 7, 1);
    }

    public function testMissingAccountIdentityFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('identity is missing');
        (new CamtAccountVerifier($this->db()))->verify(
            '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.052.001.02"><BkToCstmrAcctRpt><Rpt/></BkToCstmrAcctRpt></Document>', 7, 1
        );
    }

    public function testMixedAccountsInOneDocumentFailClosed(): void
    {
        $xml = $this->camt('DK5000400440116243');
        $xml = str_replace('</BkToCstmrStmt>', '<Stmt><Acct><Id><IBAN>DK0689000000054321</IBAN></Id></Acct></Stmt></BkToCstmrStmt>', $xml);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('differs from mapped');
        (new CamtAccountVerifier($this->db()))->verify($xml, 7, 1);
    }

    public function testAnotherEntityCannotUseMappedAccount(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mapped bank account needs a valid IBAN');
        (new CamtAccountVerifier($this->db()))->verify($this->camt('DK5000400440116243'), 7, 2);
    }
}
