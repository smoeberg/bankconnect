<?php
/* BankConnect reconciliation screen: unmatched transactions, match proposals, approve/reject. */
/* Approval may create/link a native Dolibarr payment; transfer to accounting remains standard Dolibarr. */

require '../../../main.inc.php';
require_once dol_buildpath('/bankconnect/class/CamtParser.php', 0);
require_once dol_buildpath('/bankconnect/class/ReconciliationEngine.php', 0);
require_once dol_buildpath('/bankconnect/class/BankConnectStore.php', 0);
require_once dol_buildpath('/bankconnect/class/ImportService.php', 0);
require_once dol_buildpath('/bankconnect/class/DolibarrBankEntryService.php', 0);
require_once dol_buildpath('/bankconnect/class/DolibarrCandidateProvider.php', 0);
require_once dol_buildpath('/bankconnect/class/ReconciliationService.php', 0);
require_once dol_buildpath('/bankconnect/class/ReconciliationWorkflowService.php', 0);
require_once dol_buildpath('/bankconnect/class/DolibarrPaymentLinkGateway.php', 0);
require_once dol_buildpath('/bankconnect/class/ApprovedMatchLinkService.php', 0);
require_once dol_buildpath('/bankconnect/class/BankJournalHandoffService.php', 0);
require_once dol_buildpath('/bankconnect/class/AgreementStore.php', 0);
require_once dol_buildpath('/bankconnect/class/BankAccountMappingStore.php', 0);
require_once dol_buildpath('/bankconnect/class/BankConnectAutomaticImportService.php', 0);
require_once dol_buildpath('/bankconnect/class/BankConnectClientFactory.php', 0);

if (!$user->hasRight('bankconnect', 'read')) {
	accessforbidden();
}

