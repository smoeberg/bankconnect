<?php
/* BankConnect reconciliation screen: unmatched transactions, match proposals, approve/reject. */
/* No auto-posting: every approval is explicit. */

require '../../../main.inc.php';
require_once dol_buildpath('/bankconnect/class/CamtParser.php', 0);
require_once dol_buildpath('/bankconnect/class/ReconciliationEngine.php', 0);
require_once dol_buildpath('/bankconnect/class/BankConnectStore.php', 0);
require_once dol_buildpath('/bankconnect/class/ImportService.php', 0);
require_once dol_buildpath('/bankconnect/class/DolibarrBankEntryService.php', 0);
require_once dol_buildpath('/bankconnect/class/DolibarrCandidateProvider.php', 0);
require_once dol_buildpath('/bankconnect/class/ReconciliationService.php', 0);

if (!$user->hasRight('bankconnect', 'read')) {
	accessforbidden();
}

$langs->load('bankconnect@bankconnect');
$store = new BankConnectStore($db);
$accountid = GETPOST('account', 'int') ?: 0;
$action = GETPOST('action', 'alpha');
$requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$writeAction = in_array($action, ['import', 'match', 'approve', 'reject'], true);
if ($writeAction) {
	if ($requestMethod !== 'POST' || !checkToken()) {
		accessforbidden();
	}
}

if ($action === 'import' && $user->hasRight('bankconnect', 'write')) {
	$upload = $_FILES['camtfile'] ?? null;
	if ($upload !== null) {
		$uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
		if ($uploadError !== UPLOAD_ERR_OK) {
			setEventMessages('BankConnect import upload failed (error '.$uploadError.')', null, 'errors');
		} elseif (empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
			setEventMessages('BankConnect import upload is invalid', null, 'errors');
		} else {
			$originalName = (string) ($upload['name'] ?? 'import');
			$extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
			$maxSize = 10 * 1024 * 1024;
			if ((int) ($upload['size'] ?? 0) > $maxSize) {
				setEventMessages($langs->trans('BankConnectFileTooLarge'), null, 'errors');
			} elseif (!function_exists('finfo_open')) {
				setEventMessages($langs->trans('BankConnectInvalidFileType'), null, 'errors');
			} else {
				$finfo = finfo_open(FILEINFO_MIME_TYPE);
				$mime = $finfo !== false ? finfo_file($finfo, $upload['tmp_name']) : false;
				if ($finfo !== false) {
					finfo_close($finfo);
				}
				$allowedMimes = ['application/xml', 'text/xml'];
				if ($extension !== 'xml' || !is_string($mime) || !in_array($mime, $allowedMimes, true)) {
				setEventMessages('BankConnect import requires a valid XML CAMT upload', null, 'errors');
			} else {
				try {
					$service = new ImportService($store, null, new DolibarrBankEntryService($db));
					$result = $service->importFile($upload['tmp_name'], $accountid, $originalName, $user);
					$store->audit($user->id, 'import', $result['total'].' tx from '.$originalName.' ('.$result['imported'].' new, '.$result['duplicates'].' duplicates)');
					setEventMessages($langs->trans('BankConnectImportOk', $result['imported'], $result['duplicates']), null);
				} catch (RuntimeException $e) {
					setEventMessages($langs->trans('BankConnectImportFailed').': '.$e->getMessage(), null, 'errors');
				}
				}
			}
		}
	}
} elseif ($action === 'match' && $user->hasRight('bankconnect', 'write')) {
	$engine = new ReconciliationEngine($conf);
	$reconciliation = new ReconciliationService($engine, $store);
	$provider = new DolibarrCandidateProvider($db, (string)($conf->currency ?? 'DKK'));
	foreach ($store->unmatchedTransactions($accountid) as $tx) {
		$transaction = BankTransaction::fromArray($tx);
		$candidates = $provider->forTransaction($transaction, (int)$conf->entity);
		$reconciliation->propose((int)$tx['rowid'], (int)$user->id, $transaction, $candidates);
	}
} elseif ($action === 'approve' && $user->hasRight('bankconnect', 'write')) {
	$store->approveMatch((int)GETPOST('matchid', 'int'), $user->id);
	setEventMessages($langs->trans('BankConnectApproved'), null);
} elseif ($action === 'reject' && $user->hasRight('bankconnect', 'write')) {
	$store->rejectMatch((int)GETPOST('matchid', 'int'), $user->id);
	setEventMessages($langs->trans('BankConnectRejected'), null);
}

llxHeader('', 'BankConnect');

