-- MariaDB 11. Back up the existing database before applying.
-- Run once against the ORIGINAL app_login schema; safe to reapply.
USE MYSTICAL_ORACLE_LOGIN_DB;
ALTER TABLE Account
    MODIFY username VARCHAR(32) NOT NULL,
    MODIFY alias VARCHAR(60) NOT NULL,
    ADD COLUMN IF NOT EXISTS email VARCHAR(254) NULL,
    ADD COLUMN IF NOT EXISTS questions_count INT NOT NULL DEFAULT 0;
CREATE UNIQUE INDEX IF NOT EXISTS account_email_unique ON Account(email);
ALTER TABLE CtfInfo ADD COLUMN IF NOT EXISTS light_dark_flag_solved_at DATETIME NULL;
ALTER TABLE FlagSubmissions MODIFY submitted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6);
CREATE INDEX IF NOT EXISTS submissions_user_time ON FlagSubmissions(user_id, submitted_at);
CREATE TABLE IF NOT EXISTS Challenges (
    challenge_name VARCHAR(20) PRIMARY KEY,
    title VARCHAR(80) NOT NULL,
    points INT UNSIGNED NOT NULL,
    validation_type ENUM('static', 'fire_hmac', 'personal_hmac') NOT NULL DEFAULT 'static',
    flag_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS FlagSubmissions (
    sub_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    challenge_name VARCHAR(20) NOT NULL,
    submitted_flag VARCHAR(255) NOT NULL,
    is_correct BOOLEAN NOT NULL,
    submitted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ip_address VARCHAR(45) NULL,
    INDEX submissions_user_time (user_id, submitted_at),
    FOREIGN KEY (user_id) REFERENCES Account(acc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ChallengeSolves (
    user_id INT NOT NULL,
    challenge_name VARCHAR(20) NOT NULL,
    points INT UNSIGNED NOT NULL,
    solved_at DATETIME(6) NOT NULL,
    PRIMARY KEY (user_id, challenge_name),
    FOREIGN KEY (user_id) REFERENCES Account(acc_id) ON DELETE CASCADE,
    FOREIGN KEY (challenge_name) REFERENCES Challenges(challenge_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ContestState (
    id INT PRIMARY KEY,
    ends_at DATETIME(6) NULL,
    closed_at DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO ContestState (id) VALUES (1);
CREATE TABLE IF NOT EXISTS LeaderboardSnapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    created_by INT NULL,
    captured_at DATETIME(6) NOT NULL,
    cutoff_at DATETIME(6) NOT NULL,
    final_marker TINYINT NULL UNIQUE,
    payload LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO Challenges (challenge_name, title, points, validation_type, flag_hash, enabled, sort_order) VALUES
('spirit', 'ESPÍRITO: QUINTESSÊNCIA', 100, 'personal_hmac', NULL, 1, 1),
('air', 'AR: O INTELECTO', 150, 'personal_hmac', NULL, 1, 2),
('fire', 'FOGO: A PAIXÃO', 200, 'fire_hmac', NULL, 1, 3),
('water', 'ÁGUA: O SENTIMENTO', 300, 'personal_hmac', NULL, 0, 4),
('earth', 'TERRA: A MAESTRIA', 250, 'personal_hmac', NULL, 1, 5),
('light_dark', 'LUZ E TREVAS: O EQUILÍBRIO', 350, 'personal_hmac', NULL, 0, 6);

INSERT INTO Account (username, alias, email, password_hash, acc_role) VALUES
('admin', 'Administrador', 'admin@oracle.mystical', '$2y$10$HT/MBxWGl0Pj0e2HkbY2L.kd7.HcC8Hyo51qi8GpK/XaEMMFeqxCm', 'admin'),
('gandalf', 'Gandalf', 'gandalf@oracle.mystical', '$2y$10$Bctm8jXBYDoVUw5tLMNAyuBThPRqQtkbs4wthfqy5LlcFHjncuCXq', 'player')
ON DUPLICATE KEY UPDATE
    alias = VALUES(alias),
    email = VALUES(email),
    password_hash = VALUES(password_hash),
    acc_role = VALUES(acc_role);

INSERT IGNORE INTO CtfInfo (user_id)
SELECT acc_id FROM Account WHERE username IN ('admin', 'gandalf');

-- Preserve per-challenge history. Existing solve records are never replaced.
-- Legacy aggregate scores without per-challenge dates need manual reconciliation.
INSERT IGNORE INTO ChallengeSolves (user_id, challenge_name, points, solved_at)
SELECT legacy.user_id, legacy.challenge_name, c.points, legacy.solved_at
FROM (
    SELECT user_id, 'spirit' challenge_name, spirit_flag_solved_at solved_at FROM CtfInfo
    UNION ALL SELECT user_id, 'air', air_flag_solved_at FROM CtfInfo
    UNION ALL SELECT user_id, 'fire', fire_flag_solved_at FROM CtfInfo
    UNION ALL SELECT user_id, 'water', water_flag_solved_at FROM CtfInfo
    UNION ALL SELECT user_id, 'earth', earth_flag_solved_at FROM CtfInfo
    UNION ALL SELECT user_id, 'light_dark', light_dark_flag_solved_at FROM CtfInfo
) legacy JOIN Challenges c USING (challenge_name)
WHERE legacy.solved_at IS NOT NULL;
