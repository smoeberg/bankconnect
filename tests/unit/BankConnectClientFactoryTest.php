<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../htdocs/custom/bankconnect/class/BankConnectClientFactory.php';
require_once __DIR__.'/BankCertificateFixture.php';

final class BankConnectClientFactoryTest extends TestCase
{
    private function agreements(Conf $conf): AgreementStore
    {
        $encrypted = (new BankConnectCertificateManager($conf))->encryptPrivateKey('customer private key');
        return new class($encrypted) extends AgreementStore {
            private string $encrypted;
            public function __construct(string $encrypted) { $this->encrypted = $encrypted; }
            public function getActiveCertificate(int $agreementId): ?array
            {
                return ['certificate_pem' => 'customer certificate', 'private_key_enc' => $this->encrypted];
            }
        };
    }

    public function testConfiguredLeafMustChainToTrustedRoot(): void
    {
        $fixture = BankCertificateFixture::create();
        $conf = new Conf();
        $conf->global['BANKCONNECT_BANK_CERTIFICATE'] = $fixture['leaf'];
        $conf->global['BANKCONNECT_TRUSTED_CA_PEM'] = $fixture['root'];
        $agreements = $this->agreements($conf);
        $this->assertInstanceOf(BankConnectClient::class, (new BankConnectClientFactory($conf, $agreements))->create(['rowid' => 1]));

        unset($conf->global['BANKCONNECT_TRUSTED_CA_PEM']);
        $this->expectException(BankConnectException::class);
        (new BankConnectClientFactory($conf, $agreements))->create(['rowid' => 1]);
    }
}
