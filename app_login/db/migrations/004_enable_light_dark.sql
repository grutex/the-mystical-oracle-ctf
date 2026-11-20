-- Desafio VI: o Caminho das Trevas já está disponível.
USE MYSTICAL_ORACLE_LOGIN_DB;

UPDATE Challenges
SET enabled = TRUE
WHERE challenge_name = 'light_dark';
