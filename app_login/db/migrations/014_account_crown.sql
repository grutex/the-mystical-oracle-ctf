USE MYSTICAL_ORACLE_LOGIN_DB;

ALTER TABLE Account ADD COLUMN IF NOT EXISTS has_crown BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE Account ADD COLUMN IF NOT EXISTS crowned_at DATETIME(6) NULL;

-- Keep crowns already awarded by the previous implementation. Use the actual
-- eye activation timestamp when available, rather than the last flag's date.
UPDATE Account a
LEFT JOIN CtfInfo i ON i.user_id = a.acc_id
SET a.has_crown = TRUE,
    a.crowned_at = COALESCE(a.crowned_at, i.oracle_finale_at)
WHERE a.ctf_completed_at IS NOT NULL OR i.oracle_finale_at IS NOT NULL;