$langs->load('bankconnect@bankconnect');
$store = new BankConnectStore($db);
$accountid = GETPOST('account', 'int') ?: 0;
$action = GETPOST('action', 'alpha');
$requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$writeAction = in_array($action, ['import', 'sync', 'match', 'approve', 'reject', 'defer', 'link'], true);
if ($writeAction) {
	if ($requestMethod !== 'POST' || !checkToken()) {
		accessforbidden();
	}
	if (!$user->hasRight('bankconnect', 'write')) {
		accessforbidden();
	}
}
if ($accountid > 0) {
	$accountAccess = $db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'bank_account WHERE rowid='.(int)$accountid.' AND clos=0 AND entity IN ('.getEntity('bank_account').')');
	if (!$accountAccess || !$db->fetch_object($accountAccess)) {
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
 } elseif ($action === 'sync' && $user->hasRight('bankconnect', 'write')) {
	try {
		$syncService = new BankConnectAutomaticImportService(
			new AgreementStore($db),
			new BankAccountMappingStore($db),
			new ImportService($store, null, new DolibarrBankEntryService($db)),
			new BankConnectClientFactory()
		);
		$syncResult = $syncService->run((int)$conf->entity, $user);
		$store->audit($user->id, 'sync', 'auto import: '.$syncResult['imported'].' new, '.$syncResult['duplicates'].' duplicates across '.$syncResult['agreements'].' agreement(s)');
		if (!empty($syncResult['errors'])) {
			setEventMessages($langs->trans('BankConnectSyncPartial').': '.implode(' — ', $syncResult['errors']), null, 'errors');
		} else {
			setEventMessages($langs->trans('BankConnectSyncOk', $syncResult['imported'], $syncResult['duplicates']), null);
		}
	} catch (Throwable $e) {
		setEventMessages($langs->trans('BankConnectSyncFailed').': '.$e->getMessage(), null, 'errors');
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
	try {
		$workflow = new ReconciliationWorkflowService($store, new DolibarrCandidateProvider($db, (string)($conf->currency ?? 'DKK')));
		$workflow->approve(GETPOSTINT('matchid'), $accountid, (int)$conf->entity, (array)GETPOST('candidate', 'array'), (int)$user->id);
		$linker = new ApprovedMatchLinkService($store, new DolibarrPaymentLinkGateway($db));
		$linker->link(GETPOSTINT('matchid'), $accountid, (int)$conf->entity, $user);
		setEventMessages($langs->trans('BankConnectApprovedAndLinked'), null);
	} catch (Throwable $e) {
		setEventMessages($e->getMessage(), null, 'errors');
	}
} elseif ($action === 'link' && $user->hasRight('bankconnect', 'write')) {
	try {
		$linker = new ApprovedMatchLinkService($store, new DolibarrPaymentLinkGateway($db));
		$linker->link(GETPOSTINT('matchid'), $accountid, (int)$conf->entity, $user);
		setEventMessages($langs->trans('BankConnectLinked'), null);
	} catch (Throwable $e) {
		setEventMessages($e->getMessage(), null, 'errors');
	}
} elseif ($action === 'reject' && $user->hasRight('bankconnect', 'write')) {
	try {
		$workflow = new ReconciliationWorkflowService($store, new DolibarrCandidateProvider($db, (string)($conf->currency ?? 'DKK')));
		$workflow->reject(GETPOSTINT('matchid'), $accountid, (int)$user->id);
		setEventMessages($langs->trans('BankConnectRejected'), null);
	} catch (Throwable $e) {
		setEventMessages($e->getMessage(), null, 'errors');
	}
} elseif ($action === 'defer' && $user->hasRight('bankconnect', 'write')) {
	try {
		$workflow = new ReconciliationWorkflowService($store, new DolibarrCandidateProvider($db, (string)($conf->currency ?? 'DKK')));
		$workflow->defer(GETPOSTINT('transactionid'), $accountid, (int)$user->id);
		setEventMessages($langs->trans('BankConnectDeferred'), null);
	} catch (Throwable $e) {
		setEventMessages($e->getMessage(), null, 'errors');
	}
}

llxHeader('', 'BankConnect', '', '', 0, 0, '', ['/bankconnect/css/reconcile.css']);
print load_fiche_titre('BankConnect — '.$langs->trans('BankConnectReconcile'), '', 'bank');

$accounts = [];
$res = $db->query('SELECT rowid, label FROM '.MAIN_DB_PREFIX.'bank_account WHERE clos=0 AND entity IN ('.getEntity('bank_account').') ORDER BY label');
while ($res && ($account = $db->fetch_object($res))) {
	$accounts[] = $account;
}
if (!$accountid && count($accounts) === 1) {
	$accountid = (int)$accounts[0]->rowid;
}

print '<div class="bc-toolbar">';
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'"><label>'.$langs->trans('BankConnectAccount').' ';
print '<select name="account" onchange="this.form.submit()"><option value="">'.$langs->trans('BankConnectChooseAccount').'</option>';
foreach ($accounts as $account) {
	print '<option value="'.(int)$account->rowid.'"'.($accountid === (int)$account->rowid ? ' selected' : '').'>'.dol_escape_htmltag($account->label).'</option>';
}
print '</select></label></form>';
if ($user->hasRight('bankconnect', 'write')) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inlineblock">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="sync"><input type="hidden" name="account" value="'.$accountid.'">';
	print '<input type="submit" class="button" value="'.$langs->trans('BankConnectSyncNow').'">';
	print '</form>';
}
if ($accountid) {
	// Sync status card: last run, imported count / errors (task: show last sync status)
	$agreementStore = new AgreementStore($db);
	$mappingStore = new BankAccountMappingStore($db);
	$agreement = null;
	foreach ($agreementStore->listAgreements((int) $conf->entity) as $a) {
		$mapping = $mappingStore->findByAgreement((int) $conf->entity, (int) $a['rowid']);
		if ($mapping && (int)$mapping['fk_bank_account'] === $accountid) {
			$agreement = $a;
			break;
		}
	}
	if ($agreement) {
		print '<div class="bc-sync-status">';
		if (!empty($agreement['last_sync_error'])) {
			print '<span class="error">'.$langs->trans('BankConnectLastSyncFailed').': '.dol_escape_htmltag($agreement['last_sync_error']).'</span>';
		} elseif (!empty($agreement['last_sync_at'])) {
			print '<span class="ok">'.dol_escape_htmltag($langs->trans('BankConnectLastSync').': '.dol_print_date($agreement['last_sync_at'], 'dayhour').' — '.($agreement['last_sync_summary'] ?? '')).'</span>';
		} else {
			print '<span class="opacitymedium">'.$langs->trans('BankConnectNeverSynced').'</span>';
		}
		print '</div>';
	}

	print '<details class="bc-import"><summary>'.$langs->trans('BankConnectManualImport').'</summary>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" enctype="multipart/form-data">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="import"><input type="hidden" name="account" value="'.$accountid.'">';
	print '<input type="file" name="camtfile" accept=".xml"> <input type="submit" class="button" value="'.$langs->trans('BankConnectImport').'">';
	print '</form></details>';
}
print '</div>';

