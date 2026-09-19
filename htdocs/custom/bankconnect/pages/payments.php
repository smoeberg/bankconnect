<?php
/**
 * BankConnect – send payments (pain.001) from unpaid supplier invoices.
 */

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/custom/bankconnect/class/Pain001Builder.php';
require_once DOL_DOCUMENT_ROOT.'/custom/bankconnect/class/PaymentBatchService.php';
require_once DOL_DOCUMENT_ROOT.'/custom/bankconnect/class/BankConnectException.php';

if (empty($user->rights->bankconnect->write) && empty($user->admin)) {
    accessforbidden();
}

$langs->load('bankconnect@bankconnect');
$action = GETPOST('action', 'aZ09');
$fkAgreement = GETPOSTINT('fk_agreement') ?: 1;

/*
 * Actions
 */
if ($action === 'create_batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected = GETPOST('invoice', 'array');
    if (empty($selected) || !is_array($selected)) {
        setEventMessages($langs->trans('BankConnectNoInvoicesSelected'), null, 'warnings');
    } else {
        try {
            $builder = new Pain001Builder();
            $builder->setInitiatingParty($mysoc->name ?: 'Company');

            // Debtor from first bank account with IBAN (simplified)
            $debtorIban = '';
            $debtorBic  = '';
            $sqlBank = "SELECT iban_prefix, number, bic, label FROM ".MAIN_DB_PREFIX."bank_account"
                     . " WHERE entity IN (".getEntity('bank_account').") AND clos = 0 ORDER BY rowid ASC LIMIT 1";
            $resBank = $db->query($sqlBank);
            if ($resBank && ($b = $db->fetch_object($resBank))) {
                $debtorIban = ($b->iban_prefix ?: '').($b->number ?: '');
                $debtorBic  = $b->bic ?: '';
            }
            if ($debtorIban === '') {
                throw new BankConnectException('No open bank account with IBAN configured');
            }
            $builder->setDebtor($mysoc->name ?: 'Company', $debtorIban, $debtorBic);

            $execDate = GETPOST('execution_date', 'alpha');
            if ($execDate) {
                $builder->setExecutionDate(new DateTimeImmutable($execDate));
            }

            $payType = GETPOST('payment_type', 'aZ09') ?: 'dk_transfer';
            $builder->setPaymentType(
                $payType === 'sepa' ? Pain001Builder::TYPE_SEPA : Pain001Builder::TYPE_DK_TRANSFER
            );

            foreach ($selected as $facId) {
                $facId = (int) $facId;
                $sql = "SELECT f.rowid, f.ref, f.total_ttc, f.multicurrency_code, s.nom, s.iban, s.bic"
                     . " FROM ".MAIN_DB_PREFIX."facture_fourn AS f"
                     . " LEFT JOIN ".MAIN_DB_PREFIX."societe AS s ON s.rowid = f.fk_soc"
                     . " WHERE f.rowid = ".$facId
                     . " AND f.fk_statut = 1 AND f.paye = 0"; // validated, unpaid
                $res = $db->query($sql);
                $fac = $res ? $db->fetch_object($res) : null;
                if (!$fac || empty($fac->iban)) {
                    continue;
                }

                $endToEndId = substr(preg_replace('/[^A-Za-z0-9]/', '', $fac->ref), 0, 35);
                if ($endToEndId === '') {
                    $endToEndId = 'FF'.$fac->rowid;
                }

                $builder->addTransaction([
                    'endToEndId'       => $endToEndId,
                    'amount'           => (float) $fac->total_ttc,
                    'currency'         => $fac->multicurrency_code ?: 'DKK',
                    'creditorName'     => $fac->nom,
                    'creditorIban'     => $fac->iban,
                    'creditorBic'      => $fac->bic ?: '',
                    'remittance'       => $fac->ref,
                    'fk_facture_fourn' => (int) $fac->rowid,
                ]);
            }

            if (count($builder->getTransactions()) === 0) {
                throw new BankConnectException($langs->trans('BankConnectNoValidInvoices'));
            }

            $svc = new PaymentBatchService($db, $conf);
            $result = $svc->createBatch($builder, $fkAgreement, $conf->entity);

            // Optionally send immediately
            if (GETPOST('send_now', 'int')) {
                $sent = $svc->sendBatch($result['batch_id']);
                setEventMessages(
                    $langs->trans('BankConnectBatchSent', $result['batch_id'], $result['nb_of_txs'], price($result['control_sum'])),
                    null
                );
            } else {
                setEventMessages(
                    $langs->trans('BankConnectBatchCreated', $result['batch_id'], $result['nb_of_txs'], price($result['control_sum'])),
                    null
                );
            }
        } catch (BankConnectException $e) {
            setEventMessages($e->getMessage(), null, 'errors');
        } catch (Throwable $e) {
            setEventMessages('Unexpected error: '.$e->getMessage(), null, 'errors');
        }
    }
}

