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
