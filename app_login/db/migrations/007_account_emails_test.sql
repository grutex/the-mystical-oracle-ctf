USE MYSTICAL_ORACLE_LOGIN_DB;

START TRANSACTION;

UPDATE Account
SET email = CONCAT(username, '@oracle.mystical')
WHERE email LIKE '%@oracle.local';

INSERT INTO Account (username, alias, email, password_hash, acc_role, ignore_leaderboard)
VALUES ('Fofão', 'Fofão', 'theffz@oracle.mystical', '$2y$10$CAKmCDI6hJlIB/poPYR0zO9H6aM5yu46q.HyeJNRq0AmevhtW8NDu', 'player', TRUE)
ON DUPLICATE KEY UPDATE
    email = VALUES(email),
    password_hash = VALUES(password_hash),
    ignore_leaderboard = TRUE;

INSERT IGNORE INTO CtfInfo (user_id)
SELECT acc_id FROM Account WHERE username = 'Fofão';

COMMIT;