if (!$accountid) {
	print '<div class="info">'.$langs->trans('BankConnectChooseAccountHelp').'</div>';
	llxFooter();
	exit;
}

$unmatched = $store->unmatchedTransactions($accountid);
$proposals = $store->proposedMatchesForAccount($accountid);
$linkPending = $store->approvedMatchesAwaitingLink($accountid);
$linked = $store->linkedMatchesForAccount($accountid);
$journalHandoff = new BankJournalHandoffService($db);
$provider = new DolibarrCandidateProvider($db, (string)($conf->currency ?? 'DKK'));
$workflow = new ReconciliationWorkflowService($store, $provider);

print '<div class="bc-summary">';
print '<div><strong>'.count($unmatched).'</strong><span>'.$langs->trans('BankConnectUnmatched').'</span></div>';
print '<div><strong>'.count($proposals).'</strong><span>'.$langs->trans('BankConnectAwaitingApproval').'</span></div>';
print '<div><strong>'.count($linkPending).'</strong><span>'.$langs->trans('BankConnectAwaitingLink').'</span></div>';
print '<div><strong>'.count($linked).'</strong><span>'.$langs->trans('BankConnectLinkedEntries').'</span></div>';
print '</div>';

if ($unmatched) {
	print '<section class="bc-section"><div class="bc-section-head"><div><h2>'.$langs->trans('BankConnectUnmatched').'</h2><p>'.$langs->trans('BankConnectUnmatchedHelp').'</p></div>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="account" value="'.$accountid.'"><input type="hidden" name="action" value="match"><button class="button button-save">'.$langs->trans('BankConnectRunMatching').'</button></form></div>';
	print '<div class="bc-grid">';
	foreach ($unmatched as $tx) {
		print '<article class="bc-card"><div class="bc-card-top"><span>'.dol_print_date($tx['tx_date'], 'day').'</span><strong class="'.((float)$tx['amount'] < 0 ? 'bc-debit' : 'bc-credit').'">'.price($tx['amount']).' '.dol_escape_htmltag($tx['currency']).'</strong></div>';
		print '<h3>'.dol_escape_htmltag($tx['counterparty'] ?: $langs->trans('BankConnectUnknownCounterparty')).'</h3><p>'.dol_escape_htmltag($tx['reference'] ?: '—').'</p>';
		print '<div class="bc-card-meta">';
		if (!empty($tx['fk_bankentry'])) {
			print '<a href="'.DOL_URL_ROOT.'/compta/bank/line.php?rowid='.(int)$tx['fk_bankentry'].'">'.$langs->trans('BankConnectBankEntry').' #'.(int)$tx['fk_bankentry'].'</a>';
		} elseif (($tx['bank_entry_state'] ?? '') === 'error') {
			print '<span class="bc-pill bc-danger" title="'.dol_escape_htmltag($tx['bank_entry_error'] ?? '').'">'.$langs->trans('Error').'</span>';
		}
		if (!empty($tx['requires_manual_review'])) print '<span class="bc-pill bc-warn">'.$langs->trans('BankConnectManualReview').'</span>';
		if (!empty($tx['is_reversal'])) print '<span class="bc-pill bc-danger">Reversal</span>';
		print '</div><form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="account" value="'.$accountid.'"><input type="hidden" name="action" value="defer"><input type="hidden" name="transactionid" value="'.(int)$tx['rowid'].'"><button class="button">'.$langs->trans('BankConnectDefer').'</button></form></article>';
	}
	print '</div></section>';
}

