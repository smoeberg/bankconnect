<?php
/**
 * Admin: BankConnect agreement + certificate onboarding.
 */

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/custom/bankconnect/class/BankConnectCertificateManager.php';
require_once DOL_DOCUMENT_ROOT.'/custom/bankconnect/class/AgreementStore.php';
require_once DOL_DOCUMENT_ROOT.'/custom/bankconnect/class/BankConnectException.php';

if (!$user->admin) {
    accessforbidden();
}

$langs->load('bankconnect@bankconnect');
$action = GETPOST('action', 'aZ09');
$store = new AgreementStore($db);

if ($action === 'onboard' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $activation = GETPOST('activation_code', 'alphanohtml');
    $functionId = GETPOST('function_identification', 'alphanohtml');
    $mainReg = GETPOST('main_registration_number', 'alphanohtml') ?: '8079';
    $label = GETPOST('label', 'alphanohtml');
    $dryRun = GETPOSTINT('dry_run');

    try {
        if (empty($conf->global->BANKCONNECT_KEY_ENCRYPTION_SECRET) && empty(getenv('BANKCONNECT_KEY_ENCRYPTION_SECRET'))) {
            throw new BankConnectException(
                'Sæt BANKCONNECT_KEY_ENCRYPTION_SECRET i Dolibarr-konstant eller miljø før onboarding.'
            );
        }

        $mgr = new BankConnectCertificateManager($conf, null, null, $store);
        $result = $mgr->onboard([
            'activation_code'            => $activation,
            'function_identification'    => $functionId,
            'main_registration_number'   => $mainReg,
            'label'                      => $label,
            'entity'                     => $conf->entity,
            'fk_user'                    => $user->id,
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
}

llxHeader('', 'BankConnect certificates');
print load_fiche_titre('BankConnect — Certificates / Onboarding', '', 'technic');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="onboard">';
print '<table class="border centpercent">';
print '<tr><td>Label</td><td><input name="label" class="minwidth200" value="Testaftale"></td></tr>';
print '<tr><td>Function identification (Bank Connect ID)</td><td><input name="function_identification" class="minwidth200" required placeholder="0010888100007"></td></tr>';
print '<tr><td>Main registration number</td><td><input name="main_registration_number" value="8079" class="minwidth100"> (8079 = test/Sydbank)</td></tr>';
print '<tr><td>Activation code (SMS)</td><td><input name="activation_code" class="minwidth200" required></td></tr>';
print '<tr><td>Dry-run (ingen SOAP)</td><td><input type="checkbox" name="dry_run" value="1" checked> Generér CSR + gem aftale uden bankkald</td></tr>';
print '</table>';
print '<br><input type="submit" class="button button-save" value="Onboard">';
print '</form>';

print '<br><h3>Eksisterende aftaler</h3>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>ID</th><th>Label</th><th>BC ID</th><th>Status</th><th>Aktiv cert</th></tr>';
foreach ($store->listAgreements((int) $conf->entity) as $a) {
    $cert = $store->getActiveCertificate((int) $a['rowid']);
    print '<tr class="oddeven">';
    print '<td>'.(int) $a['rowid'].'</td>';
    print '<td>'.dol_escape_htmltag($a['label'] ?? '').'</td>';
    print '<td>'.dol_escape_htmltag($a['bank_connect_id'] ?? '').'</td>';
    print '<td>'.dol_escape_htmltag($a['status'] ?? '').'</td>';
    print '<td>'.($cert ? '#'.$cert['rowid'].' (til '.$cert['valid_to'].')' : '—').'</td>';
    print '</tr>';
}
print '</table>';

llxFooter();
