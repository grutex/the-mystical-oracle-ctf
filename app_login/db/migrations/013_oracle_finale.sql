USE MYSTICAL_ORACLE_LOGIN_DB;

ALTER TABLE Account ADD COLUMN IF NOT EXISTS ctf_completed_at DATETIME(6) NULL;
ALTER TABLE CtfInfo ADD COLUMN IF NOT EXISTS oracle_finale_at DATETIME(6) NULL;

-- Preserve recorded eye activations for compatibility with the original field.
UPDATE Account a
JOIN CtfInfo i ON i.user_id = a.acc_id
SET a.ctf_completed_at = COALESCE(a.ctf_completed_at, i.oracle_finale_at)
WHERE i.oracle_finale_at IS NOT NULL;
