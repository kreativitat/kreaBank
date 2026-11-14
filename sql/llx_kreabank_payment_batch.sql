-- Explicit one-statement-to-many-existing-movements reconciliation.
-- Nullable active keys retain history while preventing concurrent active claims.
CREATE TABLE IF NOT EXISTS llx_kreabank_payment_batch (
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER NOT NULL,
	fk_statement_line INTEGER NOT NULL,
	active_statement_line INTEGER NULL,
	bank_account_id INTEGER NOT NULL,
	amount DECIMAL(24,8) NOT NULL,
	currency VARCHAR(3) NOT NULL,
	operation_date DATE NOT NULL,
	statement_ref VARCHAR(64) NOT NULL,
	name VARCHAR(120) NOT NULL,
	status SMALLINT NOT NULL DEFAULT 1,
	source_snapshot LONGTEXT NOT NULL,
	fk_user INTEGER NOT NULL,
	datec DATETIME NOT NULL,
	fk_user_undo INTEGER NULL,
	date_undo DATETIME NULL,
	UNIQUE KEY uk_kreabank_payment_batch_active (entity, active_statement_line),
	KEY idx_kreabank_payment_batch_source (entity, fk_statement_line, status)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_kreabank_payment_batch_line (
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER NOT NULL,
	fk_batch INTEGER NOT NULL,
	fk_bank_line INTEGER NOT NULL,
	active_bank_line INTEGER NULL,
	amount DECIMAL(24,8) NOT NULL,
	snapshot_json LONGTEXT NOT NULL,
	UNIQUE KEY uk_kreabank_payment_batch_claim (entity, active_bank_line),
	UNIQUE KEY uk_kreabank_payment_batch_member (entity, fk_batch, fk_bank_line),
	KEY idx_kreabank_payment_batch_native (entity, fk_bank_line)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_kreabank_payment_batch_draft (
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER NOT NULL,
	fk_user INTEGER NOT NULL,
	fk_statement_line INTEGER NOT NULL,
	name VARCHAR(120) NOT NULL,
	ids_json LONGTEXT NOT NULL,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uk_kreabank_payment_batch_draft (entity, fk_user, fk_statement_line)
) ENGINE=innodb;
