<?php
/* BankConnect reconciliation screen: unmatched transactions, match proposals, approve/reject. */
/* No auto-posting: every approval is explicit. */

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/custom/bankconnect/class/CamtParser.php';
require_once DOL_DOCUMENT_ROOT.'/custom/bankconnect/class/ReconciliationEngine.php';
require_once DOL_DOCUMENT_ROOT.'/custom/bankconnect/class/BankConnectStore.php';
require_once DOL_DOCUMENT_ROOT.'/custom/bankconnect/class/ImportService.php';

if (!$user->rights->bankconnect->read) {
	accessforbidden();
}

$langs->load('bankconnect@bankconnect');
$store = new BankConnectStore($db);
$accountid = GETPOST('account', 'int') ?: 0;
$action = GETPOST('action', 'alpha');

/*
 * Actions (write permission required)
 */
if ($action === 'import' && $user->rights->bankconnect->write) {
	if (!empty($_FILES['camtfile']['tmp_name'])) {
		try {
			$service = new ImportService($store);
			$result = $service->importFile($_FILES['camtfile']['tmp_name'], $accountid, $_FILES['camtfile']['name']);
			$store->audit($user->id, 'import', $result['total'].' tx from '.$_FILES['camtfile']['name'].' ('.$result['imported'].' new, '.$result['duplicates'].' duplicates)');
			setEventMessages($langs->trans('BankConnectImportOk', $result['imported'], $result['duplicates']), null);
		} catch (RuntimeException $e) {
			setEventMessages($langs->trans('BankConnectImportFailed').': '.$e->getMessage(), null, 'errors');
		}
	}
} elseif ($action === 'match' && $user->rights->bankconnect->write) {
	$engine = new ReconciliationEngine();
	$engine->setLogger(function ($m) { dol_syslog('BankConnect: '.$m); });
	foreach ($store->unmatchedTransactions($accountid) as $tx) {
		$results = $engine->reconcile($tx);
		foreach ($results as $r) {
			$store->saveMatch($tx['rowid'], $r->matchType, $r->ruleName ?? null, $r->bankEntryId ?? null, $r->score, $r->reason);
		}
	}
} elseif ($action === 'approve' && $user->rights->bankconnect->write) {
	$store->approveMatch((int)GETPOST('matchid', 'int'), $user->id);
	setEventMessages($langs->trans('BankConnectApproved'), null);
} elseif ($action === 'reject' && $user->rights->bankconnect->write) {
	$store->rejectMatch((int)GETPOST('matchid', 'int'), $user->id);
	setEventMessages($langs->trans('BankConnectRejected'), null);
} elseif ($action === 'post' && $user->rights->bankconnect->write) {
	require_once DOL_DOCUMENT_ROOT.'/custom/bankconnect/class/ApprovalPosting.php';
	$poster = new ApprovalPosting($db, $store);
	$poster->setLogger(function ($m) { dol_syslog('BankConnect: '.$m); });
	$n = $poster->postAllApproved($user->id, $accountid);
	setEventMessages($langs->trans('BankConnectPosted', $n), null);
}

/*
 * View
 */
llxHeader('', 'BankConnect');

print load_fiche_titre('BankConnect — '.$langs->trans('BankConnectReconcile'), '', 'bank');

// Account selector + import form
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="import">';
print '<select name="account">';
// populate from Dolibarr bank accounts
$sql = 'SELECT rowid, label FROM llx_bank_account ORDER BY label';
$res = $db->query($sql);
while ($res && $o = $db->fetch_object($res)) {
	print '<option value="'.$o->rowid.'"'.($accountid == $o->rowid ? ' selected' : '').'>'.dol_escape_htmltag($o->label).'</option>';
}
print '</select> ';
print '<input type="file" name="camtfile" accept=".xml,.csv"> ';
print '<input type="submit" class="button" value="'.$langs->trans('BankConnectImport').'">';
print '</form>';

// Unmatched list
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
print '<tr class="liste_titre"><th>Date</th><th>Amount</th><th>Ref</th><th>Counterparty</th><th>Proposal</th><th></th></tr>';
foreach ($unmatched as $tx) {
	print '<tr>';
	print '<td>'.dol_print_date($tx['tx_date'], 'day').'</td>';
	print '<td>'.price($tx['amount']).' '.$tx['currency'].'</td>';
	print '<td>'.dol_escape_htmltag($tx['reference'] ?? '').'</td>';
	print '<td>'.dol_escape_htmltag($tx['counterparty'] ?? '').'</td>';
	print '<td>—</td><td></td>';
	print '</tr>';
}
print '</table>';

// Post approved matches button
if ($accountid) {
	$approvedCount = 0;
	$sqlc = "SELECT COUNT(*) AS c FROM llx_bankconnect_transaction WHERE fk_bank_account = ".(int)$accountid." AND state = 'approved'";
	$resc = $db->query($sqlc);
	$approvedCount = $resc ? (int)$db->fetch_object($resc)->c : 0;
	if ($approvedCount > 0) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="account" value="'.$accountid.'">';
		print '<input type="hidden" name="action" value="post">';
		print '<input type="submit" class="button button-save" value="'.$langs->trans('BankConnectPostApproved', $approvedCount).'">';
		print '</form>';
	}
}

// Proposed matches awaiting approval
if ($accountid) {
	$sql = "SELECT m.rowid AS mid, m.match_type, m.rule_name, m.score, m.reason, t.tx_date, t.amount, t.currency, t.reference, t.counterparty
			FROM llx_bankconnect_match m
			JOIN llx_bankconnect_transaction t ON t.rowid = m.fk_transaction
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
		print '<a class="button button-success" href="'.$_SERVER['PHP_SELF'].'?account='.$accountid.'&action=approve&matchid='.$o->mid.'&token='.newToken().'">'.$langs->trans('BankConnectApprove').'</a> ';
		print '<a class="button" href="'.$_SERVER['PHP_SELF'].'?account='.$accountid.'&action=reject&matchid='.$o->mid.'&token='.newToken().'">'.$langs->trans('BankConnectReject').'</a>';
		print '</td>';
		print '</tr>';
	}
	if (!$any) {
		print '<tr><td colspan="7">'.$langs->trans('BankConnectNothingPending').'</td></tr>';
	}
	print '</table>';
}

llxFooter();
