USE MYSTICAL_ORACLE_LOGIN_DB;

START TRANSACTION;

-- A mesma trava usada pelo portal serializa a mudança com submissões e resets.
SELECT id FROM ContestState WHERE id = 1 FOR UPDATE;

UPDATE Challenges
SET points = CASE challenge_name WHEN 'water' THEN 300 WHEN 'earth' THEN 250 END
WHERE challenge_name IN ('water', 'earth');

UPDATE ChallengeSolves s
JOIN Challenges c USING (challenge_name)
SET s.points = c.points
WHERE s.challenge_name IN ('water', 'earth');

UPDATE CtfInfo i
SET score = (SELECT COALESCE(SUM(s.points), 0) FROM ChallengeSolves s WHERE s.user_id = i.user_id)
WHERE EXISTS (SELECT 1 FROM ChallengeSolves s WHERE s.user_id = i.user_id AND s.challenge_name IN ('water', 'earth'));

COMMIT;
