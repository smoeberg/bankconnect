<?php
require_once __DIR__.'/BankConnectClient.php';
require_once __DIR__.'/BankConnectCertificateManager.php';

/** Creates an agreement-scoped, certificate-backed BankConnect client. */
class BankConnectClientFactory
{
	private $conf;
	private AgreementStore $agreements;

	public function __construct($conf, AgreementStore $agreements)
	{
		$this->conf = $conf;
		$this->agreements = $agreements;
	}

	/** @param array<string,mixed> $agreement */
	public function create(array $agreement): BankConnectClient
	{
		$certificate = $this->agreements->getActiveCertificate((int)$agreement['rowid']);
		if ($certificate === null) {
			throw new BankConnectException('BankConnect agreement has no active customer certificate');
		}

		$manager = new BankConnectCertificateManager($this->conf, null, null, $this->agreements);
		$global = (array)($this->conf->global ?? []);
		$global['BANKCONNECT_CUSTOMER_CERTIFICATE'] = (string)$certificate['certificate_pem'];
		$global['BANKCONNECT_CUSTOMER_PRIVATE_KEY'] = $manager->decryptPrivateKey((string)$certificate['private_key_enc']);
		if (!empty($agreement['endpoint'])) {
			$global['BANKCONNECT_ENDPOINT'] = (string)$agreement['endpoint'];
		}
		if (!empty($agreement['datacenter'])) {
			$global['BANKCONNECT_DATACENTER'] = (string)$agreement['datacenter'];
		}
		if (empty($global['BANKCONNECT_BANK_CERTIFICATE'])) {
			$environmentCertificate = getenv('BANKCONNECT_BANK_CERTIFICATE');
			if ($environmentCertificate !== false && trim($environmentCertificate) !== '') {
				$global['BANKCONNECT_BANK_CERTIFICATE'] = $environmentCertificate;
			}
		}

		$clientConf = new Conf();
		$clientConf->global = $global;
		return new BankConnectClient($clientConf);
	}
}
