<?php
/* Copyright (C) 2024-2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonhookactions.class.php';

/**
 * Hook actions for KreaBank.
 */
class ActionsKreaBank extends CommonHookActions
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var array */
	public $errors = array();

	/** @var array */
	public $results = array();

	/** @var string */
	public $resprints = '';

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/** Keep native account tabs unchanged; the workspace header owns navigation. */
	public function completeTabsHead($parameters, &$object, &$action, $hookmanager)
	{
		return 0;
	}

	/** Simplify workspace links without changing native routes or other menu permissions. */
	public function menuLeftMenuItems($parameters, &$menuitems, &$action, $hookmanager)
	{
		global $user, $langs;
		$this->results = array();
		if (($parameters['mainmenu'] ?? '') !== 'bank' || !is_array($menuitems) || !empty($user->socid)
			|| !$user->hasRight('banque', 'lire') || !$user->hasRight('kreabank', 'reconciliation', 'read')) {
			return 0;
		}
		$visible = array();
		foreach ($menuitems as $item) {
			$path = parse_url($item['url'] ?? '', PHP_URL_PATH);
			if ($path === '/custom/kreabank/reconciliationlist.php' || ((int) ($item['level'] ?? 0) === 1
				&& in_array($path, array('/compta/bank/list.php', '/custom/kreabank/reconcile.php'), true))) {
				continue;
			}
			$visible[] = $item;
		}
		$bankIndex = null;
		foreach ($visible as $index => $item) {
			if ((int) ($item['level'] ?? 0) === 0 && parse_url($item['url'] ?? '', PHP_URL_PATH) === '/compta/bank/list.php') {
				$bankIndex = $index;
				break;
			}
		}
		if ($bankIndex !== null) {
			$movementIndex = null;
			$newAccountIndex = null;
			for ($index = $bankIndex + 1; isset($visible[$index]) && (int) ($visible[$index]['level'] ?? 0) > 0; $index++) {
				$item = $visible[$index];
				if ((int) ($item['level'] ?? 0) !== 1) { continue; }
				$path = parse_url($item['url'] ?? '', PHP_URL_PATH);
				$query = array();
				parse_str(html_entity_decode((string) (parse_url($item['url'] ?? '', PHP_URL_QUERY) ?? ''), ENT_QUOTES, 'UTF-8'), $query);
				if ($path === '/compta/bank/card.php' && ($query['action'] ?? '') === 'create') { $newAccountIndex = $index; }
				if ($movementIndex === null && $path === '/compta/bank/bankentries_list.php' && empty($query['action'])) { $movementIndex = $index; }
			}
			if ($movementIndex !== null) {
				$langs->load('kreabank@kreabank');
				$visible[$movementIndex]['titre'] = $langs->trans('KreaBankNavMovements');
				if ($newAccountIndex !== null) {
					// Move the native entry with its children, without changing its URL or rights.
					$end = $movementIndex + 1;
					while (isset($visible[$end]) && (int) ($visible[$end]['level'] ?? 0) > 1) { $end++; }
					$movementBlock = array_splice($visible, $movementIndex, $end - $movementIndex);
					if ($newAccountIndex > $movementIndex) { $newAccountIndex -= count($movementBlock); }
					$movementBlock[0]['position'] = (int) ($visible[$newAccountIndex]['position'] ?? 0);
					$insert = $newAccountIndex + 1;
					while (isset($visible[$insert]) && (int) ($visible[$insert]['level'] ?? 0) > 1) { $insert++; }
					array_splice($visible, $insert, 0, $movementBlock);
				}
			}
			$reports = array();
			$ordinary = array();
			foreach ($visible as $item) {
				if (parse_url($item['url'] ?? '', PHP_URL_PATH) === '/custom/kreatax/bankreconciliation.php') {
					$item['level'] = 1;
					$reports[] = $item;
				} else {
					$ordinary[] = $item;
				}
			}
			if ($reports) {
				// Keep physical order aligned with native sibling sorting: its block closing
				// checks the next integer key even after sorting with preserved keys.
				foreach ($ordinary as $index => $item) {
					if ((int) ($item['level'] ?? 0) === 0 && parse_url($item['url'] ?? '', PHP_URL_PATH) === '/compta/bank/list.php') {
						$insert = $index + 1;
						while (isset($ordinary[$insert]) && (int) ($ordinary[$insert]['level'] ?? 0) > 0) { $insert++; }
						$position = null;
						for ($sibling = $index + 1; isset($ordinary[$sibling]) && (int) ($ordinary[$sibling]['level'] ?? 0) > 0; $sibling++) {
							$query = array();
							parse_str(html_entity_decode((string) (parse_url($ordinary[$sibling]['url'] ?? '', PHP_URL_QUERY) ?? ''), ENT_QUOTES, 'UTF-8'), $query);
							if ((int) ($ordinary[$sibling]['level'] ?? 0) === 1
								&& parse_url($ordinary[$sibling]['url'] ?? '', PHP_URL_PATH) === '/compta/bank/bankentries_list.php' && empty($query['action'])) {
								$position = (int) ($ordinary[$sibling]['position'] ?? 0);
								$insert = $sibling + 1;
								while (isset($ordinary[$insert]) && (int) ($ordinary[$insert]['level'] ?? 0) > 1) { $insert++; }
								break;
							}
						}
						if ($position !== null) {
							foreach ($reports as &$report) { $report['position'] = $position; }
							unset($report);
						}
						array_splice($ordinary, $insert, 0, $reports);
						break;
					}
				}
				$visible = $ordinary;
			}
		}
		if ($visible === array_values($menuitems)) {
			return 0;
		}
		// Native rendering uses adjacent integer keys to determine menu block boundaries.
		$menuitems = $visible;
		$this->results = $visible;
		return 1;
	}

	/** Scoped native lists identify their fixed account in the shared header. */
	public function completeArrayFields($parameters, &$object, &$action, $hookmanager)
	{
		if ($this->bankingContextAllowed() && basename($_SERVER['PHP_SELF'] ?? '') === 'bankentries_list.php'
			&& $this->nativeAccountId('bankentries_list.php') > 0) {
			// Remove the field itself: saved native column preferences can override checked=0.
			unset($parameters['arrayfields']['ba.ref']);
		}
		return 0;
	}

	/** Apply the explicit matched-movements view before native pagination/counting. */
	public function printFieldListWhere($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $conf, $param;
		$this->resprints = '';
		if (!$this->bankingContextAllowed()) {
			return 0;
		}
		require_once __DIR__ . '/../lib/kreabank.lib.php';
		$page = basename($_SERVER['PHP_SELF'] ?? '');
		if ($page === 'list.php') {
			$this->resprints = ' AND b.entity=' . (int) $conf->entity;
		} elseif ($page === 'bankentries_list.php') {
			$this->resprints = ' AND ba.entity=' . (int) $conf->entity;
			if (GETPOSTISSET('account_context')) {
				$accountId = max(0, GETPOSTINT('account_context'));
				if ($accountId > 0) {
					$this->resprints .= ' AND ba.rowid=' . $accountId;
				}
				if (strpos((string) $param, 'account_context=') === false) {
					$param .= '&account_context=' . $accountId;
				}
			}
			$filter = kreabankMovementFilter();
			if ($filter !== 0) {
				require_once __DIR__ . '/kreabankintegration.class.php';
				$report = new KreaBankIntegration($this->db,$conf->entity);
				$this->resprints .= $filter === 1 ? $report->matchedWhereSql() : ' AND b.rappro=0';
				if (strpos((string) $param,'kreabank_matched=') === false) {
					$param .= '&kreabank_matched=' . $filter;
				}
			}
		}
		return 0;
	}

	/** Preserve the optional native-list filter when submitting the search form. */
	public function printFieldPreListTitle($parameters, &$object, &$action, $hookmanager)
	{
		$this->resprints = '';
		if ($this->bankingContextAllowed() && basename($_SERVER['PHP_SELF'] ?? '') === 'bankentries_list.php') {
			require_once __DIR__ . '/../lib/kreabank.lib.php';
			$this->resprints = kreabankNavigationHiddenFields($this->nativeAccountId('bankentries_list.php'));
			if (kreabankMovementFilter() !== 0) {
				$this->resprints .= '<input type="hidden" name="kreabank_matched" value="' . kreabankMovementFilter() . '">';
			}
		}
		return 0;
	}

	/** Route the banking entry point to the company-owned account overview. */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $conf, $langs;
		// Native transfer hooks must enforce the relationship even for users without workspace read rights.
		if (strpos($_SERVER['PHP_SELF'] ?? '', '/compta/bank/') !== false
			&& basename($_SERVER['PHP_SELF']) === 'transfer.php' && $action === 'add') {
			require_once __DIR__ . '/KreaBankAccountPairs.class.php';
			$policy = new KreaBankAccountPairs($this->db, $conf->entity);
			try {
				for ($i = 1; $i < 20; $i++) {
					$from = GETPOSTINT($i . '_account_from'); $to = GETPOSTINT($i . '_account_to');
					if ($from > 0 && $to > 0) { $policy->assertTransfer($from, $to); }
				}
			} catch (Throwable $e) {
				$langs->load('kreabank@kreabank');
				$this->error = $langs->trans('KreaBankPairTransferDenied');
				$action = ''; // Native transfer.php does not gate its write block on the hook result.
				return -1;
			}
			// A leg that repeats an existing movement would double-count the ledger: review first.
			if (isModEnabled('kreabank')) {
				require_once __DIR__ . '/KreaBankTransferGuard.class.php';
				$transfers = array();
				for ($i = 1; $i < 20; $i++) {
					$from = GETPOSTINT($i . '_account_from'); $to = GETPOSTINT($i . '_account_to');
					$amount = (string) price2num(GETPOST($i . '_amount', 'alpha'), 'MT', 2);
					if ($from <= 0 || $to <= 0 || (float) $amount <= 0) { continue; }
					$transfers[] = array('from' => $from, 'to' => $to, 'amount' => $amount,
						'amount_to' => (string) price2num(GETPOST($i . '_amountto', 'alpha'), 'MT', 2),
						'date' => (int) dol_mktime(12, 0, 0, GETPOSTINT($i . '_month'), GETPOSTINT($i . '_day'), GETPOSTINT($i . '_year'), 'gmt'));
					if ((float) $transfers[count($transfers) - 1]['amount_to'] <= 0) { $transfers[count($transfers) - 1]['amount_to'] = ''; }
				}
				try {
					$guard = new KreaBankTransferGuard($this->db, $conf->entity);
					$existing = $transfers ? $guard->existingMovements($transfers, getDolGlobalInt('KREABANK_AUTOMATCH_DATE_TOLERANCE', 3)) : array();
				} catch (Throwable $e) {
					$existing = array();
					dol_syslog('KreaBank transfer duplicate review unavailable: ' . $e->getMessage(), LOG_WARNING);
				}
				if ($existing) {
					$store = (isset($_SESSION['kreabank_transfer_confirm']) && is_array($_SESSION['kreabank_transfer_confirm'])) ? $_SESSION['kreabank_transfer_confirm'] : array();
					$confirmed = count($existing) <= 100
						&& KreaBankTransferGuard::consumeConfirmation($store, $guard->confirmationKey($user->id, $transfers, $existing), time());
					$_SESSION['kreabank_transfer_confirm'] = $store;
					if (!$confirmed) {
						$langs->load('kreabank@kreabank');
						$this->error = $langs->trans(count($existing) > 100 ? 'KreaBankTransferExistingIncomplete' : 'KreaBankTransferExistingBlocked', '#' . implode(', #', array_slice($existing, 0, 20)));
						$action = '';
						return -1;
					}
					try { $audited = $guard->auditOverride($user->id, $transfers, $existing); } catch (Throwable $e) { $audited = false; }
					if (!$audited) {
						dol_syslog('KreaBank could not audit a confirmed transfer with existing movements ' . implode(',', $existing), LOG_WARNING);
					}
				}
			}
		}
		if (!$this->bankingContextAllowed()) {
			return 0;
		}
		// Request-local display cap only; never change unit/total calculation precision.
		$conf->global->MAIN_MAX_DECIMALS_SHOWN = '2';
		require_once __DIR__ . '/../lib/kreabank.lib.php';
		$page = basename($_SERVER['PHP_SELF'] ?? '');
		if ($page === 'list.php' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $action === '') {
			header('Location: ' . kreabankWorkspaceUrl('accounts'));
			exit;
		}
		if ($page === 'bankentries_list.php' && GETPOSTISSET('account_context')) {
			foreach (array('account', 'id', 'search_account') as $field) {
				if (GETPOSTINT($field) > 0 && GETPOSTINT($field) !== GETPOSTINT('account_context')) {
					kreabankNavigationAccount(GETPOSTINT($field));
				}
			}
		}
		$accountId = $this->nativeAccountId($page);
		if ($accountId > 0) {
			kreabankNavigationAccount($accountId);
		}
		return 0;
	}

	private function bankingContextAllowed()
	{
		global $user;
		return strpos($_SERVER['PHP_SELF'] ?? '', '/compta/bank/') !== false
			&& in_array(basename($_SERVER['PHP_SELF'] ?? ''), array('list.php', 'card.php', 'line.php', 'bankentries_list.php', 'releve.php', 'document.php', 'annuel.php', 'treso.php', 'transfer.php'), true)
			&& $user->hasRight('banque','lire') && $user->hasRight('kreabank','reconciliation','read');
	}

	private function nativeAccountId($page)
	{
		global $db, $conf;
		if ($page === 'line.php') {
			$id = GETPOSTINT('rowid');
			$r = $db->query('SELECT b.fk_account FROM ' . $db->prefix() . 'bank b JOIN ' . $db->prefix() . 'bank_account ba ON ba.rowid=b.fk_account WHERE b.rowid=' . $id . ' AND ba.entity=' . (int) $conf->entity);
			$row = $r ? $db->fetch_object($r) : null;
			if ($id > 0 && !$row) {
				accessforbidden();
			}
			return $row ? (int) $row->fk_account : 0;
		}
		if ($page === 'bankentries_list.php' && GETPOSTISSET('account_context')) {
			return max(0, GETPOSTINT('account_context'));
		}
		$id = GETPOSTINT('account') ?: (GETPOSTINT('id') ?: GETPOSTINT('search_account'));
		$ref = GETPOST('ref','alpha');
		if (!$id && $ref !== '') {
			$r = $db->query('SELECT rowid FROM ' . $db->prefix() . 'bank_account WHERE entity=' . (int) $conf->entity . " AND ref='" . $db->escape($ref) . "'");
			$row = $r ? $db->fetch_object($r) : null;
			if (!$row) {
				accessforbidden();
			}
			$id = (int) $row->rowid;
		}
		return $id;
	}

	/** Replace the duplicate native navigation with the same single banking header. */
	public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $conf, $langs;
		$this->resprints = '';
		// Standalone forms retain native navigation and execution.
		$page = basename($_SERVER['PHP_SELF'] ?? '');
		if ($page === 'transfer.php') {
			if ($this->bankingContextAllowed()) {
				require_once __DIR__ . '/../lib/kreabank_transfer.lib.php';
				$descriptions = array();
				$descriptionError = '';
				try {
					$descriptions = kreabankTransferDescriptions($this->db, $user, $conf->entity);
				} catch (Throwable $e) {
					$langs->load('kreabank@kreabank');
					$descriptionError = $langs->transnoentities('KreaBankTransferDescriptionsUnavailable');
					dol_syslog('KreaBank transfer description history lookup failed', LOG_WARNING);
				}
				$this->resprints = kreabankTransferAssets($descriptions, $descriptionError);
			}
			return 0;
		}
		if ($page === 'card.php' && ($action === 'create' || GETPOST('action', 'aZ09') === 'create')) {
			return 0;
		}
		if (!$this->bankingContextAllowed()) {
			return 0;
		}
		require_once __DIR__ . '/../lib/kreabank.lib.php';
		$page = basename($_SERVER['PHP_SELF'] ?? '');
		$accountId = $this->nativeAccountId($page);
		$account = kreabankNavigationAccount($accountId);
		$views = array('card.php' => 'card', 'document.php' => 'documents', 'annuel.php' => 'reports', 'treso.php' => 'forecast', 'line.php' => 'movements');
		$view = $views[$page] ?? 'accounts';
		if ($page === 'bankentries_list.php') {
			$view = GETPOST('action', 'aZ09') === 'reconcile' ? 'numbering' : 'movements';
		} elseif ($page === 'releve.php') {
			$view = GETPOSTINT('native_statements') || GETPOST('num', 'alphanohtml') !== '' ? 'native_statements' : 'statement';
		}
		$redirect = '';
		if ($page === 'releve.php' && GETPOST('num','alphanohtml') === '' && !GETPOSTINT('native_statements')) {
			$redirect = 'window.location.replace(' . json_encode(kreabankStatementUrl($accountId), JSON_HEX_TAG|JSON_HEX_AMP) . ');';
		}
		// The native movement list owns its filters; other bank views retain theirs.
		$html = kreabankBankingHeader($view, $account, $page !== 'bankentries_list.php' || $view !== 'movements');
		$ordinaryMovements = $page === 'bankentries_list.php' && $accountId > 0 && $action === '' && GETPOST('action', 'aZ09') === '';
		$this->resprints = '<div id="kreabank-native-context">' . $html . '</div><script nonce="' . dol_escape_htmltag(getNonce()) . '">'
			. '(function(){document.body.classList.add("kb-native-bank"' . ($ordinaryMovements ? ',"kb-native-movements"' : '') . ');var panel=document.getElementById("kreabank-native-context"),target=document.getElementById("id-right");if(panel&&target){target.insertBefore(panel,target.firstChild);}' . $redirect . '})();</script>';
		return 0;
	}

	/**
	 * Render module separator on native product card.
	 *
	 * @param array $parameters Hook parameters
	 * @param CommonObject $object Current object
	 * @param string $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		if (!$this->isNativeProductCardContext($parameters, $object)) {
			return 0;
		}

		$this->resprints = $this->buildProductCardSeparatorScriptRow('KreaBank', array('kreabank_'), 2, 'kreabank');
		return 0;
	}

	/**
	 * Check if current hook call is native product card context.
	 *
	 * @param array $parameters Hook parameters
	 * @param mixed $object Current object
	 * @return bool
	 */
	private function isNativeProductCardContext($parameters, $object)
	{
		if (!is_object($object)) {
			return false;
		}

		$element = '';
		if (property_exists($object, 'element')) {
			$element = $object->element;
		} elseif (method_exists($object, 'getElement')) {
			$element = $object->getElement();
		}
		if ($element !== 'product') {
			return false;
		}

		$context = '';
		if (!empty($parameters['context'])) {
			$context = (string) $parameters['context'];
		} elseif (!empty($parameters['currentcontext'])) {
			$context = (string) $parameters['currentcontext'];
		}
		if (strpos($context, 'productcard') === false) {
			return false;
		}

		$scriptPath = isset($_SERVER['PHP_SELF']) ? (string) $_SERVER['PHP_SELF'] : '';
		if ($scriptPath === '' && isset($_SERVER['SCRIPT_NAME'])) {
			$scriptPath = (string) $_SERVER['SCRIPT_NAME'];
		}

		return (strpos($scriptPath, '/product/card.php') !== false);
	}

	/**
	 * Build a hidden hook row that injects a non-gray section separator on product card.
	 *
	 * @param string $title Section title
	 * @param array $prefixes Exrafield key prefixes
	 * @param int $colspan Table colspan
	 * @param string $marker Marker key
	 * @return string
	 */
	private function buildProductCardSeparatorScriptRow($title, $prefixes, $colspan, $marker)
	{
		$nonce = function_exists('getNonce') ? getNonce() : '';
		$nonceAttr = $nonce !== '' ? ' nonce="' . dol_escape_htmltag($nonce) . '"' : '';
		$titleJson = json_encode((string) $title);
		$prefixesJson = json_encode(array_values($prefixes));
		$markerJson = json_encode((string) $marker);
		$colspanInt = (int) $colspan;

		$script = '(function(){'
			. 'var title=' . $titleJson . ';'
			. 'var prefixes=' . $prefixesJson . ';'
			. 'var marker=' . $markerJson . ';'
			. 'var colspan=' . $colspanInt . ';'
			. 'function insertSeparator(){'
			. 'if(!Array.isArray(prefixes)||!prefixes.length){return;}'
			. 'var firstRow=null;'
			. 'for(var i=0;i<prefixes.length&&!firstRow;i++){'
			. 'var selector=\'[name^="options_\'+prefixes[i]+\'"],[id^="options_\'+prefixes[i]+\'"]\';'
			. 'var nodes=document.querySelectorAll(selector);'
			. 'for(var n=0;n<nodes.length;n++){'
			. 'var row=nodes[n].closest?nodes[n].closest("tr"):null;'
			. 'if(row){firstRow=row;break;}'
			. '}'
			. '}'
			. 'if(!firstRow||!firstRow.parentNode){return;}'
			. 'if(firstRow.parentNode.querySelector(\'tr.krea-module-separator-\'+marker)){return;}'
			. 'var tr=document.createElement("tr");'
			. 'tr.className=\'liste_titre krea-module-separator-\'+marker;'
			. 'tr.style.setProperty("background","transparent","important");'
			. 'var td=document.createElement("td");'
			. 'td.setAttribute("colspan",String(colspan));'
			. 'td.textContent=title;'
			. 'td.style.setProperty("background","transparent","important");'
			. 'tr.appendChild(td);'
			. 'firstRow.parentNode.insertBefore(tr,firstRow);'
			. '}'
			. 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",insertSeparator);}else{insertSeparator();}'
			. 'setTimeout(insertSeparator,250);'
			. '})();';

		return '<tr class="krea-module-separator-hook-' . dol_escape_htmltag((string) $marker) . '" style="display:none;"><td colspan="' . $colspanInt . '"><script' . $nonceAttr . '>' . $script . '</script></td></tr>';
	}
}
