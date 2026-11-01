-- Fintable source metadata only. Statement lines use the existing KreaBank staging path.
CREATE TABLE IF NOT EXISTS llx_kreabank_feed_account (
 rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
 entity INTEGER NOT NULL,
 fk_bank_account INTEGER NOT NULL,
 workspace_id VARCHAR(128) NOT NULL,
 provider_account_id VARCHAR(128) NOT NULL,
 provider_connection_id VARCHAR(128) NOT NULL,
 iban VARCHAR(64) NOT NULL,
 currency VARCHAR(3) NOT NULL,
 balance DECIMAL(24,8) NULL,
 balance_available DECIMAL(24,8) NULL,
 bank_updated_at VARCHAR(40) NULL,
 watermark VARCHAR(40) NULL,
 source_start_date DATE NULL,
 history_from DATE NULL,
 history_to DATE NULL,
 last_attempt DATETIME NULL,
 last_success DATETIME NULL,
 status VARCHAR(32) NOT NULL DEFAULT 'ready',
 error_code VARCHAR(255) NULL,
 datec DATETIME NOT NULL,
 tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uk_kb_feed_native (entity, fk_bank_account, workspace_id),
 UNIQUE KEY uk_kb_feed_provider (workspace_id, provider_account_id),
 KEY idx_kb_feed_entity (entity, status)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_kreabank_feed_transaction (
 rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
 entity INTEGER NOT NULL,
 fk_feed_account INTEGER NOT NULL,
 provider_transaction_id VARCHAR(128) NOT NULL,
 external_id_hash CHAR(64) NULL,
 source_uid VARCHAR(128) NOT NULL,
 financial_hash CHAR(64) NOT NULL,
 content_hash CHAR(64) NOT NULL,
 fk_statement_line INTEGER NULL,
 source_payload LONGTEXT NOT NULL,
 conflict_payload LONGTEXT NULL,
 status VARCHAR(32) NOT NULL DEFAULT 'imported',
 datec DATETIME NOT NULL,
 tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uk_kb_feed_transaction (fk_feed_account, provider_transaction_id),
 UNIQUE KEY uk_kb_feed_external (fk_feed_account, external_id_hash),
 KEY idx_kb_feed_tx_entity (entity, status)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_kreabank_feed_run (
 rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
 slot_key VARCHAR(64) NOT NULL,
 status VARCHAR(32) NOT NULL,
 attempts INTEGER NOT NULL DEFAULT 1,
 refresh_requested SMALLINT NOT NULL DEFAULT 0,
 started_at DATETIME NOT NULL,
 finished_at DATETIME NULL,
 result_json LONGTEXT NULL,
 UNIQUE KEY uk_kb_feed_slot (slot_key)
) ENGINE=innodb;
