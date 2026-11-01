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

	/** Apply the explicit matched-movements view before native pagination/counting. */
	public function printFieldListWhere($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $conf, $param;
		$this->resprints = '';
		if (!$this->bankingContextAllowed()) {
			return 0;
		}
		$page = basename($_SERVER['PHP_SELF'] ?? '');
		if ($page === 'list.php') {
			$this->resprints = ' AND b.entity=' . (int) $conf->entity;
		} elseif ($page === 'bankentries_list.php') {
			$this->resprints = ' AND ba.entity=' . (int) $conf->entity;
			if (GETPOSTINT('kreabank_matched')) {
				require_once __DIR__ . '/kreabankintegration.class.php';
				$report = new KreaBankIntegration($this->db,$conf->entity);
				$this->resprints .= $report->matchedWhereSql();
				if (strpos((string) $param,'kreabank_matched=') === false) {
					$param .= '&kreabank_matched=1';
				}
			}
		}
		return 0;
	}

	/** Preserve the optional native-list filter when submitting the search form. */
	public function printFieldPreListTitle($parameters, &$object, &$action, $hookmanager)
	{
		$this->resprints = '';
		if (basename($_SERVER['PHP_SELF'] ?? '') === 'bankentries_list.php' && GETPOSTINT('kreabank_matched')) {
			$this->resprints = '<input type="hidden" name="kreabank_matched" value="1">';
		}
		return 0;
	}

	/** Route the banking entry point to the company-owned account overview. */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $user;
		if (!$this->bankingContextAllowed()) {
			return 0;
		}
		require_once __DIR__ . '/../lib/kreabank.lib.php';
		$page = basename($_SERVER['PHP_SELF'] ?? '');
		if ($page === 'list.php' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $action === '') {
			header('Location: ' . kreabankWorkspaceUrl('accounts'));
			exit;
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
		$this->resprints = '';
		// Internal transfers keep the native form and navigation without a workspace header.
		if (basename($_SERVER['PHP_SELF'] ?? '') === 'transfer.php') {
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
		$html = kreabankBankingHeader($view,$account);
		$this->resprints = '<div id="kreabank-native-context">' . $html . '</div><script nonce="' . dol_escape_htmltag(getNonce()) . '">'
			. '(function(){document.body.classList.add("kb-native-bank");var panel=document.getElementById("kreabank-native-context"),target=document.getElementById("id-right");if(panel&&target){target.insertBefore(panel,target.firstChild);}' . $redirect . '})();</script>';
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
