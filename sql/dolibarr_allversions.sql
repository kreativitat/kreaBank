--
-- Script run when an upgrade of Dolibarr is done. Whatever is the Dolibarr version.
--

--
-- Legacy conversion query:
-- Rename llx_bank_extrafields.comprovativo to llx_bank_extrafields.receipt
-- and align llx_extrafields metadata.
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

UPDATE llx_extrafields
SET name = 'receipt', label = 'Receipt'
WHERE elementtype = 'bank' AND name = 'comprovativo';
