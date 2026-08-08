-- =============================================================================
-- VCMS — Initial Schema Migration
-- Version : 001
-- Description : Creates all core tables, indexes, triggers, and seeds
--               default settings + Super_Admin account.
-- Idempotent : YES — uses CREATE TABLE IF NOT EXISTS and INSERT IGNORE
-- Character Set : utf8mb4 / utf8mb4_unicode_ci throughout
-- =============================================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET collation_connection = utf8mb4_unicode_ci;

-- Disable FK checks during table creation so order is flexible for re-runs,
-- but we still create in dependency order for clarity.
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- 1. settings
-- =============================================================================
CREATE TABLE IF NOT EXISTS `settings` (
    `id`            BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100)        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `setting_value` TEXT                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `description`   VARCHAR(255)        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `status`        TINYINT UNSIGNED    NOT NULL DEFAULT 1,
    `created_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`    BIGINT UNSIGNED     NULL DEFAULT NULL,
    `updated_by`    BIGINT UNSIGNED     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_settings_key` (`setting_key`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 2. admins
-- =============================================================================
CREATE TABLE IF NOT EXISTS `admins` (
    `id`                     BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `name`                   VARCHAR(100)        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `username`               VARCHAR(50)         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `password_hash`          VARCHAR(255)        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `phone`                  VARCHAR(15)         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `role`                   ENUM('Admin','Super_Admin')
                                                 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Admin',
    `failed_login_count`     TINYINT UNSIGNED    NOT NULL DEFAULT 0,
    `locked_until`           DATETIME            NULL DEFAULT NULL,
    `remember_token`         VARCHAR(64)         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `remember_token_expires` DATETIME            NULL DEFAULT NULL,
    `status`                 TINYINT UNSIGNED    NOT NULL DEFAULT 1,
    `created_at`             DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`             BIGINT UNSIGNED     NULL DEFAULT NULL,
    `updated_by`             BIGINT UNSIGNED     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admins_username` (`username`),
    KEY `idx_admins_status` (`status`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 3. cycles
-- =============================================================================
CREATE TABLE IF NOT EXISTS `cycles` (
    `id`                  BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `cycle_number`        SMALLINT UNSIGNED   NOT NULL,
    `name`                VARCHAR(100)        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `cycle_status`        ENUM('Active','Completed')
                                              CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
    `started_at_bs_year`  SMALLINT UNSIGNED   NOT NULL,
    `started_at_bs_month` TINYINT UNSIGNED    NOT NULL,
    `ended_at_bs_year`    SMALLINT UNSIGNED   NULL DEFAULT NULL,
    `ended_at_bs_month`   TINYINT UNSIGNED    NULL DEFAULT NULL,
    `status`              TINYINT UNSIGNED    NOT NULL DEFAULT 1,
    `created_at`          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`          BIGINT UNSIGNED     NULL DEFAULT NULL,
    `updated_by`          BIGINT UNSIGNED     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_cycles_cycle_status` (`cycle_status`),
    KEY `idx_cycles_cycle_number` (`cycle_number`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 4. accounting_periods
-- =============================================================================
CREATE TABLE IF NOT EXISTS `accounting_periods` (
    `id`            BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `cycle_id`      BIGINT UNSIGNED     NOT NULL,
    `bs_year`       SMALLINT UNSIGNED   NOT NULL,
    `bs_month`      TINYINT UNSIGNED    NOT NULL,
    `period_status` ENUM('OPEN','CLOSED')
                                        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'OPEN',
    `closed_at`     DATETIME            NULL DEFAULT NULL,
    `closed_by`     BIGINT UNSIGNED     NULL DEFAULT NULL,
    `summary_json`  JSON                NULL DEFAULT NULL,
    `status`        TINYINT UNSIGNED    NOT NULL DEFAULT 1,
    `created_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`    BIGINT UNSIGNED     NULL DEFAULT NULL,
    `updated_by`    BIGINT UNSIGNED     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ap_year_month_cycle` (`bs_year`, `bs_month`, `cycle_id`),
    KEY `idx_ap_bs_year`       (`bs_year`),
    KEY `idx_ap_bs_month`      (`bs_month`),
    KEY `idx_ap_period_status` (`period_status`),
    CONSTRAINT `fk_ap_cycle_id`
        FOREIGN KEY (`cycle_id`)  REFERENCES `cycles`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ap_closed_by`
        FOREIGN KEY (`closed_by`) REFERENCES `admins`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 5. members
-- =============================================================================
CREATE TABLE IF NOT EXISTS `members` (
    `id`                 BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `member_id`          VARCHAR(10)         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `member_seq`         INT UNSIGNED        NOT NULL,
    `full_name`          VARCHAR(100)        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `phone`              VARCHAR(15)         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `address`            VARCHAR(255)        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `join_date_bs_year`  SMALLINT UNSIGNED   NOT NULL,
    `join_date_bs_month` TINYINT UNSIGNED    NOT NULL,
    `join_date_bs_day`   TINYINT UNSIGNED    NOT NULL,
    `join_date_ad`       DATE                NOT NULL,
    `notes`              TEXT                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `status`             TINYINT UNSIGNED    NOT NULL DEFAULT 1,
    `created_at`         DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`         BIGINT UNSIGNED     NULL DEFAULT NULL,
    `updated_by`         BIGINT UNSIGNED     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_members_member_id`  (`member_id`),
    UNIQUE KEY `uq_members_member_seq` (`member_seq`),
    KEY        `idx_members_phone`     (`phone`),
    KEY        `idx_members_status`    (`status`),
    FULLTEXT KEY `ft_members_full_name` (`full_name`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 6. saving_transactions
-- =============================================================================
CREATE TABLE IF NOT EXISTS `saving_transactions` (
    `id`                        BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `cycle_id`                  BIGINT UNSIGNED   NOT NULL,
    `accounting_period_id`      BIGINT UNSIGNED   NOT NULL,
    `member_id`                 BIGINT UNSIGNED   NOT NULL,
    `amount`                    DECIMAL(15,2)     NOT NULL,
    `transaction_date_bs_year`  SMALLINT UNSIGNED NOT NULL,
    `transaction_date_bs_month` TINYINT UNSIGNED  NOT NULL,
    `transaction_date_ad`       DATE              NOT NULL,
    `remarks`                   VARCHAR(255)      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `status`                    TINYINT UNSIGNED  NOT NULL DEFAULT 1,
    `created_at`                DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`                BIGINT UNSIGNED   NULL DEFAULT NULL,
    `updated_by`                BIGINT UNSIGNED   NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_st_member_period` (`member_id`, `accounting_period_id`),
    KEY `idx_st_member_id`           (`member_id`),
    KEY `idx_st_accounting_period_id`(`accounting_period_id`),
    KEY `idx_st_cycle_id`            (`cycle_id`),
    CONSTRAINT `fk_st_cycle_id`
        FOREIGN KEY (`cycle_id`)             REFERENCES `cycles`(`id`)             ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_st_accounting_period_id`
        FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_st_member_id`
        FOREIGN KEY (`member_id`)            REFERENCES `members`(`id`)            ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 7. interest_transactions
-- =============================================================================
CREATE TABLE IF NOT EXISTS `interest_transactions` (
    `id`                   BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `cycle_id`             BIGINT UNSIGNED   NOT NULL,
    `accounting_period_id` BIGINT UNSIGNED   NOT NULL,
    `member_id`            BIGINT UNSIGNED   NOT NULL,
    `amount`               DECIMAL(15,2)     NOT NULL,
    `balance_before`       DECIMAL(15,2)     NOT NULL,
    `status`               TINYINT UNSIGNED  NOT NULL DEFAULT 1,
    `created_at`           DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`           BIGINT UNSIGNED   NULL DEFAULT NULL,
    `updated_by`           BIGINT UNSIGNED   NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_it_member_id`            (`member_id`),
    KEY `idx_it_accounting_period_id` (`accounting_period_id`),
    KEY `idx_it_cycle_id`             (`cycle_id`),
    CONSTRAINT `fk_it_cycle_id`
        FOREIGN KEY (`cycle_id`)             REFERENCES `cycles`(`id`)             ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_it_accounting_period_id`
        FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_it_member_id`
        FOREIGN KEY (`member_id`)            REFERENCES `members`(`id`)            ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 8. loans
-- =============================================================================
CREATE TABLE IF NOT EXISTS `loans` (
    `id`                    BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `cycle_id`              BIGINT UNSIGNED   NOT NULL,
    `accounting_period_id`  BIGINT UNSIGNED   NOT NULL,
    `member_id`             BIGINT UNSIGNED   NOT NULL,
    `loan_amount`           DECIMAL(15,2)     NOT NULL,
    `outstanding_principal` DECIMAL(15,2)     NOT NULL,
    `accrued_interest`      DECIMAL(15,2)     NOT NULL DEFAULT 0.00,
    `interest_rate`         DECIMAL(5,2)      NOT NULL,
    `loan_date_bs_year`     SMALLINT UNSIGNED NOT NULL,
    `loan_date_bs_month`    TINYINT UNSIGNED  NOT NULL,
    `loan_date_ad`          DATE              NOT NULL,
    `loan_status`           ENUM('Outstanding','Completed','Cancelled')
                                              CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Outstanding',
    `remarks`               TEXT              CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `status`                TINYINT UNSIGNED  NOT NULL DEFAULT 1,
    `created_at`            DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`            BIGINT UNSIGNED   NULL DEFAULT NULL,
    `updated_by`            BIGINT UNSIGNED   NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_loans_member_id`            (`member_id`),
    KEY `idx_loans_accounting_period_id` (`accounting_period_id`),
    KEY `idx_loans_cycle_id`             (`cycle_id`),
    KEY `idx_loans_loan_status`          (`loan_status`),
    CONSTRAINT `fk_loans_cycle_id`
        FOREIGN KEY (`cycle_id`)             REFERENCES `cycles`(`id`)             ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_loans_accounting_period_id`
        FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_loans_member_id`
        FOREIGN KEY (`member_id`)            REFERENCES `members`(`id`)            ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 9. repayments
-- =============================================================================
CREATE TABLE IF NOT EXISTS `repayments` (
    `id`                        BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `loan_id`                   BIGINT UNSIGNED   NOT NULL,
    `cycle_id`                  BIGINT UNSIGNED   NOT NULL,
    `accounting_period_id`      BIGINT UNSIGNED   NOT NULL,
    `repayment_type`            ENUM('PrincipalOnly','InterestOnly','Both')
                                                  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `amount`                    DECIMAL(15,2)     NOT NULL,
    `principal_paid`            DECIMAL(15,2)     NOT NULL DEFAULT 0.00,
    `interest_paid`             DECIMAL(15,2)     NOT NULL DEFAULT 0.00,
    `repayment_date_bs_year`    SMALLINT UNSIGNED NOT NULL,
    `repayment_date_bs_month`   TINYINT UNSIGNED  NOT NULL,
    `repayment_date_ad`         DATE              NOT NULL,
    `remarks`                   VARCHAR(255)      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `status`                    TINYINT UNSIGNED  NOT NULL DEFAULT 1,
    `created_at`                DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`                BIGINT UNSIGNED   NULL DEFAULT NULL,
    `updated_by`                BIGINT UNSIGNED   NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_repayments_loan_id`              (`loan_id`),
    KEY `idx_repayments_accounting_period_id` (`accounting_period_id`),
    KEY `idx_repayments_cycle_id`             (`cycle_id`),
    CONSTRAINT `fk_repayments_loan_id`
        FOREIGN KEY (`loan_id`)              REFERENCES `loans`(`id`)              ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_repayments_cycle_id`
        FOREIGN KEY (`cycle_id`)             REFERENCES `cycles`(`id`)             ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_repayments_accounting_period_id`
        FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 10. cash_bank_transactions
-- =============================================================================
CREATE TABLE IF NOT EXISTS `cash_bank_transactions` (
    `id`                        BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `cycle_id`                  BIGINT UNSIGNED   NOT NULL,
    `accounting_period_id`      BIGINT UNSIGNED   NOT NULL,
    `transaction_type`          ENUM('CashIn','CashOut','BankIn','BankOut','CashToBank','BankToCash')
                                                  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `amount`                    DECIMAL(15,2)     NOT NULL,
    `reference_type`            ENUM('Saving','LoanDisbursement','LoanRepayment','Transfer','Distribution','Manual')
                                                  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `reference_id`              BIGINT UNSIGNED   NULL DEFAULT NULL,
    `description`               VARCHAR(255)      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `transaction_date_bs_year`  SMALLINT UNSIGNED NOT NULL,
    `transaction_date_bs_month` TINYINT UNSIGNED  NOT NULL,
    `transaction_date_ad`       DATE              NOT NULL,
    `status`                    TINYINT UNSIGNED  NOT NULL DEFAULT 1,
    `created_at`                DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`                BIGINT UNSIGNED   NULL DEFAULT NULL,
    `updated_by`                BIGINT UNSIGNED   NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_cbt_cycle_id`             (`cycle_id`),
    KEY `idx_cbt_accounting_period_id` (`accounting_period_id`),
    KEY `idx_cbt_transaction_type`     (`transaction_type`),
    KEY `idx_cbt_transaction_date_ad`  (`transaction_date_ad`),
    CONSTRAINT `fk_cbt_cycle_id`
        FOREIGN KEY (`cycle_id`)             REFERENCES `cycles`(`id`)             ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_cbt_accounting_period_id`
        FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 11. distributions
