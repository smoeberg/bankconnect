<?php
/* BankConnect module entry point. */

require '../../main.inc.php';

if (!$user->hasRight('bankconnect', 'read')) {
	accessforbidden();
}

header('Location: '.dol_buildpath('/bankconnect/pages/reconcile.php', 1));
exit;
