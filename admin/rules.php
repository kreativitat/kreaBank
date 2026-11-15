<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
$res = @include __DIR__ . '/../../../main.inc.php';
if (!$res) { $res = @include __DIR__ . '/../../../../main.inc.php'; }
if (!$res) { die('Dolibarr bootstrap unavailable'); }
require_once __DIR__ . '/../lib/kreabank.lib.php';
require_once __DIR__ . '/../class/KreaBankRule.class.php';
$langs->loadLangs(array('admin','banks','companies','categories','kreabank@kreabank'));
if (!empty($user->socid) || !isModEnabled('kreabank') || !isModEnabled('banque')
	|| !$user->hasRight('kreabank','reconciliation','read') || !$user->hasRight('banque','lire')) { accessforbidden(); }
$rules = new KreaBankRule($db, $user, $conf->entity);
$canWrite = $user->hasRight('kreabank','rules','write') && $user->hasRight('banque','modifier');
$action = GETPOST('action','aZ09'); $editId = GETPOSTINT('id'); $preview = null; $failed = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!kreabankIsTokenValid() || ($action !== 'preview' && !$canWrite)) { accessforbidden(); }
	try {
		if ($action === 'save') {
			$conditions = array('all'=>array(), 'any'=>array());
			foreach (array('all','any') as $group) {
				$fields = GETPOST('condition_field_' . $group,'array'); $values = GETPOST('condition_value_' . $group,'array'); $maxima = GETPOST('condition_max_' . $group,'array');
				foreach ((array) $fields as $index=>$field) {
					if ($field === '') { continue; }
					$pair = explode('|', (string) $field, 2);
					$c = array('field'=>$pair[0], 'operator'=>$pair[1] ?? '', 'value'=>(string) ($values[$index] ?? ''));
					if ($c['operator'] === 'between') { $c['max'] = (string) ($maxima[$index] ?? ''); }
					$conditions[$group][] = $c;
				}
			}
			$data = array('label'=>GETPOST('label','alphanohtml'), 'active'=>GETPOSTINT('active'), 'priority'=>GETPOST('priority','alphanohtml'),
				'fk_bank_account'=>GETPOSTINT('fk_bank_account'), 'direction'=>GETPOST('direction','aZ09'), 'revision'=>GETPOST('revision','aZ09'), 'conditions'=>$conditions,
				'action'=>array('type'=>GETPOST('rule_action','aZ09'), 'thirdparty_id'=>GETPOSTINT('thirdparty_id'), 'invoice_type'=>GETPOST('invoice_type','aZ09'),
					'accounting_account'=>GETPOST('accounting_account','alphanohtml'), 'vat'=>GETPOST('vat','alphanohtml'), 'label'=>GETPOST('entry_label','alphanohtml'),
					'payment_mode'=>GETPOST('payment_mode','aZ09'), 'category_id'=>GETPOSTINT('category_id')));
			$failed = $data; $rules->save($data, $editId);
		} elseif ($action === 'toggle' || $action === 'priority') {
			$current = null; foreach ($rules->all() as $row) { if ((int) $row['rowid'] === $editId) { $current = $row; } }
			if (!$current) { throw new DomainException('Unknown bank rule'); }
			$current['revision'] = GETPOST('revision','aZ09');
			if ($action === 'toggle') { $current['active'] = (int) $current['active'] === 1 ? 0 : 1; }
			else { $current['priority'] = GETPOST('priority','alphanohtml'); }
			$rules->save($current, $editId);
		} elseif ($action === 'delete') { $rules->delete($editId, GETPOST('revision','aZ09')); }
		elseif ($action === 'preview') { $preview = $rules->preview($editId); }
		else { throw new DomainException('Invalid bank-rule action'); }
		if ($action !== 'preview') {
			setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
			header('Location: ' . dol_buildpath('/custom/kreabank/admin/rules.php',1)); exit;
		}
	} catch (Throwable $e) { setEventMessages($langs->trans('KreaBankRuleSaveError'), null, 'errors'); }
} elseif ($action !== '' && $action !== 'edit' && $action !== 'new') { accessforbidden(); }

