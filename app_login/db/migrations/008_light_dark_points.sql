USE MYSTICAL_ORACLE_LOGIN_DB;

START TRANSACTION;

UPDATE Challenges SET points = 350 WHERE challenge_name = 'light_dark';
UPDATE ChallengeSolves SET points = 350 WHERE challenge_name = 'light_dark';
UPDATE CtfInfo i
SET score = (SELECT COALESCE(SUM(s.points), 0) FROM ChallengeSolves s WHERE s.user_id = i.user_id)
WHERE EXISTS (SELECT 1 FROM ChallengeSolves s WHERE s.user_id = i.user_id AND s.challenge_name = 'light_dark');

COMMIT;
