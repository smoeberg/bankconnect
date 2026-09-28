<?php
/** Supplier payments and status reconciliation for the current entity. */
require '../../../main.inc.php';
require_once dol_buildpath('/bankconnect/class/PaymentPageService.php', 0);

if (!$user->hasRight('bankconnect', 'write') && empty($user->admin)) accessforbidden();
$langs->load('bankconnect@bankconnect');
$entity = (int)$conf->entity;
$action = GETPOST('action', 'aZ09');
$payments = new PaymentPageService($db, $conf);

if (in_array($action, ['create_batch', 'send_batch', 'refresh_status', 'resolve_unknown'], true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !checkToken()) accessforbidden();
    try {
        if ($action === 'create_batch') {
            $result = $payments->create(
                $entity, GETPOSTINT('fk_agreement'), (array)GETPOST('invoice', 'array'),
                (string)($mysoc->name ?: 'Company'), GETPOST('execution_date', 'alpha'),
                GETPOST('payment_type', 'aZ09') ?: 'dk_transfer', (bool)GETPOSTINT('send_now')
            );
            setEventMessages($langs->trans(GETPOSTINT('send_now') ? 'BankConnectBatchSent' : 'BankConnectBatchCreated',
                $result['batch_id'], $result['nb_of_txs'], price($result['control_sum'])), null);
        } elseif ($action === 'send_batch') {
            $payments->send($entity, GETPOSTINT('batch_id'));
            setEventMessages($langs->trans('BankConnectBatchSubmitted'), null);
        } elseif ($action === 'resolve_unknown') {
            $payments->resolveUnknown($entity, GETPOSTINT('batch_id'));
            setEventMessages($langs->trans('BankConnectStatusUpdated'), null);
        } else {
            $payments->refresh($entity, GETPOSTINT('batch_id'));
            setEventMessages($langs->trans('BankConnectStatusUpdated'), null);
        }
    } catch (Throwable $e) {
        // The underlying exception can contain bank payloads, credentials or SQL.
        setEventMessages($langs->trans('BankConnectPaymentActionFailed'), null, 'errors');
    }
}

llxHeader('', 'BankConnect — '.$langs->trans('BankConnectPayments'));
print load_fiche_titre($langs->trans('BankConnectPayments'), '', 'payment');
try {
    $available = $payments->availableAgreements($entity);
} catch (Throwable $e) {
    $available = [];
    setEventMessages($langs->trans('BankConnectPaymentActionFailed'), null, 'errors');
}
if (!$available) {
    print '<div class="warning">'.$langs->trans('BankConnectPaymentSetupRequired').'</div>';
} else {
    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="create_batch">';
    print '<label>'.$langs->trans('BankConnectPaymentAgreement').' <select name="fk_agreement" required>';
    foreach ($available as $option) {
        $agreement = $option['agreement'];
        $account = $option['account'];
        print '<option value="'.(int)$agreement['rowid'].'">'
            .dol_escape_htmltag($agreement['label'].' — '.$account['label'].' ('.$account['iban'].')').'</option>';
    }
    print '</select></label>';

    print '<div class="div-table-responsive"><table class="tagtable liste">';
    print '<tr class="liste_titre"><th></th><th>'.$langs->trans('Ref').'</th><th>'.$langs->trans('ThirdParty')
        .'</th><th class="right">'.$langs->trans('AmountTTC').'</th><th>IBAN</th><th>'.$langs->trans('Date').'</th></tr>';
    $prefix = MAIN_DB_PREFIX;
    $sql = 'SELECT f.rowid, f.ref, f.total_ttc, f.multicurrency_total_ttc, f.datef, f.multicurrency_code, s.nom, s.iban,'
        .' COALESCE((SELECT SUM(pf.amount) FROM '.$prefix.'paiementfourn_facturefourn pf WHERE pf.fk_facturefourn=f.rowid),0) AS paid_main,'
        .' COALESCE((SELECT SUM(pf.multicurrency_amount) FROM '.$prefix.'paiementfourn_facturefourn pf WHERE pf.fk_facturefourn=f.rowid),0) AS paid_multi'
        .' FROM '.$prefix.'facture_fourn f JOIN '.$prefix.'societe s ON s.rowid=f.fk_soc'
        .' WHERE f.entity='.$entity.' AND f.fk_statut=1 AND f.paye=0 ORDER BY f.datef DESC, f.rowid DESC LIMIT 200';
    $res = $db->query($sql);
    if ($res === false) setEventMessages($langs->trans('BankConnectPaymentActionFailed'), null, 'errors');
    $count = 0;
    while ($res && ($invoice = $db->fetch_object($res))) {
        $currency = strtoupper((string)($invoice->multicurrency_code ?: ($conf->currency ?? 'DKK')));
        $multi = $currency !== strtoupper((string)($conf->currency ?? 'DKK'));
        $remaining = round((float)($multi ? $invoice->multicurrency_total_ttc : $invoice->total_ttc)
            - (float)($multi ? $invoice->paid_multi : $invoice->paid_main), 2);
        if ($remaining <= 0) continue;
        $count++;
        $disabled = empty($invoice->iban) ? ' disabled' : '';
        print '<tr class="oddeven"><td><input type="checkbox" name="invoice[]" value="'.(int)$invoice->rowid.'"'.$disabled.'></td>';
        print '<td>'.dol_escape_htmltag($invoice->ref).'</td><td>'.dol_escape_htmltag($invoice->nom).'</td>';
        print '<td class="right">'.price($remaining).' '.dol_escape_htmltag($currency).'</td>';
        print '<td>'.dol_escape_htmltag($invoice->iban ?: '—').'</td>';
        print '<td>'.dol_print_date($db->jdate($invoice->datef), 'day').'</td></tr>';
    }
    if (!$count) print '<tr><td colspan="6">'.$langs->trans('BankConnectNoUnpaidInvoices').'</td></tr>';
    print '</table></div><br>';
    print '<label>'.$langs->trans('BankConnectExecutionDate').' <input type="date" name="execution_date" value="'
        .dol_print_date(dol_now(), '%Y-%m-%d').'"></label> ';
    print '<label>'.$langs->trans('BankConnectPaymentType').' <select name="payment_type">';
    print '<option value="dk_transfer">'.$langs->trans('BankConnectTypeDkTransfer').'</option>';
    print '<option value="sepa">'.$langs->trans('BankConnectTypeSepa').'</option></select></label> ';
    print '<label><input type="checkbox" name="send_now" value="1"> '.$langs->trans('BankConnectSendNow').'</label> ';
    print '<input type="submit" class="button button-save" value="'.$langs->trans('BankConnectCreateBatch').'">';
    print '</form>';
}

