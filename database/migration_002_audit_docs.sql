-- =============================================================================
-- Revizor Asszisztens — Migration 002: Audit log & Document storage
-- Target: revizor_db
-- Applied: (manual — run via phpMyAdmin or mysql CLI)
-- =============================================================================

-- 0. Skip import flag for bank accounts
ALTER TABLE church_bank_accounts ADD COLUMN skip_import TINYINT(1) NOT NULL DEFAULT 0 AFTER account_type;

-- 1. reconciliation_audit_log
--    Naplózza a bank_reconciliation rekordok státuszváltozásait,
--    párosítási műveleteit és a checklist mentéseket.
CREATE TABLE IF NOT EXISTS `reconciliation_audit_log` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `bank_reconciliation_id` INT NOT NULL,
    `action` VARCHAR(50) NOT NULL COMMENT 'status_change / match / unmatch / checklist_save / comment / other',
    `old_status` VARCHAR(20) DEFAULT NULL,
    `new_status` VARCHAR(20) DEFAULT NULL,
    `details` JSON DEFAULT NULL COMMENT 'Szabad formátumú adatok (pl. ots_record_id, field changes)',
    `user_id` INT DEFAULT NULL COMMENT 'GN_USER_ID az OTS-ből',
    `user_name` VARCHAR(100) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_bank_rec_id` (`bank_reconciliation_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit log for reconciliation status changes and matches';

-- 2. document_files
--    Metaadatok a feltöltött dokumentumokról (fizikai fájl a storage path-ban).
CREATE TABLE IF NOT EXISTS `document_files` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `original_name` VARCHAR(255) NOT NULL COMMENT 'Eredeti fájlnév',
    `storage_path` VARCHAR(512) NOT NULL COMMENT 'Relatív elérés az storage könyvtárban',
    `mime_type` VARCHAR(127) DEFAULT NULL,
    `file_size_bytes` BIGINT UNSIGNED DEFAULT NULL,
    `file_hash_sha256` CHAR(64) DEFAULT NULL COMMENT 'SHA-256 hash a dedupl. és integritás végett',
    `uploaded_by_user_id` INT DEFAULT NULL COMMENT 'Feltöltő GN_USER_ID',
    `uploaded_by_name` VARCHAR(100) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_storage_path` (`storage_path`),
    INDEX `idx_file_hash` (`file_hash_sha256`),
    INDEX `idx_uploaded_by` (`uploaded_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Uploaded document file metadata';

-- 3. document_links
--    Kapcsolótábla: dokumentumok kötése bank_reconciliation, audit_checklist,
--    vagy church rekordokhoz.
--    A future dokumentumtár Phase 1-hez készült.
CREATE TABLE IF NOT EXISTS `document_links` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `document_file_id` INT UNSIGNED NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL COMMENT 'bank_reconciliation / audit_checklist / church / custom',
    `entity_id` INT NOT NULL,
    `link_label` VARCHAR(255) DEFAULT NULL COMMENT 'Opcionális címke / megjegyzés',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_doc_entity` (`document_file_id`, `entity_type`, `entity_id`),
    INDEX `idx_entity` (`entity_type`, `entity_id`),
    CONSTRAINT `fk_dl_doc_file` FOREIGN KEY (`document_file_id`)
        REFERENCES `document_files` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Links between documents and reconciliation records';

-- =============================================================================
-- Helper: Audit log insert trigger (opcionális — PHP-ből is írható)
-- A státuszváltozások automatikus naplózásához.
-- JELENLEG NINCS AKTIVÁLVA — a PHP oldali naplózás preferált.
-- =============================================================================
-- DELIMITER //
-- CREATE TRIGGER trg_bank_reconciliation_after_update
-- AFTER UPDATE ON bank_reconciliation
-- FOR EACH ROW
-- BEGIN
--     IF OLD.status != NEW.status THEN
--         INSERT INTO reconciliation_audit_log
--             (bank_reconciliation_id, action, old_status, new_status, details, user_name)
--         VALUES
--             (NEW.id, 'status_change', OLD.status, NEW.status, NULL, NEW.updated_by);
--     END IF;
-- END //
-- DELIMITER ;

-- =============================================================================
-- Rollback script (ha kellene visszavonni):
--   DROP TABLE IF EXISTS document_links;
--   DROP TABLE IF EXISTS document_files;
--   DROP TABLE IF EXISTS reconciliation_audit_log;
-- =============================================================================
