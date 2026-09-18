<?php
/* Dolibarr module descriptor — BankConnect (DK bank reconciliation). */
/* Copyright (C) 2026 WM Group / Eira. */

require_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.php';

class modBankConnect extends DolibarrModules
{
	public $id = 500010;
	public $name = 'BankConnect';
	public $family = 'financial';
	public $version = '0.2.0';
	public $description = 'Bankafstemning: camt-import, regelbaseret matching med AI-fallback, godkendelse før bokføring (DK).';
	public $editor_name = 'WM Group / Eira';
	public $editor_url = 'https://github.com/smoeberg/bankconnect';

	public function __construct($db)
	{
		parent::__construct($db);

		$this->const_name = 'MAIN_MODULE_BANKCONNECT';
		$this->config_page_url = ['bankconnect.php@bankconnect'];
		$this->dirs = ['/bankconnect'];

		// Permissions: 1 = read, 2 = import/approve
		$this->rights_class = 'bankconnect';
		$this->rights[1][0] = 500011;
		$this->rights[1][1] = 'Read bank reconciliations';
		$this->rights[1][4] = 'read';
		$this->rights[2][0] = 500012;
		$this->rights[2][1] = 'Import bank transactions and approve reconciliation';
		$this->rights[2][4] = 'write';

		// Menu entry under bank module
		$this->menu[0] = [
			'fk_menu' => 'fk_menu=mod_bank;type=left',
			'type' => 'left',
			'titre' => 'BankConnect',
			'url' => '/custom/bankconnect/pages/reconcile.php',
			'langs' => 'bankconnect@bankconnect',
			'position' => 500,
			'perms' => '$user->rights->bankconnect->read',
			'enabled' => 'isModEnabled("bankconnect")',
			'user' => 0,
		];
	}
}
