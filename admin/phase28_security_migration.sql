-- ============================================================
-- Phase 28: Security Migration
-- Pharmacy ERP — Enterprise Security Hardening
-- ============================================================

-- 1. Login attempt log table (DB-backed rate limiting, per IP)
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `ip_address`   VARCHAR(45)      NOT NULL,
    `username`     VARCHAR(100)     DEFAULT NULL,
    `attempted_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `success`      TINYINT(1)       NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    INDEX `idx_ip_time`  (`ip_address`, `attempted_at`),
    INDEX `idx_user_time`(`username`,   `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Security audit log (tracks security-significant events)
CREATE TABLE IF NOT EXISTS `security_events` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_type`  VARCHAR(60)     NOT NULL,   -- e.g. CSRF_FAIL, XSS_ATTEMPT, PATH_TRAVERSAL
    `user_id`     INT             DEFAULT NULL,
    `ip_address`  VARCHAR(45)     NOT NULL,
    `user_agent`  VARCHAR(500)    DEFAULT NULL,
    `request_uri` VARCHAR(1000)   DEFAULT NULL,
    `details`     TEXT            DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_event_time`(`event_type`, `created_at`),
    INDEX `idx_ip`(`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Ensure active_sessions table has all needed columns
ALTER TABLE `active_sessions`
    ADD COLUMN IF NOT EXISTS `csrf_token` VARCHAR(128) DEFAULT NULL AFTER `username`,
    ADD COLUMN IF NOT EXISTS `user_agent` VARCHAR(500) DEFAULT NULL;

-- 4. Add security_events cleanup event (runs nightly)
CREATE EVENT IF NOT EXISTS `cleanup_security_events`
    ON SCHEDULE EVERY 1 DAY
    DO DELETE FROM `security_events` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 90 DAY);

CREATE EVENT IF NOT EXISTS `cleanup_login_attempts`
    ON SCHEDULE EVERY 1 DAY
    DO DELETE FROM `login_attempts` WHERE `attempted_at` < DATE_SUB(NOW(), INTERVAL 30 DAY);
