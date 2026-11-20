-- Início do Jogo e contas de teste fora da classificação.
USE MYSTICAL_ORACLE_LOGIN_DB;

ALTER TABLE Account
    ADD COLUMN IF NOT EXISTS ignore_leaderboard BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE ContestState
    ADD COLUMN IF NOT EXISTS started_at DATETIME(6) NULL;

UPDATE Account SET ignore_leaderboard = TRUE WHERE username = 'gandalf';
