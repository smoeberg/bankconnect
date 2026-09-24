<?php
/**
 * Admin: BankConnect agreement + certificate onboarding + bank account mapping.
 */

require '../../../main.inc.php';
require_once dol_buildpath('/bankconnect/class/BankConnectCertificateManager.php', 0);
require_once dol_buildpath('/bankconnect/class/AgreementStore.php', 0);
require_once dol_buildpath('/bankconnect/class/BankAccountMappingStore.php', 0);
require_once dol_buildpath('/bankconnect/class/BankConnectException.php', 0);
require_once dol_buildpath('/bankconnect/class/BankConnectClientFactory.php', 0);
require_once dol_buildpath('/bankconnect/class/BankConnectConnectionTestService.php', 0);
require_once dol_buildpath('/bankconnect/class/BankConnectEndpointPolicy.php', 0);
require_once dol_buildpath('/bankconnect/class/BankCertificateStore.php', 0);

if (!$user->admin) {
    accessforbidden();
}

$langs->load('bankconnect@bankconnect');
$action = GETPOST('action', 'aZ09');
$store = new AgreementStore($db);
$mappingStore = new BankAccountMappingStore($db);

if (in_array($action, ['onboard', 'map_account', 'unmap_account', 'test_connection'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkToken()) {
        accessforbidden();
    }
}

if ($action === 'onboard') {
    $activation = GETPOST('activation_code', 'alpha');
    $functionId = GETPOST('function_identification', 'alpha');
    $mainReg = GETPOST('main_registration_number', 'alpha') ?: '8079';
    $label = GETPOST('label', 'alphanohtml');
	$dryRun = 0;
	$environment = GETPOST('environment', 'alpha') === 'production' ? 'production' : 'test';
	$datacenter = strtoupper(GETPOST('datacenter', 'alpha') ?: 'BANKDATA');
	$endpoint = $environment === 'production'
		? 'https://bankconnectservices.dk/2019/04/04/services/CorporateService'
		: 'https://stest.bankconnect.dk/2019/04/04/services/CorporateService';

    try {
		$currentGlobals = (array)($conf->global ?? []);
        if (empty($currentGlobals['BANKCONNECT_KEY_ENCRYPTION_SECRET']) && empty(getenv('BANKCONNECT_KEY_ENCRYPTION_SECRET'))) {
            throw new BankConnectException(
                'Sæt BANKCONNECT_KEY_ENCRYPTION_SECRET i Dolibarr-konstant eller miljø før onboarding.'
            );
        }

		if ($environment === 'test' && $datacenter !== 'BANKDATA') {
			throw new BankConnectException('BankConnects systemtest understøtter kun Bankdata.');
		}
		BankConnectEndpointPolicy::validateBankConnect($endpoint, $environment);
		$onboardConf = clone $conf;
		$onboardGlobals = (array)($conf->global ?? []);
		$onboardGlobals['BANKCONNECT_ENDPOINT'] = $endpoint;
		$onboardGlobals['BANKCONNECT_ENVIRONMENT'] = $environment;
		$onboardGlobals['BANKCONNECT_DATACENTER'] = $datacenter;
		$environmentBankCertificate = getenv('BANKCONNECT_BANK_CERTIFICATE');
		if (empty($onboardGlobals['BANKCONNECT_BANK_CERTIFICATE']) && $environmentBankCertificate !== false) $onboardGlobals['BANKCONNECT_BANK_CERTIFICATE'] = $environmentBankCertificate;
		$onboardConf->global = $onboardGlobals;
		if (!$dryRun && empty($onboardGlobals['BANKCONNECT_BANK_CERTIFICATE']) && getenv('BANKCONNECT_BANK_CERTIFICATE') === false) {
			// Not pre-configured: the manager will attempt automatic GetBankCertificate fetch during onboarding
			// and fail with a clear error if it cannot obtain one.
		}

			$bankCertificateStore = new BankCertificateStore($db, (int)$conf->entity);
			$mgr = new BankConnectCertificateManager($onboardConf, null, null, $store, $bankCertificateStore);
        $result = $mgr->onboard([
            'activation_code'            => $activation,
            'function_identification'    => $functionId,
            'main_registration_number'   => $mainReg,
            'label'                      => $label,
            'entity'                     => $conf->entity,
            'fk_user'                    => $user->id,
			'datacenter'                  => $datacenter,
			'endpoint'                    => $endpoint,
            'dry_run'                    => (bool) $dryRun,
        ]);

        setEventMessages(
            'Agreement #'.$result['agreement_id'].' status='.$result['status']
            .($result['certificate_id'] ? ' cert #'.$result['certificate_id'] : ' (dry-run / no cert yet)'),
            null
        );
    } catch (BankConnectException $e) {
        setEventMessages($e->getMessage(), null, 'errors');
    } catch (Throwable $e) {
        setEventMessages($e->getMessage(), null, 'errors');
    }
} elseif ($action === 'map_account') {
    try {
        $mappingStore->map(
            (int) $conf->entity,
            GETPOSTINT('agreement_id'),
            GETPOSTINT('bank_account_id'),
            (int) $user->id
        );
        setEventMessages('BankConnect-aftalen er nu mappet til Dolibarr-bankkontoen.', null);
    } catch (Throwable $e) {
        setEventMessages($e->getMessage(), null, 'errors');
    }
} elseif ($action === 'unmap_account') {
    try {
        $mappingStore->unmap((int) $conf->entity, GETPOSTINT('agreement_id'));
        setEventMessages('BankConnect-mapping fjernet.', null);
    } catch (Throwable $e) {
        setEventMessages($e->getMessage(), null, 'errors');
    }
} elseif ($action === 'test_connection') {
	try {
			$tester = new BankConnectConnectionTestService($store, $mappingStore, new BankConnectClientFactory($conf, $store, new BankCertificateStore($db, (int)$conf->entity)));
		$tester->test(GETPOSTINT('agreement_id'), (int)$conf->entity);
		setEventMessages($langs->trans('BankConnectConnectionOk'), null);
	} catch (Throwable $e) {
		setEventMessages($langs->trans('BankConnectConnectionFailed').': '.$e->getMessage(), null, 'errors');
	}
}

$agreements = $store->listAgreements((int) $conf->entity);
$bankAccounts = [];
$resAccounts = $db->query(
    'SELECT rowid, label, ref, account_number FROM '.MAIN_DB_PREFIX.'bank_account'
    .' WHERE entity IN ('.getEntity('bank_account').') AND clos = 0 ORDER BY label'
);
while ($resAccounts && ($account = $db->fetch_object($resAccounts))) {
    $bankAccounts[] = $account;
}

llxHeader('', 'BankConnect certificates');
print load_fiche_titre($langs->trans('BankConnectConnectTitle'), '', 'technic');

print '<div class="info">'.$langs->trans('BankConnectConnectIntro').'</div>';
print '<div class="fichecenter"><div class="fichehalfleft"><div class="underbanner clearboth"></div>';
print '<h3>1. '.$langs->trans('BankConnectActivateAgreement').'</h3>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="onboard">';
print '<table class="border centpercent">';
print '<tr><td>Label</td><td><input name="label" class="minwidth200" value="Testaftale"></td></tr>';
print '<tr><td>Function identification (Bank Connect ID)</td><td><input name="function_identification" class="minwidth200" required placeholder="0010888100007"></td></tr>';
print '<tr><td>Main registration number</td><td><input name="main_registration_number" value="8079" class="minwidth100"> (8079 = test/Sydbank)</td></tr>';
print '<tr><td>Activation code (SMS)</td><td><input type="password" name="activation_code" class="minwidth200" required autocomplete="new-password"></td></tr>';
print '<tr><td>'.$langs->trans('BankConnectEnvironment').'</td><td><select name="environment"><option value="test">Systemtest</option><option value="production">Produktion</option></select></td></tr>';
print '<tr><td>'.$langs->trans('BankConnectDatacenter').'</td><td><select name="datacenter"><option value="BANKDATA">Bankdata</option><option value="NBS">NBS/SDC</option><option value="BEC">BEC</option></select></td></tr>';
print '</table>';
print '<br><input type="submit" class="button button-save" value="'.$langs->trans('BankConnectActivateAgreement').'">';
print '</form>';
print '</div><div class="fichehalfright"><div class="underbanner clearboth"></div>';
print '<h3>'.$langs->trans('BankConnectBeforeConnect').'</h3><ol><li>'.$langs->trans('BankConnectNeedAgreement').'</li><li>'.$langs->trans('BankConnectNeedSecret').'</li><li>'.$langs->trans('BankConnectNeedBankCertificate').'</li></ol>';
print '<p>'.$langs->trans('BankConnectOfficialEnvironmentHelp').'</p></div></div><div class="clearboth"></div>';

print '<br><h3>2. '.$langs->trans('BankConnectMapAccount').'</h3>';
print '<p>Hver BankConnect-aftale kan mappes til én åben Dolibarr-bankkonto i den aktuelle entity. Bankkontoen genbruges fra Dolibarr; BankConnect opretter ikke en parallel konto.</p>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="map_account">';
print '<select name="agreement_id" required><option value="">Vælg aftale</option>';
foreach ($agreements as $a) {
    print '<option value="'.(int)$a['rowid'].'">'.dol_escape_htmltag($a['label'].' (#'.$a['rowid'].')').'</option>';
}
print '</select> ';
print '<select name="bank_account_id" required><option value="">Vælg bankkonto</option>';
foreach ($bankAccounts as $account) {
    $label = ($account->label ?: $account->ref).' (#'.$account->rowid.')';
    if (!empty($account->account_number)) {
        $label .= ' — '.$account->account_number;
    }
    print '<option value="'.(int)$account->rowid.'">'.dol_escape_htmltag($label).'</option>';
}
print '</select> ';
print '<input type="submit" class="button button-save" value="Gem mapping">';
print '</form>';

print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>Aftale</th><th>BankConnect ID</th><th>Dolibarr bankkonto</th><th></th></tr>';
foreach ($mappingStore->listMappings((int)$conf->entity) as $mapping) {
    $agreement = $store->getAgreement((int)$mapping['fk_agreement']);
    $bank = null;
    $resBank = $db->query('SELECT rowid, label, ref, account_number FROM '.MAIN_DB_PREFIX.'bank_account WHERE rowid = '.(int)$mapping['fk_bank_account'].' AND entity IN ('.getEntity('bank_account').')');
    if ($resBank) {
        $bank = $db->fetch_object($resBank);
    }
    print '<tr class="oddeven">';
    print '<td>'.dol_escape_htmltag(($agreement['label'] ?? 'Agreement').' (#'.$mapping['fk_agreement'].')').'</td>';
    print '<td>'.dol_escape_htmltag($agreement['bank_connect_id'] ?? '').'</td>';
    print '<td>'.($bank ? dol_escape_htmltag(($bank->label ?: $bank->ref).' (#'.$bank->rowid.')') : '—').'</td>';
    print '<td>';
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="unmap_account">';
    print '<input type="hidden" name="agreement_id" value="'.(int)$mapping['fk_agreement'].'">';
    print '<input type="submit" class="button" value="Fjern">';
    print '</form>';
    print '</td>';
    print '</tr>';
}
print '</table>';

print '<br><h3>3. '.$langs->trans('BankConnectTestConnection').'</h3>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>ID</th><th>Label</th><th>BC ID</th><th>Status</th><th>Aktiv cert</th><th>'.$langs->trans('BankConnectLastTest').'</th><th></th></tr>';
foreach ($agreements as $a) {
    $cert = $store->getActiveCertificate((int) $a['rowid']);
    print '<tr class="oddeven">';
    print '<td>'.(int) $a['rowid'].'</td>';
    print '<td>'.dol_escape_htmltag($a['label'] ?? '').'</td>';
    print '<td>'.dol_escape_htmltag($a['bank_connect_id'] ?? '').'</td>';
    print '<td>'.dol_escape_htmltag($a['status'] ?? '').'</td>';
    print '<td>'.($cert ? '#'.$cert['rowid'].' (til '.$cert['valid_to'].')' : '—').'</td>';
	print '<td>'.dol_escape_htmltag(($a['last_connection_status'] ?? '') ?: '—').(!empty($a['last_connection_test']) ? '<br><small>'.dol_print_date($a['last_connection_test'], 'dayhour').'</small>' : '').(!empty($a['last_connection_error']) ? '<br><small class="error">'.dol_escape_htmltag($a['last_connection_error']).'</small>' : '').'</td>';
	print '<td><form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="test_connection"><input type="hidden" name="agreement_id" value="'.(int)$a['rowid'].'"><button class="button">'.$langs->trans('BankConnectTestConnection').'</button></form></td>';
    print '</tr>';
}
print '</table>';

llxFooter();
