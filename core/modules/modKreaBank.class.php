<?php
/* Copyright (C) 2004-2018 Laurent Destailleur <eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019 Nicolas ZABOURI <info@inovea-conseil.com>
 * Copyright (C) 2019-2024 Frédéric France <frederic.france@free.fr>
 * Copyright (C) 2024-2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module KreaBank
 */
class modKreaBank extends DolibarrModules
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		// Id for module (must be unique).
		// Use here a free id (See in Home -> System information -> Dolibarr for list of used modules id).
		// ID 156550 - 156599: Kreativität Works (http://kreativitat.com)
		// ID 156550 kreaproducts
		// ID 156551 degema
		// ID 156554 kreareports
		// ID 156555 kreatools
		// ID 156556 kreaml
		// ID 156558 dolizsynch
		// ID 156559 kreawoo
		// ID 156560 kreabank
		// ID 156561 kreataxmatch
		$this->numero = 156560;

		$this->rights_class = 'kreabank';
		$this->family = 'Kreativität Works';
		$this->module_position = '40';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'ModuleKreaBankDesc';
		$this->descriptionlong = 'ModuleKreaBankDescLong';
		$this->editor_name = 'Kreativität Works';
		$this->editor_url = 'http://kreativitat.com';
		$this->version = '1.12.53';
		$this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
		$this->picto = 'kreaproducts@kreaproducts';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array('productcard'),
			'moduleforexternal' => 0,
			'websitetemplates' => 0,
			'captcha' => 0,
		);

		$this->dirs = array('/kreabank/temp', '/kreabank/import');
		$this->config_page_url = array('setup.php@kreabank');
		$this->hidden = getDolGlobalInt('MODULE_KREABANK_DISABLED');
		$this->depends = array('modBanque');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('kreabank@kreabank');
		$this->phpmin = array(7, 1);
		$this->need_dolibarr_version = array(19, -3);
		$this->need_javascript_ajax = 1;
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		$this->const = array(
			array('KREABANK_AUTOMATCH_DATE_TOLERANCE', 'integer', '3', 'Date tolerance in days for exact matching', 1, 'current', 1),
			array('KREABANK_BULKMATCH_SCAN_CACHE_TTL', 'integer', '120', 'Bulk match scan cache TTL in seconds', 1, 'current', 1),
			array('KREABANK_BULKMATCH_SCAN_CHUNK_SIZE', 'integer', '200', 'Bulk match scan chunk size', 1, 'current', 1),
			array('KREABANK_BULKMATCH_SCAN_REQUEST_BUDGET_MS', 'integer', '1200', 'Bulk match scan max budget per request (ms)', 1, 'current', 1),
			array('KREABANK_BULKMATCH_MIN_SCORE', 'integer', '100', 'Minimum score required for bulk auto-selection/confirmation', 1, 'current', 1),
			array('KREABANK_BULKMATCH_AUTO_RELOAD_MAX', 'integer', '30', 'Maximum automatic scan refresh attempts before manual refresh is required', 1, 'current', 1),
			array('KREABANK_BATCH_MIN_CANDIDATES', 'integer', '4', 'Minimum payment candidates to auto-detect batch mode in reconcile', 1, 'current', 1),
			array('KREABANK_BATCH_MIN_COVERAGE_PCT', 'integer', '90', 'Minimum percentage coverage to auto-detect batch mode in reconcile', 1, 'current', 1),
			array('KREABANK_BATCH_HINT_KEYWORDS', 'chaine', 'LOTE', 'Comma-separated keywords used to hint batch statement lines', 1, 'current', 1),
			array('KREABANK_BATCH_ML_ENABLED', 'yesno', '1', 'Enable ML-assisted batch prediction during reconciliation', 1, 'current', 1),
			array('KREABANK_BATCH_ML_CLASSIFIER', 'chaine', 'knn', 'Batch ML classifier algorithm (knn, naive_bayes, decision_tree, random_forest)', 1, 'current', 1),
			array('KREABANK_BATCH_ML_AUTO_THRESHOLD', 'integer', '80', 'ML confidence threshold (%) to auto-enable batch mode', 1, 'current', 1),
			array('KREABANK_BATCH_ML_MIN_SAMPLES', 'integer', '24', 'Minimum labeled samples required to train batch ML model', 1, 'current', 1),
			array('KREABANK_SUPPLIER_ML_ENABLED', 'yesno', '1', 'Enable ML-assisted supplier prediction for quick supplier invoice flow', 1, 'current', 1),
			array('KREABANK_SUPPLIER_ML_MIN_CONFIDENCE', 'integer', '70', 'Minimum ML confidence threshold (%) to auto-accept predicted supplier', 1, 'current', 1),
			array('KREABANK_SUPPLIER_ML_MIN_SAMPLES', 'integer', '18', 'Minimum labeled supplier samples required for ML prediction', 1, 'current', 1),
			array('KREABANK_AUDIT_RETENTION_DAYS', 'integer', '365', 'Retention window for reconciliation audit rows (days)', 1, 'current', 1),
			array('KREABANK_AUTOMATCH_SAFE_SCORE', 'integer', '150', 'Minimum score for secure batch reconciliation', 1, 'current', 1),
			array('KREABANK_AUTOMATCH_PARTIAL_SCORE', 'integer', '90', 'Minimum score for suggested reconciliation', 1, 'current', 1),
			array('KREABANK_DISCARD_ZERO_INVOICES', 'yesno', '1', 'Discard zero-amount invoices in open documents list', 1, 'current', 1),
			array('KREABANK_FEED_ENABLED', 'yesno', '0', 'Enable bank feed synchronization via API', 1, 'current', 1),
			array('KREABANK_FEED_PROVIDER', 'chaine', 'nordigen', 'Bank feed provider (nordigen/gocardless)', 1, 'current', 1),
			array('KREABANK_FEED_SECRET_ID', 'chaine', '', 'Provider secret id', 0, 'current', 1),
			array('KREABANK_FEED_SECRET_KEY', 'chaine', '', 'Provider secret key', 0, 'current', 1),
			array('KREABANK_DEFAULT_IMPORT_DELIMITER', 'chaine', ';', 'Default CSV delimiter for imports', 1, 'current', 1),
			array('KREABANK_HIDE_RECONCILED_LINES', 'yesno', '1', 'Hide reconciled lines in dual-pane view', 1, 'current', 1),
		);

		if (!isModEnabled('kreabank')) {
			$conf->kreabank = new stdClass();
			$conf->kreabank->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();

		$this->cronjobs = array();

		$this->rights = array();
		$r = 0;

		$this->rights[$r][0] = $this->numero + 1;
		$this->rights[$r][1] = 'Read bank reconciliation workspace';
		$this->rights[$r][4] = 'reconciliation';
		$this->rights[$r][5] = 'read';
		$r++;

		$this->rights[$r][0] = $this->numero + 2;
		$this->rights[$r][1] = 'Reconcile bank statement lines';
		$this->rights[$r][4] = 'reconciliation';
		$this->rights[$r][5] = 'write';
		$r++;

		$this->rights[$r][0] = $this->numero + 3;
		$this->rights[$r][1] = 'Undo bank reconciliations';
		$this->rights[$r][4] = 'reconciliation';
		$this->rights[$r][5] = 'undo';
		$r++;

		$this->menu = array();
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=bank',
			'type' => 'left',
			'titre' => 'KreaBankReconcile',
			'prefix' => img_picto('', '', 'class="fas fa-university infobox-bank_account paddingright pictofixedwidth"'),
			'mainmenu' => 'bank',
			'leftmenu' => 'kreabank_reconcile',
			'url' => '/custom/kreabank/reconcile.php?mainmenu=bank&leftmenu=kreabank_reconcile',
			'langs' => 'kreabank@kreabank',
			'position' => 1950,
			'enabled' => 'isModEnabled("kreabank") && isModEnabled("banque")',
			'perms' => '$user->hasRight("kreabank", "reconciliation", "read") && $user->hasRight("banque", "lire")',
			'target' => '',
			'user' => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=bank,fk_leftmenu=kreabank_reconcile',
			'type' => 'left',
			'titre' => 'KreaBankBulkMatch',
			'mainmenu' => 'bank',
			'leftmenu' => 'kreabank_bulkmatch',
			'url' => '/custom/kreabank/bulkmatch.php?mainmenu=bank&leftmenu=kreabank_bulkmatch',
			'langs' => 'kreabank@kreabank',
			'position' => 1952,
			'enabled' => 'isModEnabled("kreabank") && isModEnabled("banque")',
			'perms' => '$user->hasRight("kreabank", "reconciliation", "read") && $user->hasRight("banque", "lire")',
			'target' => '',
			'user' => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=bank,fk_leftmenu=kreabank_reconcile',
			'type' => 'left',
			'titre' => 'KreaBankImport',
			'mainmenu' => 'bank',
			'leftmenu' => 'kreabank_import',
			'url' => '/custom/kreabank/import.php?mainmenu=bank&leftmenu=kreabank_import',
			'langs' => 'kreabank@kreabank',
			'position' => 1951,
			'enabled' => 'isModEnabled("kreabank") && isModEnabled("banque")',
			'perms' => '$user->hasRight("kreabank", "reconciliation", "write") && $user->hasRight("banque", "modifier")',
			'target' => '',
			'user' => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=bank,fk_leftmenu=kreabank_reconcile',
			'type' => 'left',
			'titre' => 'KreaBankPending',
			'mainmenu' => 'bank',
			'leftmenu' => 'kreabank_pending',
			'url' => '/custom/kreabank/pending.php?mainmenu=bank&leftmenu=kreabank_pending',
			'langs' => 'kreabank@kreabank',
			'position' => 1953,
			'enabled' => 'isModEnabled("kreabank") && isModEnabled("banque")',
			'perms' => '$user->hasRight("kreabank", "reconciliation", "read") && $user->hasRight("banque", "lire")',
			'target' => '',
			'user' => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=bank,fk_leftmenu=kreabank_reconcile',
			'type' => 'left',
			'titre' => 'KreaBankHistory',
			'mainmenu' => 'bank',
			'leftmenu' => 'kreabank_history',
			'url' => '/custom/kreabank/history.php?mainmenu=bank&leftmenu=kreabank_history',
			'langs' => 'kreabank@kreabank',
			'position' => 1954,
			'enabled' => 'isModEnabled("kreabank") && isModEnabled("banque")',
			'perms' => '$user->hasRight("kreabank", "reconciliation", "read") && $user->hasRight("banque", "lire")',
			'target' => '',
			'user' => 2,
		);
	}

	/**
	 * Function called when module is enabled.
	 *
	 * @param string $options Options when enabling module
	 * @return int<-1,1>
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/kreabank/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);
		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param string $options Options when disabling module
	 * @return int<-1,1>
	 */
	public function remove($options = '')
	{
		$sql = array();

		return $this->_remove($sql, $options);
	}
}
