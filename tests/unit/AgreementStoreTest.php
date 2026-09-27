<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/MockDoliDB.php';
require_once __DIR__.'/../../htdocs/custom/bankconnect/class/AgreementStore.php';

class AgreementStoreTest extends TestCase
{
    public function testMissingAgreementIsDistinctFromReadFailure(): void
    {
        $db = new MockDoliDB();
        $store = new AgreementStore($db);

        $this->assertNull($store->getAgreement(9));

        $db->failNextQueryContaining('FROM llx_bankconnect_agreement WHERE rowid', 'database unavailable');
        $this->expectException(BankConnectException::class);
        $this->expectExceptionMessage('getAgreement failed: database unavailable');
        $store->getAgreement(9);
    }

    public function testMissingActiveCertificateIsDistinctFromReadFailure(): void
    {
        $db = new MockDoliDB();
        $store = new AgreementStore($db);

        $this->assertNull($store->getActiveCertificate(9));

        $db->failNextQueryContaining('FROM llx_bankconnect_certificate', 'database unavailable');
        $this->expectException(BankConnectException::class);
        $this->expectExceptionMessage('getActiveCertificate failed: database unavailable');
        $store->getActiveCertificate(9);
    }

    public function testEmptyAgreementListIsDistinctFromReadFailure(): void
    {
        $db = new MockDoliDB();
        $store = new AgreementStore($db);

        $this->assertSame([], $store->listAgreements(1));

        $db->failNextQueryContaining('FROM llx_bankconnect_agreement WHERE entity', 'database unavailable');
        $this->expectException(BankConnectException::class);
        $this->expectExceptionMessage('listAgreements failed: database unavailable');
        $store->listAgreements(1);
    }
}
