USE MYSTICAL_ORACLE_LOGIN_DB;

-- Preserve account ID, credentials, email and all progress references.
UPDATE Account
SET username = 'Fofão', alias = 'Fofão'
WHERE username = 'theffz';
