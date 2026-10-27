-- Fix missing remember_expires (Keep me logged in)
-- Run once in phpMyAdmin on pharmacy_db

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS remember_token VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS remember_expires DATETIME NULL DEFAULT NULL;

-- MariaDB older versions without IF NOT EXISTS:
-- ALTER TABLE users ADD COLUMN remember_expires DATETIME NULL DEFAULT NULL;