llxHeader('', $langs->trans('KreaBankRules'));
print load_fiche_titre($langs->trans('KreaBankRules'), '', 'bank');
print dol_get_fiche_head(kreabankAdminPrepareHead(), 'rules', $langs->trans('KreaBankSetup'), -1, 'bank');
print '<p class="opacitymedium">' . $langs->trans('KreaBankRulesHelp') . '</p>';
$escape = static function ($value) { return dol_escape_htmltag((string) $value); };
$hidden = static function ($action, $id = 0, $revision = '') use ($escape) {
	return '<input type="hidden" name="token" value="' . newToken() . '"><input type="hidden" name="action" value="' . $escape($action)
		. '"><input type="hidden" name="id" value="' . (int) $id . '"><input type="hidden" name="revision" value="' . $escape($revision) . '">';
};
$select = static function ($name, $options, $value, $required = false) use ($escape) {
	$html = '<select class="flat maxwidth300" id="' . $escape($name) . '" name="' . $escape($name) . '"' . ($required ? ' required' : '') . '>';
	foreach ($options as $id=>$label) { $html .= '<option value="' . $escape($id) . '"' . ((string) $id === (string) $value ? ' selected' : '') . '>' . $escape($label) . '</option>'; }
	return $html . '</select>';
};
try {
	if (!$rules->available()) { print '<p class="error">' . $langs->trans('KreaBankRulesInstall') . '</p>'; }
	else {
		$all = $rules->all(); $options = $rules->options();
		$accounts = array('0'=>$langs->trans('KreaBankRuleAllAccounts'));
		foreach ($options['accounts'] as $row) { $accounts[$row->rowid] = $row->ref . ' — ' . $row->label; }
		print '<div class="div-table-responsive"><table class="liste centpercent"><thead><tr class="liste_titre">';
		foreach (array('KreaBankRulePriority','Label','Status','BankAccount','KreaBankRuleAction','KreaBankRuleHits','KreaBankActions') as $key) { print '<th scope="col">' . $langs->trans($key) . '</th>'; }
		print '</tr></thead><tbody>';
		foreach ($all as $row) {
			$id = (int) $row['rowid']; $revision = $row['revision'];
			print '<tr class="oddeven"><td>';
			if ($canWrite) { print '<form method="post">' . $hidden('priority',$id,$revision) . '<label class="sr-only" for="priority-' . $id . '">' . $langs->trans('KreaBankRulePriority') . '</label><input class="flat width75" type="number" min="0" max="999999" id="priority-' . $id . '" name="priority" value="' . (int) $row['priority'] . '"><button type="submit" class="button small">' . $langs->trans('Save') . '</button></form>'; }
			else { print (int) $row['priority']; }
			print '</td><td>' . $escape($row['label']) . '</td><td>' . $langs->trans((int) $row['active'] === 1 ? 'Enabled' : 'Disabled') . '</td><td>'
				. $escape($accounts[(int) $row['fk_bank_account']] ?? $langs->trans('KreaBankRuleUnavailable')) . '</td><td>' . $langs->trans('KreaBankRuleAction_' . $row['action']['type']) . '</td><td>' . (int) $row['hit_count'];
			if ($row['last_used']) { print '<br><span class="opacitymedium">' . $escape(substr($row['last_used'],0,10)) . '</span>'; }
			print '</td><td class="nowrap">';
			if ($canWrite) {
				print '<a class="button small" href="?action=edit&amp;id=' . $id . '">' . $langs->trans('Modify') . '</a><form class="inline-block" method="post">' . $hidden('toggle',$id,$revision)
					. '<button class="button small" type="submit">' . $langs->trans((int) $row['active'] === 1 ? 'Disable' : 'Enable') . '</button></form>';
				print '<details class="inline-block"><summary>' . $langs->trans('Delete') . '</summary><form method="post">' . $hidden('delete',$id,$revision) . '<p>' . $langs->trans('KreaBankRuleDeleteConfirm') . '</p><button class="button small" type="submit">' . $langs->trans('Confirm') . '</button></form></details>';
			}
			print '<form class="inline-block" method="post">' . $hidden('preview',$id,$revision) . '<button class="button small" type="submit">' . $langs->trans('KreaBankRulePreview') . '</button></form></td></tr>';
		}
		if (!$all) { print '<tr><td colspan="7">' . $langs->trans('KreaBankRulesEmpty') . '</td></tr>'; }
		print '</tbody></table></div>';
		if ($preview !== null) {
			print '<h3>' . $langs->trans('KreaBankRulePreview') . '</h3><p>' . $langs->trans('KreaBankRulePreviewCount',count($preview['matches']),$preview['scanned']) . '</p>';
			if ($preview['truncated']) { print '<p class="opacitymedium">' . $langs->trans('KreaBankRulePreviewBounded') . '</p>'; }
			print '<div class="div-table-responsive"><table class="liste centpercent"><tr class="liste_titre"><th scope="col">' . $langs->trans('Date') . '</th><th scope="col">' . $langs->trans('BankAccount') . '</th><th scope="col">' . $langs->trans('Description') . '</th><th class="right" scope="col">' . $langs->trans('Amount') . '</th></tr>';
			foreach ($preview['matches'] as $line) { print '<tr class="oddeven"><td>' . $escape($line['operation_date']) . '</td><td>' . $escape($accounts[(int) $line['bank_account_id']] ?? '') . '</td><td>' . $escape($line['description']) . '</td><td class="right nowrap">' . kreabankPrice($line['amount']) . ' ' . $escape($line['currency']) . '</td></tr>'; }
			print '</table></div>';
		}
		if ($canWrite) {
			$edit = $failed;
			if (!$edit && $action === 'edit') { foreach ($all as $row) { if ((int) $row['rowid'] === $editId) { $edit = $row; } } }
			if ($action !== 'edit' && $action !== 'new' && !$failed) { print '<div class="tabsAction"><a class="butAction" href="?action=new">' . $langs->trans('KreaBankRuleNew') . '</a></div>'; }
			else {
				$edit = $edit ?: array('label'=>'','priority'=>100,'active'=>0,'fk_bank_account'=>0,'direction'=>'both','conditions'=>array('all'=>array(),'any'=>array()),'action'=>array('type'=>'invoice_search'));
				print '<h3>' . $langs->trans($editId > 0 ? 'KreaBankRuleEdit' : 'KreaBankRuleNew') . '</h3><form method="post">' . $hidden('save',$editId,$edit['revision'] ?? '');
				print '<div class="div-table-responsive"><table class="border centpercent"><tr><td class="titlefield"><label for="label">' . $langs->trans('Label') . '</label></td><td><input class="flat minwidth200 maxwidth300 maxwidth75onsmartphone" id="label" name="label" maxlength="180" required value="' . $escape($edit['label']) . '"></td></tr>';
				print '<tr><td><label for="priority">' . $langs->trans('KreaBankRulePriority') . '</label></td><td><input class="flat width75" type="number" id="priority" name="priority" min="0" max="999999" required value="' . (int) $edit['priority'] . '"></td></tr>';
				print '<tr><td><label for="active">' . $langs->trans('Status') . '</label></td><td>' . $select('active',array('0'=>$langs->trans('Disabled'),'1'=>$langs->trans('Enabled')),$edit['active']) . '</td></tr>';
				print '<tr><td><label for="fk_bank_account">' . $langs->trans('BankAccount') . '</label></td><td>' . $select('fk_bank_account',$accounts,$edit['fk_bank_account']) . '</td></tr>';
				print '<tr><td><label for="direction">' . $langs->trans('KreaBankRuleDirection') . '</label></td><td>' . $select('direction',array('both'=>$langs->trans('KreaBankRuleBoth'),'in'=>$langs->trans('KreaBankRuleIn'),'out'=>$langs->trans('KreaBankRuleOut')),$edit['direction']) . '</td></tr></table></div>';
				$fields = array(''=>'—','description|contains'=>$langs->trans('KreaBankRuleDescriptionContains'),'description|not_contains'=>$langs->trans('KreaBankRuleDescriptionNotContains'),'description|regex'=>$langs->trans('KreaBankRuleDescriptionRegex'),'counterparty_name|contains'=>$langs->trans('KreaBankRuleCounterpartyContains'),'iban|equals'=>$langs->trans('KreaBankRuleIban'),'amount|equals'=>$langs->trans('KreaBankRuleAmountEquals'),'amount|between'=>$langs->trans('KreaBankRuleAmountBetween'),'currency|equals'=>$langs->trans('Currency'));
				foreach (array('all'=>'KreaBankRuleAll','any'=>'KreaBankRuleAny') as $group=>$key) {
					print '<h4>' . $langs->trans($key) . '</h4><p class="opacitymedium">' . $langs->trans('KreaBankRuleConditionHelp') . '</p><div class="div-table-responsive"><table class="border centpercent">';
					for ($i=0; $i<max(4,count($edit['conditions'][$group])); $i++) {
						$c = $edit['conditions'][$group][$i] ?? array('field'=>'','operator'=>'','value'=>'');
						print '<tr><td><label class="sr-only" for="condition-' . $group . '-' . $i . '">' . $langs->trans('KreaBankRuleCondition') . '</label><select class="flat" id="condition-' . $group . '-' . $i . '" name="condition_field_' . $group . '[]">';
						foreach ($fields as $value=>$text) { print '<option value="' . $escape($value) . '"' . ($c['field'] . '|' . $c['operator'] === $value ? ' selected' : '') . '>' . $escape($text) . '</option>'; }
						print '</select></td><td><label class="sr-only" for="value-' . $group . '-' . $i . '">' . $langs->trans('Value') . '</label><input class="flat" id="value-' . $group . '-' . $i . '" name="condition_value_' . $group . '[]" maxlength="256" value="' . $escape($c['value']) . '"></td><td><label for="max-' . $group . '-' . $i . '">' . $langs->trans('KreaBankRuleMaximum') . '</label> <input class="flat width100" id="max-' . $group . '-' . $i . '" name="condition_max_' . $group . '[]" value="' . $escape($c['max'] ?? '') . '"></td></tr>';
					}
					print '</table></div>';
				}
				$a = $edit['action']; $thirdparties = array('0'=>'—'); $categories = array('0'=>'—'); $accounting = array(''=>'—'); $paymentModes = array(''=>'—');
				foreach ($options['thirdparties'] as $row) { $thirdparties[$row->rowid] = $row->nom; }
				foreach ($options['categories'] as $row) { $categories[$row->rowid] = $row->label; }
				foreach ($options['accounting_accounts'] as $row) { $accounting[$row->account_number] = $row->account_number . ' — ' . $row->label; }
				foreach ($options['payment_modes'] as $row) { $paymentModes[$row->code] = $row->code . ' — ' . $row->libelle; }
				print '<h4>' . $langs->trans('KreaBankRuleAction') . '</h4><div class="div-table-responsive"><table class="border centpercent" id="kb-rule-action-fields">';
				$actionTypes = array(); foreach (array('invoice_search','quick_entry','category') as $type) { $actionTypes[$type] = $langs->trans('KreaBankRuleAction_' . $type); }
				$inputs = array('rule_action'=>array('KreaBankRuleAction',$select('rule_action',$actionTypes,$a['type'])), 'thirdparty_id'=>array('ThirdParty',$select('thirdparty_id',$thirdparties,$a['thirdparty_id'] ?? 0)),
					'invoice_type'=>array('KreaBankRuleInvoiceType',$select('invoice_type',array('supplier_invoice'=>$langs->trans('KreaBankRuleSupplierInvoice'),'customer_invoice'=>$langs->trans('KreaBankRuleCustomerInvoice')),$a['invoice_type'] ?? 'supplier_invoice')),
					'accounting_account'=>array('KreaBankRuleAccountingAccount',$select('accounting_account',$accounting,$a['accounting_account'] ?? '')), 'payment_mode'=>array('KreaBankRulePaymentMode',$select('payment_mode',$paymentModes,$a['payment_mode'] ?? '')),
					'category_id'=>array('Category',$select('category_id',$categories,$a['category_id'] ?? 0)),
					'vat'=>array('KreaBankRuleVat','<input class="flat width100" type="text" inputmode="decimal" id="vat" name="vat" value="' . $escape($a['vat'] ?? '') . '">'),
					'entry_label'=>array('Label','<input class="flat minwidth200 maxwidth300 maxwidth75onsmartphone" id="entry_label" name="entry_label" maxlength="255" value="' . $escape($a['label'] ?? '') . '">'));
				foreach ($inputs as $name=>$input) { print '<tr data-kb-rule-field="' . $name . '"><td class="titlefield"><label for="' . $name . '">' . $langs->trans($input[0]) . '</label></td><td>' . $input[1] . '</td></tr>'; }
				print '</table></div><p class="opacitymedium">' . $langs->trans('KreaBankRuleActionHelp') . '</p><div class="center"><button type="submit" class="button button-save">' . $langs->trans('Save') . '</button><a class="button button-cancel" href="' . dol_buildpath('/custom/kreabank/admin/rules.php',1) . '">' . $langs->trans('Cancel') . '</a></div></form>';
				print '<script nonce="' . $escape(getNonce()) . '">document.addEventListener("DOMContentLoaded",function(){const select=document.getElementById("rule_action");if(!select)return;const fields={invoice_search:["thirdparty_id","invoice_type"],quick_entry:["thirdparty_id","accounting_account","payment_mode","vat","entry_label"],category:["category_id"]};function update(){document.querySelectorAll("#kb-rule-action-fields [data-kb-rule-field]").forEach(function(row){const key=row.dataset.kbRuleField;const visible=key==="rule_action"||(fields[select.value]||[]).includes(key);row.hidden=!visible;row.querySelectorAll("input,select").forEach(function(input){input.disabled=!visible;});});}select.addEventListener("change",update);update();});</script>';
			}
		}
	}
} catch (Throwable $e) { print '<p class="error">' . $langs->trans('KreaBankRuleLoadError') . '</p>'; }
print dol_get_fiche_end(); llxFooter(); $db->close();