print '<br><h3>'.$langs->trans('BankConnectRecentBatches').'</h3>';
print '<table class="noborder centpercent"><tr class="liste_titre"><th>ID</th><th>MsgId</th><th>Status</th>'
    .'<th class="right">Txs</th><th class="right">Sum</th><th>Sent</th><th></th></tr>';
$res = $db->query('SELECT rowid, msg_id, status, nb_of_txs, control_sum, date_sent FROM '.MAIN_DB_PREFIX
    .'bankconnect_batch WHERE entity='.$entity.' ORDER BY rowid DESC LIMIT 20');
if ($res === false) setEventMessages($langs->trans('BankConnectPaymentActionFailed'), null, 'errors');
$any = false;
while ($res && ($batch = $db->fetch_object($res))) {
    $any = true;
    print '<tr class="oddeven"><td>'.(int)$batch->rowid.'</td><td>'.dol_escape_htmltag($batch->msg_id)
        .'</td><td>'.dol_escape_htmltag($batch->status).'</td><td class="right">'.(int)$batch->nb_of_txs
        .'</td><td class="right">'.price($batch->control_sum).'</td><td>'
        .($batch->date_sent ? dol_print_date($db->jdate($batch->date_sent), 'dayhour') : '—').'</td><td>';
    $batchAction = match ((string)$batch->status) {
        'draft', 'validated', 'prepared' => 'send_batch',
        'unknown' => 'resolve_unknown',
        'submitted', 'pending', 'partial' => 'refresh_status',
        default => null,
    };
    if ($batchAction !== null) {
        $label = match ($batchAction) {
            'send_batch' => 'BankConnectSendBatch',
            'resolve_unknown' => 'BankConnectResolveUnknown',
            default => 'BankConnectRefreshStatus',
        };
        print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">'
            .'<input type="hidden" name="token" value="'.newToken().'">'
            .'<input type="hidden" name="action" value="'.$batchAction.'">'
            .'<input type="hidden" name="batch_id" value="'.(int)$batch->rowid.'">'
            .'<button class="button" type="submit">'.$langs->trans($label).'</button></form>';
    }
    print '</td></tr>';
}
if (!$any) print '<tr><td colspan="7">'.$langs->trans('BankConnectNoBatches').'</td></tr>';
print '</table>';
llxFooter();
