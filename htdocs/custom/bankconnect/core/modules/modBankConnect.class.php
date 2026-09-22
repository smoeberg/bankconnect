<?php
/* Copyright (C) 2026 WM Group / Eira */

/**
 * \file       htdocs/custom/bankconnect/core/modules/modBankConnect.class.php
 * \ingroup    bankconnect
 * \brief      BankConnect module descriptor.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/** Description and activation class for the BankConnect module. */
class modBankConnect extends DolibarrModules
{
	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;
		$this->numero = 500010;
		$this->rights_class = 'bankconnect';
		$this->family = 'financial';
		$this->module_position = '80';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'ModuleBankConnectDesc';
		$this->descriptionlong = 'ModuleBankConnectDescLong';
		$this->editor_name = 'WM Group / Eira';
		$this->editor_url = 'https://github.com/smoeberg/bankconnect';
		$this->version = '0.8.0';
		$this->const_name = 'MAIN_MODULE_BANKCONNECT';
		$this->picto = 'bank';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
		);

		$this->dirs = array('/bankconnect/temp');
		$this->config_page_url = array('bankconnect.php@bankconnect');
		$this->hidden = false;
		$this->depends = array('modBanque');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('bankconnect@bankconnect');
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(24, 0);
		$this->need_javascript_ajax = 0;
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();
		$this->const = array();
		$this->tabs = array();
		$this->cronjobs = array(
			array(
				'label' => 'BankConnectAutomaticImport',
				'jobtype' => 'method',
				'class' => '/bankconnect/class/BankConnectCron.php',
				'objectname' => 'BankConnectCron',
				'method' => 'importStatements',
				'parameters' => '',
				'comment' => 'BankConnectAutomaticImportDesc',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'status' => 0,
				'test' => 'isModEnabled("bankconnect")',
				'priority' => 50,
			),
		);

		if (!isModEnabled('bankconnect')) {
			$conf->bankconnect = new stdClass();
			$conf->bankconnect->enabled = 0;
		}

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = 500011;
		$this->rights[$r][1] = 'BankConnectPermRead';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'read';
		$r++;
		$this->rights[$r][0] = 500012;
		$this->rights[$r][1] = 'BankConnectPermWrite';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'write';

		$this->menu = array();
		$this->menu[0] = array(
			'fk_mainmenu' => 'bank',
			'fk_leftmenu' => '',
			'type' => 'left',
			'titre' => 'BankConnectReconcile',
			'mainmenu' => 'bank',
			'leftmenu' => 'bankconnect_reconcile',
			'url' => '/bankconnect/pages/reconcile.php',
			'langs' => 'bankconnect@bankconnect',
			'position' => 500,
			'enabled' => 'isModEnabled("bankconnect")',
			'perms' => '$user->hasRight("bankconnect", "read")',
			'target' => '',
			'user' => 0,
		);
	}

	/** @param string $options Options when enabling module
	 * @return int 1 on success, -1 on error
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/bankconnect/sql/');
		if ($result < 0) {
			return -1;
		}

		$table = MAIN_DB_PREFIX.'bankconnect_transaction';
		$columns = array(
			'fk_bankentry' => 'BIGINT DEFAULT NULL',
			'bank_entry_state' => "VARCHAR(16) NOT NULL DEFAULT 'pending'",
			'bank_entry_error' => 'VARCHAR(255) DEFAULT NULL',
		);
		foreach ($columns as $column => $definition) {
			$description = $this->db->DDLDescTable($table, $column);
			$exists = $description && $this->db->fetch_object($description);
			if (!$exists && !$this->db->query('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition)) {
				return -1;
			}
		}

		return $this->_init(array(), $options);
	}

	/** @param string $options Options when disabling module
	 * @return int 1 on success, -1 on error
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