print '<section class="bc-section"><div class="bc-section-head"><div><h2>'.$langs->trans('BankConnectAwaitingApproval').'</h2><p>'.$langs->trans('BankConnectApprovalHelp').'</p></div></div>';
if (!$proposals) {
	print '<div class="bc-empty">'.$langs->trans('BankConnectNothingPending').'</div>';
}
foreach ($proposals as $proposal) {
	$transaction = BankTransaction::fromArray($proposal);
	$available = $provider->forTransaction($transaction, (int)$conf->entity);
	$suggested = [];
	foreach ($proposal['candidates'] as $candidate) $suggested[$candidate['candidate_type'].':'.$candidate['candidate_id']] = true;
	usort($available, static function (Candidate $a, Candidate $b) use ($suggested, $workflow): int {
		return (int)isset($suggested[$workflow->key($b->type, $b->id)]) <=> (int)isset($suggested[$workflow->key($a->type, $a->id)]);
	});
	$score = (float)$proposal['score'];
	$band = $score >= .9 ? 'bc-strong' : ($score >= .7 ? 'bc-medium' : 'bc-weak');
	print '<article class="bc-proposal"><header><div><span>'.dol_print_date($proposal['tx_date'], 'day').'</span><h3>'.dol_escape_htmltag($proposal['counterparty'] ?: $langs->trans('BankConnectUnknownCounterparty')).'</h3><p>'.dol_escape_htmltag($proposal['reference'] ?: '—').'</p></div><div class="bc-amount"><strong>'.price($proposal['amount']).' '.dol_escape_htmltag($proposal['currency']).'</strong><span class="bc-confidence '.$band.'">'.round($score * 100).'%</span></div></header>';
	print '<div class="bc-reason"><strong>'.dol_escape_htmltag($proposal['rule_name']).'</strong> — '.dol_escape_htmltag($proposal['reason']).'</div>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="bc-candidate-form"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="account" value="'.$accountid.'"><input type="hidden" name="action" value="approve"><input type="hidden" name="matchid" value="'.(int)$proposal['match_rowid'].'">';
	print '<div class="bc-candidates">';
	foreach (array_slice($available, 0, 20) as $candidate) {
		$key = $workflow->key($candidate->type, $candidate->id);
		$isSuggested = isset($suggested[$key]);
		print '<label class="bc-candidate'.($isSuggested ? ' bc-suggested' : '').'"><input type="checkbox" name="candidate[]" value="'.dol_escape_htmltag($key).'"'.($isSuggested ? ' checked' : '').'><span><strong>'.dol_escape_htmltag($candidate->ref ?: '#'.$candidate->id).'</strong><small>'.dol_escape_htmltag($candidate->thirdparty).' · '.dol_print_date($candidate->date, 'day').'</small></span><b>'.price($candidate->remaining).' '.dol_escape_htmltag($candidate->currency).'</b>'.($isSuggested ? '<em>'.$langs->trans('BankConnectSuggested').'</em>' : '').'</label>';
	}
	print '</div><div class="bc-actions"><button class="button button-save">'.$langs->trans('BankConnectApproveSelection').'</button></div></form>';
	print '<div class="bc-actions bc-secondary"><form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="account" value="'.$accountid.'"><input type="hidden" name="action" value="reject"><input type="hidden" name="matchid" value="'.(int)$proposal['match_rowid'].'"><button class="button">'.$langs->trans('BankConnectReject').'</button></form></div></article>';
}
print '</section>';