-- =============================================================================
CREATE TABLE IF NOT EXISTS `distributions` (
    `id`                  BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `cycle_id`            BIGINT UNSIGNED   NOT NULL,
    `pdf_generated_at`    DATETIME          NULL DEFAULT NULL,
    `pdf_path`            VARCHAR(255)      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `confirmed_at`        DATETIME          NULL DEFAULT NULL,
    `confirmed_by`        BIGINT UNSIGNED   NULL DEFAULT NULL,
    `total_disbursed`     DECIMAL(15,2)     NULL DEFAULT NULL,
    `member_count`        SMALLINT UNSIGNED NULL DEFAULT NULL,
    `distribution_status` ENUM('Draft','PdfGenerated','Completed')
                                            CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
    `status`              TINYINT UNSIGNED  NOT NULL DEFAULT 1,
    `created_at`          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`          BIGINT UNSIGNED   NULL DEFAULT NULL,
    `updated_by`          BIGINT UNSIGNED   NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_distributions_cycle_id` (`cycle_id`),
    KEY `idx_distributions_distribution_status` (`distribution_status`),
    CONSTRAINT `fk_distributions_cycle_id`
        FOREIGN KEY (`cycle_id`)     REFERENCES `cycles`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_distributions_confirmed_by`
        FOREIGN KEY (`confirmed_by`) REFERENCES `admins`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 12. distribution_items
-- =============================================================================
CREATE TABLE IF NOT EXISTS `distribution_items` (
    `id`                      BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `distribution_id`         BIGINT UNSIGNED   NOT NULL,
    `cycle_id`                BIGINT UNSIGNED   NOT NULL,
    `member_id`               BIGINT UNSIGNED   NOT NULL,
    `total_savings`           DECIMAL(15,2)     NOT NULL,
    `total_interest`          DECIMAL(15,2)     NOT NULL,
    `total_outstanding_loan`  DECIMAL(15,2)     NOT NULL,
    `final_payable`           DECIMAL(15,2)     NOT NULL,
    `is_shortfall`            TINYINT(1)        NOT NULL DEFAULT 0,
    `status`                  TINYINT UNSIGNED  NOT NULL DEFAULT 1,
    `created_at`              DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`              BIGINT UNSIGNED   NULL DEFAULT NULL,
    `updated_by`              BIGINT UNSIGNED   NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_di_distribution_id` (`distribution_id`),
    KEY `idx_di_cycle_id`        (`cycle_id`),
    KEY `idx_di_member_id`       (`member_id`),
    CONSTRAINT `fk_di_distribution_id`
        FOREIGN KEY (`distribution_id`) REFERENCES `distributions`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_di_cycle_id`
        FOREIGN KEY (`cycle_id`)        REFERENCES `cycles`(`id`)        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_di_member_id`
        FOREIGN KEY (`member_id`)       REFERENCES `members`(`id`)       ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 13. audit_logs  (insert-only — protected by triggers below)
-- =============================================================================
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`            BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `logged_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `admin_username`VARCHAR(100)      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `action_type`   VARCHAR(100)      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `description`   VARCHAR(500)      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `ip_address`    VARCHAR(45)       CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unavailable',
    `user_agent`    VARCHAR(255)      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unavailable',
    `status`        TINYINT UNSIGNED  NOT NULL DEFAULT 1,
    `created_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`    BIGINT UNSIGNED   NULL DEFAULT NULL,
    `updated_by`    BIGINT UNSIGNED   NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_al_logged_at`      (`logged_at`),
    KEY `idx_al_admin_username` (`admin_username`),
    KEY `idx_al_action_type`    (`action_type`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- audit_logs triggers — block UPDATE and DELETE at the database level
-- Uses DROP + CREATE pattern so re-running the migration is safe.
-- =============================================================================
DROP TRIGGER IF EXISTS `trg_audit_logs_before_update`;
DELIMITER $$
CREATE TRIGGER `trg_audit_logs_before_update`
BEFORE UPDATE ON `audit_logs`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'UPDATE on audit_logs is not permitted. Audit records are immutable.';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_audit_logs_before_delete`;
DELIMITER $$
CREATE TRIGGER `trg_audit_logs_before_delete`
BEFORE DELETE ON `audit_logs`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'DELETE on audit_logs is not permitted. Audit records are immutable.';
END$$
DELIMITER ;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- SEED DATA
-- All seeds use INSERT IGNORE so re-running the migration is a no-op.
-- =============================================================================

-- Default cooperative settings
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`, `status`) VALUES
    ('cooperative_name',    'My Cooperative', 'Display name of the cooperative',              1),
    ('fixed_monthly_saving','1000',           'Fixed monthly saving amount in NPR',            1),
    ('interest_rate_annual','12',             'Annual savings interest rate (%), fixed at 12', 1),
    ('default_language',    'en',             'Default UI language: en or ne',                 1),
    ('member_seq_counter',  '0',              'Monotonic counter behind Member_ID generation; never decremented so IDs are never reused', 1);

-- Default Super_Admin account
-- Password: admin123  →  bcrypt hash (cost 10)
-- Generated with: password_hash('admin123', PASSWORD_BCRYPT, ['cost' => 10])
INSERT IGNORE INTO `admins`
    (`name`, `username`, `password_hash`, `role`, `status`)
VALUES
    (
        'System Admin',
        'admin',
        '$2y$10$4fhoCj/aImIVDHEG2t9uM.aAfeArqzm0BzW5DkHYuiLI4Fj7k/8/W',
        'Super_Admin',
        1
    );

-- =============================================================================
-- Opening cycle and first accounting period
--
-- Nothing else in the system can create these: MonthCloseService opens the next
-- period only when one is already OPEN, and DistributionService opens the next
-- cycle only when one is already Active. Without this seed a fresh install has
-- no period to post against, so savings, loans and transfers all fail with
-- "No open accounting period found."
--
-- SQL cannot convert AD → BS, so the opening month is a literal. It is set to
-- Shrawan 2083 (the worked example in the specification). If you are installing
-- in a different Nepali month, change the two values below BEFORE first run —
-- afterwards use Month Close to advance, never an UPDATE.
--
-- Both statements are guarded by NOT EXISTS rather than INSERT IGNORE, because
-- neither table has a unique key that a duplicate row would collide with.
-- =============================================================================

INSERT INTO `cycles`
    (`cycle_number`, `name`, `cycle_status`, `started_at_bs_year`, `started_at_bs_month`, `status`)
SELECT 1, 'Cycle 1', 'Active', 2083, 4, 1
 WHERE NOT EXISTS (SELECT 1 FROM `cycles`);

INSERT INTO `accounting_periods`
    (`cycle_id`, `bs_year`, `bs_month`, `period_status`, `status`)
SELECT c.`id`, 2083, 4, 'OPEN', 1
  FROM `cycles` c
 WHERE c.`cycle_number` = 1
   AND NOT EXISTS (SELECT 1 FROM `accounting_periods`);