print load_fiche_titre('BankConnect — '.$langs->trans('BankConnectReconcile'), '', 'bank');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="import">';
print '<select name="account">';
$sql = 'SELECT rowid, label FROM '.MAIN_DB_PREFIX.'bank_account ORDER BY label';
$res = $db->query($sql);
while ($res && $o = $db->fetch_object($res)) {
	print '<option value="'.$o->rowid.'"'.($accountid == $o->rowid ? ' selected' : '').'>'.dol_escape_htmltag($o->label).'</option>';
}
print '</select> ';
print '<input type="file" name="camtfile" accept=".xml"> ';
print '<input type="submit" class="button" value="'.$langs->trans('BankConnectImport').'">';
print '</form>';

$unmatched = $accountid ? $store->unmatchedTransactions($accountid) : [];
print '<p>'.dol_escape_htmltag($langs->trans('BankConnectUnmatchedCount', count($unmatched))).'</p>';

if (count($unmatched)) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="account" value="'.$accountid.'">';
	print '<input type="hidden" name="action" value="match">';
	print '<input type="submit" class="button" value="'.$langs->trans('BankConnectRunMatching').'">';
	print '</form>';
}

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>Date</th><th>Amount</th><th>Ref</th><th>Counterparty</th><th>'.$langs->trans('BankConnectBankEntry').'</th><th>Status</th><th>Proposal</th><th></th></tr>';
foreach ($unmatched as $tx) {
	print '<tr>';
	print '<td>'.dol_print_date($tx['tx_date'], 'day').'</td>';
	print '<td>'.price($tx['amount']).' '.$tx['currency'].'</td>';
	print '<td>'.dol_escape_htmltag($tx['reference'] ?? '').'</td>';
	print '<td>'.dol_escape_htmltag($tx['counterparty'] ?? '').'</td>';
	print '<td>';
	if (!empty($tx['fk_bankentry'])) {
		print '<a href="'.DOL_URL_ROOT.'/compta/bank/line.php?rowid='.(int)$tx['fk_bankentry'].'">#'.(int)$tx['fk_bankentry'].'</a>';
	} elseif (($tx['bank_entry_state'] ?? '') === 'error') {
		print '<span class="badge badge-danger" title="'.dol_escape_htmltag($tx['bank_entry_error'] ?? '').'">'.$langs->trans('Error').'</span>';
	} else {
		print '—';
	}
	print '</td>';
	print '<td>';
	if (!empty($tx['requires_manual_review'])) {
		print '<span class="badge badge-warning">Manuel gennemgang</span>';
	}
	if (!empty($tx['is_reversal'])) {
		print ' <span class="badge badge-danger">Reversal</span>';
	}
	if (empty($tx['requires_manual_review']) && empty($tx['is_reversal'])) {
		print '—';
	}
	print '</td>';
	print '<td>—</td><td></td>';
	print '</tr>';
}
print '</table>';

if ($accountid) {
	$sql = "SELECT m.rowid AS mid, m.match_type, m.rule_name, m.score, m.reason, t.tx_date, t.amount, t.currency, t.reference, t.counterparty
			FROM ".MAIN_DB_PREFIX."bankconnect_match m
			JOIN ".MAIN_DB_PREFIX."bankconnect_transaction t ON t.rowid = m.fk_transaction
			WHERE t.fk_bank_account = ".(int)$accountid." AND t.state = 'proposed' AND m.approved_by IS NULL
			ORDER BY t.tx_date DESC";
	$res = $db->query($sql);
	$any = false;
	print '<h3>'.$langs->trans('BankConnectAwaitingApproval').'</h3>';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>Date</th><th>Amount</th><th>Ref</th><th>Match</th><th>Score</th><th>Reason</th><th></th></tr>';
	while ($res && $o = $db->fetch_object($res)) {
		$any = true;
		print '<tr>';
		print '<td>'.dol_print_date($o->tx_date, 'day').'</td>';
		print '<td>'.price($o->amount).' '.$o->currency.'</td>';
		print '<td>'.dol_escape_htmltag($o->reference ?? '').'</td>';
		print '<td>'.dol_escape_htmltag($o->match_type.($o->rule_name ? ' ('.$o->rule_name.')' : '')).'</td>';
		print '<td>'.round(100 * (float)$o->score).'%</td>';
		print '<td>'.dol_escape_htmltag($o->reason ?? '').'</td>';
		print '<td>';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="account" value="'.$accountid.'">';
		print '<input type="hidden" name="action" value="approve">';
		print '<input type="hidden" name="matchid" value="'.$o->mid.'">';
		print '<input type="submit" class="button button-success" value="'.$langs->trans('BankConnectApprove').'">';
		print '</form> ';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="account" value="'.$accountid.'">';
		print '<input type="hidden" name="action" value="reject">';
		print '<input type="hidden" name="matchid" value="'.$o->mid.'">';
		print '<input type="submit" class="button" value="'.$langs->trans('BankConnectReject').'">';
		print '</form>';
		print '</td>';
		print '</tr>';
	}
	if (!$any) {
		print '<tr><td colspan="7">'.$langs->trans('BankConnectNothingPending').'</td></tr>';
	}
	print '</table>';
}

llxFooter();