if ($linkPending) {
	print '<section class="bc-section"><div class="bc-section-head"><div><h2>'.$langs->trans('BankConnectAwaitingLink').'</h2><p>'.$langs->trans('BankConnectAwaitingLinkHelp').'</p></div></div><div class="bc-grid">';
	foreach ($linkPending as $pending) {
		print '<article class="bc-card"><div class="bc-card-top"><span>'.dol_print_date($pending['tx_date'], 'day').'</span><strong>'.price($pending['amount']).' '.dol_escape_htmltag($pending['currency']).'</strong></div>';
		print '<h3>'.dol_escape_htmltag($pending['counterparty'] ?: $langs->trans('BankConnectUnknownCounterparty')).'</h3><p>'.dol_escape_htmltag($pending['reference'] ?: '—').'</p>';
		if (!empty($pending['link_error'])) print '<p class="bc-link-error">'.dol_escape_htmltag($pending['link_error']).'</p>';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="account" value="'.$accountid.'"><input type="hidden" name="action" value="link"><input type="hidden" name="matchid" value="'.(int)$pending['match_rowid'].'"><button class="button button-save">'.$langs->trans('BankConnectRetryLink').'</button></form></article>';
	}
	print '</div></section>';
}

if ($linked) {
	print '<section class="bc-section"><div class="bc-section-head"><div><h2>'.$langs->trans('BankConnectAccountingHandoff').'</h2><p>'.$langs->trans('BankConnectAccountingHandoffHelp').'</p></div></div><div class="bc-grid">';
	foreach ($linked as $entry) {
		$status = $journalHandoff->status((int)$entry['fk_bankentry'], $accountid, (int)$conf->entity);
		$stateLabels = [
			'ready' => 'BankConnectJournalReady', 'transferred' => 'BankConnectJournalTransferred',
			'missing_journal' => 'BankConnectJournalMissing', 'accounting_disabled' => 'BankConnectAccountingDisabled',
			'missing_payment_link' => 'BankConnectPaymentLinkMissing', 'broken_payment_link' => 'BankConnectPaymentLinkBroken',
			'missing_bank_entry' => 'BankConnectBankEntryMissing',
		];
		$isOk = in_array($status['state'], ['ready', 'transferred'], true);
		print '<article class="bc-card"><div class="bc-card-top"><span>'.dol_print_date($entry['tx_date'], 'day').'</span><strong>'.price($entry['amount']).' '.dol_escape_htmltag($entry['currency']).'</strong></div>';
		print '<h3>'.dol_escape_htmltag($entry['counterparty'] ?: $langs->trans('BankConnectUnknownCounterparty')).'</h3><p>'.dol_escape_htmltag($entry['reference'] ?: '—').'</p>';
		print '<div class="bc-card-meta"><a href="'.DOL_URL_ROOT.'/compta/bank/line.php?rowid='.(int)$entry['fk_bankentry'].'">'.$langs->trans('BankConnectBankEntry').' #'.(int)$entry['fk_bankentry'].'</a>';
		print '<span class="bc-pill '.($isOk ? 'bc-strong' : 'bc-danger').'">'.$langs->trans($stateLabels[$status['state']] ?? 'BankConnectPaymentLinkBroken').'</span></div>';
		if ($status['journal_id'] > 0) {
			$url = DOL_URL_ROOT.'/accountancy/journal/bankjournal.php?mainmenu=accountancy&leftmenu=accountancy_transfer_journal&id_journal='.(int)$status['journal_id'];
			print '<a class="button button-save" href="'.$url.'">'.$langs->trans($status['transferred'] ? 'BankConnectOpenJournal' : 'BankConnectTransferInJournal').'</a>';
		} elseif ($status['state'] === 'missing_journal') {
			print '<a class="button" href="'.DOL_URL_ROOT.'/compta/bank/card.php?id='.$accountid.'">'.$langs->trans('BankConnectConfigureJournal').'</a>';
		}
		print '</article>';
	}
	print '</div></section>';
}
llxFooter();
