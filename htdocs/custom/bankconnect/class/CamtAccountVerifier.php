<?php
/** Reject statements whose own account differs from the mapped Dolibarr account. */
class CamtAccountVerifier
{
    private $db;
    private string $prefix;

    public function __construct($db, ?string $prefix = null)
    {
        $this->db = $db;
        $this->prefix = $prefix ?? (defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_');
    }

    public function verify(string $camtXml, int $bankAccountId, int $entity): void
    {
        $res = $this->db->query('SELECT iban_prefix FROM '.$this->prefix.'bank_account'
            .' WHERE rowid='.(int)$bankAccountId.' AND entity='.(int)$entity.' AND clos=0 LIMIT 1');
        if ($res === false) throw new RuntimeException('BankConnect: unable to check mapped bank account');
        $account = $this->db->fetch_object($res);
        $expected = self::normalize((string)($account->iban_prefix ?? ''));
        if (!$account || !preg_match('/^[A-Z]{2}[A-Z0-9]{13,32}$/', $expected)) {
            throw new RuntimeException('BankConnect: mapped bank account needs a valid IBAN');
        }

        if (preg_match('/<!DOCTYPE\s|<!ENTITY\s/i', $camtXml)) {
            throw new RuntimeException('BankConnect: CAMT account document is unsafe');
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $doc->loadXML($camtXml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || !$doc->documentElement || $doc->documentElement->localName !== 'Document'
            || !str_starts_with((string)$doc->documentElement->namespaceURI, 'urn:iso:std:iso:20022:tech:xsd:camt.')) {
            throw new RuntimeException('BankConnect: invalid CAMT account document');
        }
        $xp = new DOMXPath($doc);
        $statements = $xp->query('/*[local-name()="Document"]/*[local-name()="BkToCstmrStmt" or local-name()="BkToCstmrAcctRpt" or local-name()="BkToCstmrDbtCdtNtfctn"]/*[local-name()="Stmt" or local-name()="Rpt" or local-name()="Ntfctn"]');
        if (!$statements || $statements->length === 0) {
            throw new RuntimeException('BankConnect: CAMT account identity is missing');
        }
        foreach ($statements as $statement) {
            $identities = $xp->query('./*[local-name()="Acct"]/*[local-name()="Id"]/*[local-name()="IBAN"]', $statement);
            if (!$identities || $identities->length !== 1) {
                throw new RuntimeException('BankConnect: CAMT account identity is missing or ambiguous');
            }
            if (!hash_equals($expected, self::normalize($identities->item(0)->textContent))) {
                throw new RuntimeException('BankConnect: CAMT account differs from mapped Dolibarr account');
            }
        }
    }

    private static function normalize(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($iban)));
    }
}
