--
-- KreaBank schema (assistant mode for native /compta/bank)
-- Source of truth for statements/reconciliation remains native Dolibarr tables:
--   - llx_bank
--   - llx_bank_url
--   - payment tables linked by update_fk_bank()
--
-- Statement lines are staged in KreaBank tables and materialized into llx_bank only on reconciliation.
--

CREATE TABLE IF NOT EXISTS llx_kreabank_statement (
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER NOT NULL DEFAULT 1,
	ref VARCHAR(64) NOT NULL,
	label VARCHAR(255) NULL,
	source_type VARCHAR(16) NOT NULL,
	filename VARCHAR(255) NULL,
	bank_account_id INTEGER NULL,
	statement_date DATE NULL,
	date_import DATETIME NOT NULL,
	fk_user_import INTEGER NOT NULL,
	status SMALLINT NOT NULL DEFAULT 0,
	currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
	external_id VARCHAR(128) NULL,
	last_balance DECIMAL(24,8) NULL,
	import_payload LONGTEXT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	datec DATETIME NOT NULL,
	UNIQUE KEY uk_kreabank_statement_entity_ref (entity, ref),
	KEY idx_kreabank_statement_entity_date (entity, statement_date),
	KEY idx_kreabank_statement_bank (entity, bank_account_id)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_kreabank_statement_line (
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER NOT NULL DEFAULT 1,
	fk_statement INTEGER NOT NULL,
	fk_native_bank_line INTEGER NULL,
	line_uid VARCHAR(128) NULL,
	line_rank INTEGER NOT NULL DEFAULT 0,
	operation_date DATE NULL,
	value_date DATE NULL,
	amount DECIMAL(24,8) NOT NULL,
	currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
	running_balance DECIMAL(24,8) NULL,
	counterparty_name VARCHAR(255) NULL,
	counterparty_iban VARCHAR(64) NULL,
	description TEXT NULL,
	payment_reference VARCHAR(190) NULL,
	bank_reference VARCHAR(190) NULL,
	direction SMALLINT NOT NULL DEFAULT 0,
	allocated_amount DECIMAL(24,8) NOT NULL DEFAULT 0,
	status SMALLINT NOT NULL DEFAULT 0,
	duplicate_hash CHAR(64) NULL,
	is_duplicate SMALLINT NOT NULL DEFAULT 0,
	duplicate_of INTEGER NULL,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	KEY idx_kreabank_line_statement (fk_statement),
	KEY idx_kreabank_line_native (fk_native_bank_line),
	KEY idx_kreabank_line_status (entity, status),
	KEY idx_kreabank_line_date_amount (operation_date, amount),
	KEY idx_kreabank_line_hash (entity, duplicate_hash)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_kreabank_import_profile (
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER NOT NULL DEFAULT 1,
	bank_account_id INTEGER NOT NULL DEFAULT 0,
	source_type VARCHAR(16) NOT NULL,
	fingerprint CHAR(64) NOT NULL,
	layout_signature CHAR(64) NULL,
	label VARCHAR(190) NOT NULL,
	header_row INTEGER NOT NULL DEFAULT -1,
	mapping_json LONGTEXT NOT NULL,
	template_json LONGTEXT NULL,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uk_kreabank_import_profile (entity, bank_account_id, source_type, fingerprint),
	KEY idx_kreabank_import_profile_layout (entity, bank_account_id, source_type, layout_signature)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_kreabank_bankmeta (
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER NOT NULL DEFAULT 1,
	fk_bank_line INTEGER NOT NULL,
	bank_account_id INTEGER NOT NULL DEFAULT 0,
	source_type VARCHAR(16) NULL,
	source_file VARCHAR(255) NULL,
	source_line_uid VARCHAR(128) NULL,
	idempotency_hash CHAR(64) NOT NULL,
	operation_date DATE NULL,
	value_date DATE NULL,
	amount DECIMAL(24,8) NOT NULL,
	currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
	running_balance DECIMAL(24,8) NULL,
	counterparty_name VARCHAR(255) NULL,
	counterparty_iban VARCHAR(64) NULL,
	description TEXT NULL,
	payment_reference VARCHAR(190) NULL,
	bank_reference VARCHAR(190) NULL,
	direction SMALLINT NOT NULL DEFAULT 0,
	status SMALLINT NOT NULL DEFAULT 0,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uk_kreabank_bankmeta_hash (entity, bank_account_id, idempotency_hash),
	UNIQUE KEY uk_kreabank_bankmeta_line (entity, fk_bank_line),
	KEY idx_kreabank_bankmeta_date (operation_date),
	KEY idx_kreabank_bankmeta_status (entity, status)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_kreabank_pattern (
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER NOT NULL DEFAULT 1,
	pattern_type VARCHAR(16) NOT NULL,
	pattern_value VARCHAR(255) NOT NULL,
	doc_type VARCHAR(32) NOT NULL,
	fk_doc INTEGER NOT NULL,
	doc_ref VARCHAR(128) NULL,
	fk_soc INTEGER NULL,
	hit_count INTEGER NOT NULL DEFAULT 0,
	last_score INTEGER NOT NULL DEFAULT 0,
	last_used DATETIME NULL,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uk_kreabank_pattern (entity, pattern_type, pattern_value, doc_type, fk_doc),
	KEY idx_kreabank_pattern_soc (fk_soc)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_kreabank_quick_entry (
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER NOT NULL DEFAULT 1,
	-- Keeps legacy column name for backward compatibility; stores native llx_bank.rowid.
	fk_statement_line INTEGER NOT NULL,
	entry_type VARCHAR(32) NOT NULL,
	label VARCHAR(255) NOT NULL,
	amount DECIMAL(24,8) NOT NULL,
	currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
	fk_soc INTEGER NULL,
	status SMALLINT NOT NULL DEFAULT 0,
	doc_type VARCHAR(32) NULL,
	fk_doc INTEGER NULL,
	note TEXT NULL,
	fk_user_author INTEGER NOT NULL,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	KEY idx_kreabank_quick_line (fk_statement_line),
	KEY idx_kreabank_quick_status (entity, status)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_kreabank_ml_sample (
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER NOT NULL DEFAULT 1,
	label SMALLINT NOT NULL DEFAULT 0,
	features_json LONGTEXT NOT NULL,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	KEY idx_kreabank_ml_sample_entity (entity, rowid),
	KEY idx_kreabank_ml_sample_label (entity, label)
) ENGINE=innodb;

SET @kb_has_recon_audit_legacy := (
	SELECT COUNT(*)
	FROM INFORMATION_SCHEMA.TABLES
	WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'llx_recon_audit'
);
SET @kb_has_recon_audit_new := (
	SELECT COUNT(*)
	FROM INFORMATION_SCHEMA.TABLES
	WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'llx_kreabank_recon_audit'
);
SET @kb_sql := IF(
	@kb_has_recon_audit_legacy = 1 AND @kb_has_recon_audit_new = 0,
	'RENAME TABLE llx_recon_audit TO llx_kreabank_recon_audit',
	'SELECT 1'
);
PREPARE kb_stmt FROM @kb_sql;
EXECUTE kb_stmt;
DEALLOCATE PREPARE kb_stmt;

CREATE TABLE IF NOT EXISTS llx_kreabank_recon_audit (
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER NOT NULL DEFAULT 1,
	audit_type VARCHAR(32) NOT NULL,
	-- Stores native llx_bank.rowid when line-level.
	fk_statement_line INTEGER NULL,
	-- Stores native llx_bank.rowid for reconciliation-level actions.
	fk_reconciliation INTEGER NULL,
	payload_json LONGTEXT NULL,
	fk_user INTEGER NULL,
	ip_address VARCHAR(64) NULL,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	KEY idx_kreabank_recon_audit_type (entity, audit_type),
	KEY idx_kreabank_recon_audit_date (datec),
	KEY idx_kreabank_recon_audit_line (fk_statement_line)
) ENGINE=innodb;

--
-- Bank extrafield migration: rename legacy Portuguese field "comprovativo"
-- to English "receipt" and ensure it exists.
--
SET @kb_has_bank_extrafields := (
	SELECT COUNT(*)
	FROM INFORMATION_SCHEMA.TABLES
	WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'llx_bank_extrafields'
);
SET @kb_has_comprovativo := (
	SELECT COUNT(*)
	FROM INFORMATION_SCHEMA.COLUMNS
	WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'llx_bank_extrafields' AND COLUMN_NAME = 'comprovativo'
);
SET @kb_has_receipt := (
	SELECT COUNT(*)
	FROM INFORMATION_SCHEMA.COLUMNS
	WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'llx_bank_extrafields' AND COLUMN_NAME = 'receipt'
);

SET @kb_sql := IF(
	@kb_has_bank_extrafields = 1 AND @kb_has_comprovativo = 1 AND @kb_has_receipt = 0,
	'ALTER TABLE llx_bank_extrafields RENAME COLUMN comprovativo TO receipt',
	'SELECT 1'
);
PREPARE kb_stmt FROM @kb_sql;
EXECUTE kb_stmt;
DEALLOCATE PREPARE kb_stmt;

SET @kb_sql := IF(
	@kb_has_bank_extrafields = 1 AND @kb_has_comprovativo = 1 AND @kb_has_receipt = 1,
	'UPDATE llx_bank_extrafields SET receipt = IF(receipt IS NULL OR receipt = '''', comprovativo, receipt)',
	'SELECT 1'
);
PREPARE kb_stmt FROM @kb_sql;
EXECUTE kb_stmt;
DEALLOCATE PREPARE kb_stmt;

SET @kb_sql := IF(
	@kb_has_bank_extrafields = 1 AND @kb_has_comprovativo = 1 AND @kb_has_receipt = 1,
	'ALTER TABLE llx_bank_extrafields DROP COLUMN comprovativo',
	'SELECT 1'
);
PREPARE kb_stmt FROM @kb_sql;
EXECUTE kb_stmt;
DEALLOCATE PREPARE kb_stmt;

SET @kb_sql := IF(
	@kb_has_bank_extrafields = 1 AND @kb_has_comprovativo = 0 AND @kb_has_receipt = 0,
	'ALTER TABLE llx_bank_extrafields ADD COLUMN receipt VARCHAR(255) DEFAULT NULL',
	'SELECT 1'
);
PREPARE kb_stmt FROM @kb_sql;
EXECUTE kb_stmt;
DEALLOCATE PREPARE kb_stmt;

SET @kb_has_extrafields := (
	SELECT COUNT(*)
	FROM INFORMATION_SCHEMA.TABLES
	WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'llx_extrafields'
);

SET @kb_sql := IF(
	@kb_has_extrafields = 1,
	'DELETE e_old
	 FROM llx_extrafields e_old
	 INNER JOIN llx_extrafields e_new ON e_new.elementtype = e_old.elementtype AND e_new.entity = e_old.entity AND e_new.name = ''receipt''
	 WHERE e_old.elementtype = ''bank'' AND e_old.name = ''comprovativo''',
	'SELECT 1'
);
PREPARE kb_stmt FROM @kb_sql;
EXECUTE kb_stmt;
DEALLOCATE PREPARE kb_stmt;

SET @kb_sql := IF(
	@kb_has_extrafields = 1,
	'UPDATE llx_extrafields SET name = ''receipt'', label = ''Receipt'' WHERE elementtype = ''bank'' AND name = ''comprovativo''',
	'SELECT 1'
);
PREPARE kb_stmt FROM @kb_sql;
EXECUTE kb_stmt;
DEALLOCATE PREPARE kb_stmt;

SET @kb_sql := IF(
	@kb_has_extrafields = 1,
	'INSERT INTO llx_extrafields
	(name,label,type,size,elementtype,fieldunique,fieldrequired,param,pos,alwayseditable,perms,langs,list,printable,totalizable,fielddefault,fieldcomputed,entity,enabled,help,aiprompt,css,cssview,csslist)
	SELECT
	''receipt'',''Receipt'',''varchar'',''255'',''bank'',0,0,'''',100,1,'''','''',''1'',0,0,NULL,NULL,0,''1'',''Receipt/proof document reference'','''','''','''',''''
	FROM DUAL
	WHERE NOT EXISTS (
		SELECT 1 FROM llx_extrafields
		WHERE elementtype = ''bank'' AND name = ''receipt'' AND entity = 0
	)',
	'SELECT 1'
);
PREPARE kb_stmt FROM @kb_sql;
EXECUTE kb_stmt;
DEALLOCATE PREPARE kb_stmt;
