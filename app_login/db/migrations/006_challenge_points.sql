USE MYSTICAL_ORACLE_LOGIN_DB;

START TRANSACTION;

UPDATE Challenges
SET points = CASE challenge_name
    WHEN 'spirit' THEN 100
    WHEN 'air' THEN 150
    WHEN 'fire' THEN 200
    WHEN 'water' THEN 275
    WHEN 'earth' THEN 275
END
WHERE challenge_name IN ('spirit', 'air', 'fire', 'water', 'earth');

UPDATE ChallengeSolves s
JOIN Challenges c USING (challenge_name)
SET s.points = c.points
WHERE s.challenge_name IN ('spirit', 'air', 'fire', 'water', 'earth');

UPDATE CtfInfo i
SET score = (SELECT COALESCE(SUM(s.points), 0) FROM ChallengeSolves s WHERE s.user_id = i.user_id);

COMMIT;
