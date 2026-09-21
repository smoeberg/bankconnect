<?php
/* Dolibarr module descriptor — BankConnect (DK bank reconciliation). */
/* Copyright (C) 2026 WM Group / Eira. */

require_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.php';

class modBankConnect extends DolibarrModules
{
	public $id = 500010;
	public $name = 'BankConnect';
	public $family = 'financial';
	public $version = '0.2.2';
	public $description = 'Bankafstemning: camt-import, regelbaseret matching med AI-fallback, godkendelse før bokføring (DK).';
	public $editor_name = 'WM Group / Eira';
	public $editor_url = 'https://github.com/smoeberg/bankconnect';

	/**
	 * Create BankConnect tables and apply additive schema upgrades.
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/bankconnect/sql/');
		if ($result < 0) {
			return -1;
		}

		$table = MAIN_DB_PREFIX.'bankconnect_transaction';
		$columns = [
			'acct_svcr_ref' => "VARCHAR(255) NULL",
			'is_reversal' => "INTEGER NOT NULL DEFAULT 0",
			'requires_manual_review' => "INTEGER NOT NULL DEFAULT 0",
			'statement_id' => "VARCHAR(255) NOT NULL DEFAULT ''",
			'transaction_id' => "VARCHAR(255) NOT NULL DEFAULT ''",
		];
		foreach ($columns as $column => $definition) {
			$sql = "SHOW COLUMNS FROM ".$table." LIKE '".$this->db->escape($column)."'";
			$res = $this->db->query($sql);
			if (!$res) {
				return -1;
			}
			if (!$this->db->fetch_object($res)) {
				if (!$this->db->query("ALTER TABLE ".$table." ADD COLUMN ".$column." ".$definition)) {
					return -1;
				}
			}
		}

		$batchLineTable = MAIN_DB_PREFIX.'bankconnect_batch_line';
		$checkManual = $this->db->query("SHOW COLUMNS FROM ".$batchLineTable." LIKE 'requires_manual_review'");
		if ($checkManual && !$this->db->fetch_object($checkManual)) {
			if (!$this->db->query("ALTER TABLE ".$batchLineTable." ADD COLUMN requires_manual_review TINYINT NOT NULL DEFAULT 0")) {
				return -1;
			}
		}

		// Existing installations need the same database-level idempotency boundary
		// as fresh installs. A non-unique statement/transaction pair is retained for
		// legacy rows with empty identity values.
		$uniqueName = 'uk_bc_statement_transaction';
		$check = $this->db->query("SHOW INDEX FROM ".$table." WHERE Key_name = '".$this->db->escape($uniqueName)."'");
		if ($check && !$this->db->fetch_object($check)) {
			$this->db->query("ALTER TABLE ".$table." ADD UNIQUE KEY ".$uniqueName." (fk_bank_account, statement_id, transaction_id)");
		}

		return $this->_init([], $options);
	}

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
