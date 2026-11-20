-- Personaliza as flags dos desafios implementados em instalações existentes.
USE MYSTICAL_ORACLE_LOGIN_DB;

ALTER TABLE Challenges
    MODIFY validation_type ENUM('static', 'fire_hmac', 'personal_hmac') NOT NULL DEFAULT 'static';

UPDATE Challenges
SET validation_type = 'personal_hmac', flag_hash = NULL
WHERE challenge_name IN ('spirit', 'air', 'water', 'earth', 'light_dark');
