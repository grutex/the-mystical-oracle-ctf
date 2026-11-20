-- Persistent login allowance. Existing sessions and account permissions are unchanged.
USE MYSTICAL_ORACLE_LOGIN_DB;
ALTER TABLE Account
    ADD COLUMN IF NOT EXISTS login_failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS login_blocked_until DATETIME(6) NULL;