/*
 * View – unpaid supplier invoices
 */
llxHeader('', 'BankConnect — '.$langs->trans('BankConnectPayments'));

print load_fiche_titre($langs->trans('BankConnectPayments'), '', 'payment');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="create_batch">';
print '<input type="hidden" name="fk_agreement" value="'.((int) $fkAgreement).'">';

print '<div class="div-table-responsive">';
print '<table class="tagtable liste">';
print '<tr class="liste_titre">';
print '<th><input type="checkbox" id="checkall" onclick="jQuery(\'.checkforselect\').prop(\'checked\', this.checked);"></th>';
print '<th>'.$langs->trans('Ref').'</th>';
print '<th>'.$langs->trans('ThirdParty').'</th>';
print '<th class="right">'.$langs->trans('AmountTTC').'</th>';
print '<th>IBAN</th>';
print '<th>'.$langs->trans('Date').'</th>';
print '</tr>';

$sql = "SELECT f.rowid, f.ref, f.total_ttc, f.datef, f.multicurrency_code, s.nom, s.iban"
     . " FROM ".MAIN_DB_PREFIX."facture_fourn AS f"
     . " LEFT JOIN ".MAIN_DB_PREFIX."societe AS s ON s.rowid = f.fk_soc"
     . " WHERE f.entity IN (".getEntity('facture_fourn').")"
     . " AND f.fk_statut = 1 AND f.paye = 0"
     . " ORDER BY f.datef DESC, f.rowid DESC"
     . " LIMIT 200";
$res = $db->query($sql);
$n = 0;
while ($res && ($obj = $db->fetch_object($res))) {
    $n++;
    $disabled = empty($obj->iban) ? ' disabled title="Mangler IBAN"' : '';
    print '<tr class="oddeven">';
    print '<td><input type="checkbox" class="checkforselect" name="invoice[]" value="'.$obj->rowid.'"'.$disabled.'></td>';
    print '<td>'.dol_escape_htmltag($obj->ref).'</td>';
    print '<td>'.dol_escape_htmltag($obj->nom).'</td>';
    print '<td class="right">'.price($obj->total_ttc).' '.dol_escape_htmltag($obj->multicurrency_code ?: 'DKK').'</td>';
    print '<td>'.dol_escape_htmltag($obj->iban ?: '—').'</td>';
    print '<td>'.dol_print_date($db->jdate($obj->datef), 'day').'</td>';
    print '</tr>';
}
if ($n === 0) {
    print '<tr><td colspan="6">'.$langs->trans('BankConnectNoUnpaidInvoices').'</td></tr>';
}
print '</table>';
print '</div>';

print '<br>';
print '<label>'.$langs->trans('BankConnectExecutionDate').' </label>';
print '<input type="date" name="execution_date" value="'.dol_print_date(dol_now(), '%Y-%m-%d').'"> ';
print '<label>'.$langs->trans('BankConnectPaymentType').' </label>';
print '<select name="payment_type">';
print '<option value="dk_transfer">'.$langs->trans('BankConnectTypeDkTransfer').'</option>';
print '<option value="sepa">'.$langs->trans('BankConnectTypeSepa').'</option>';
print '</select> ';
print '<label><input type="checkbox" name="send_now" value="1"> '.$langs->trans('BankConnectSendNow').'</label> ';
print '<input type="submit" class="button button-save" value="'.$langs->trans('BankConnectCreateBatch').'">';
print '</form>';

// Recent batches
print '<br><h3>'.$langs->trans('BankConnectRecentBatches').'</h3>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>ID</th><th>MsgId</th><th>Status</th><th class="right">Txs</th><th class="right">Sum</th><th>Sent</th></tr>';
$sqlB = "SELECT rowid, msg_id, status, nb_of_txs, control_sum, date_sent"
      . " FROM ".MAIN_DB_PREFIX."bankconnect_batch"
      . " WHERE entity = ".((int) $conf->entity)
      . " ORDER BY rowid DESC LIMIT 20";
$resB = $db->query($sqlB);
$anyB = false;
while ($resB && ($b = $db->fetch_object($resB))) {
    $anyB = true;
    print '<tr class="oddeven">';
    print '<td>'.$b->rowid.'</td>';
    print '<td>'.dol_escape_htmltag($b->msg_id).'</td>';
    print '<td>'.dol_escape_htmltag($b->status).'</td>';
    print '<td class="right">'.((int) $b->nb_of_txs).'</td>';
    print '<td class="right">'.price($b->control_sum).'</td>';
    print '<td>'.($b->date_sent ? dol_print_date($db->jdate($b->date_sent), 'dayhour') : '—').'</td>';
    print '</tr>';
}
if (!$anyB) {
    print '<tr><td colspan="6">'.$langs->trans('BankConnectNoBatches').'</td></tr>';
}
print '</table>';

llxFooter();
