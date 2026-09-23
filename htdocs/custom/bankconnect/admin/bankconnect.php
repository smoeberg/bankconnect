<?php
/* BankConnect admin page: Mistral API key, confidence threshold, default account. */

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

if (!$user->admin) {
	accessforbidden();
}

$langs->load('admin');
$langs->load('bankconnect@bankconnect');

$action = GETPOST('action', 'alpha');

if ($action === 'save' && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	if (!checkToken()) {
		accessforbidden();
	}
	dolibarr_set_const($db, 'BANKCONNECT_AI_THRESHOLD', (float)GETPOST('threshold', 'alpha'), 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('Saved'), null);
}

llxHeader('', 'BankConnect');
print load_fiche_titre('BankConnect — '.$langs->trans('Setup'), '', 'bank');
print '<div class="info">'.$langs->trans('BankConnectConnectIntro').' <a class="button button-save" href="'.dol_buildpath('/bankconnect/admin/certificates.php', 1).'">'.$langs->trans('BankConnectConnectTitle').'</a></div><br>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr><td>Mistral API key</td><td><input type="password" name="mistralkey" value="" placeholder="Set via BANKCONNECT_MISTRAL_API_KEY environment variable" autocomplete="off"></td></tr>';
print '<tr><td>AI confidence threshold</td><td><input type="text" name="threshold" value="'.dol_escape_htmltag($conf->global->BANKCONNECT_AI_THRESHOLD ?? '0.85').'" size="6"></td></tr>';
print '</table>';
print '<input type="submit" class="button" value="'.$langs->trans('Save').'">';
print '</form>';
llxFooter();
