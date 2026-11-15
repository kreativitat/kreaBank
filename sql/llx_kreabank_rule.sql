-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
-- Idempotent install/upgrade; execute through the module installer or reviewed CLI migration.
CREATE TABLE IF NOT EXISTS llx_kreabank_rule (
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER NOT NULL,
	label VARCHAR(180) NOT NULL,
	active SMALLINT NOT NULL DEFAULT 0,
	priority INTEGER NOT NULL DEFAULT 100,
	fk_bank_account INTEGER NULL,
	direction VARCHAR(4) NOT NULL DEFAULT 'both',
	conditions_json LONGTEXT NOT NULL,
	action_json LONGTEXT NOT NULL,
	hit_count BIGINT NOT NULL DEFAULT 0,
	last_used DATETIME NULL,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat INTEGER NOT NULL,
	fk_user_modif INTEGER NULL,
	KEY idx_kreabank_rule_priority (entity,active,priority,rowid)
) ENGINE=innodb;
