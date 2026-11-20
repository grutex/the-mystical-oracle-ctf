-- Fresh installation. Existing databases: apply migrations/001_portal.sql instead.
CREATE DATABASE IF NOT EXISTS MYSTICAL_ORACLE_LOGIN_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE MYSTICAL_ORACLE_LOGIN_DB;
CREATE TABLE IF NOT EXISTS Account (
    acc_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(32) NOT NULL UNIQUE,
    alias VARCHAR(60) NOT NULL,
    email VARCHAR(254) NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    acc_role VARCHAR(20) NOT NULL DEFAULT 'player',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_login DATETIME(6) NULL,
    login_failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    login_blocked_until DATETIME(6) NULL,
    is_banned BOOLEAN NOT NULL DEFAULT FALSE,
    ignore_leaderboard BOOLEAN NOT NULL DEFAULT FALSE,
    questions_count INT NOT NULL DEFAULT 0,
    has_crown BOOLEAN NOT NULL DEFAULT FALSE,
    crowned_at DATETIME(6) NULL,
    ctf_completed_at DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Compatibility with the original schema; score is derived from ChallengeSolves.
CREATE TABLE IF NOT EXISTS CtfInfo (
    user_id INT PRIMARY KEY,
    spirit_flag_solved_at DATETIME NULL,
    air_flag_solved_at DATETIME NULL,
    fire_flag_solved_at DATETIME NULL,
    water_flag_solved_at DATETIME NULL,
    earth_flag_solved_at DATETIME NULL,
    light_dark_flag_solved_at DATETIME NULL,
    challenge_solved_at DATETIME NULL,
    oracle_finale_at DATETIME(6) NULL,
    last_solve_at DATETIME NULL,
    solves_count INT DEFAULT 0,
    score INT DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES Account(acc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
    started_at DATETIME(6) NULL,
    paused_at DATETIME(6) NULL,
    paused_seconds DOUBLE NOT NULL DEFAULT 0,
    session_epoch INT NOT NULL DEFAULT 0,
    ends_at DATETIME(6) NULL,
    closed_at DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO ContestState (id) VALUES (1);
CREATE TABLE IF NOT EXISTS OracleWhispers (
    whisper_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    author_id INT NULL,
    message TEXT NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (author_id) REFERENCES Account(acc_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
('water', 'ÁGUA: O SENTIMENTO', 300, 'personal_hmac', NULL, 1, 4),
('earth', 'TERRA: A MAESTRIA', 250, 'personal_hmac', NULL, 1, 5),
('light_dark', 'LUZ E TREVAS: O EQUILÍBRIO', 350, 'personal_hmac', NULL, 1, 6);

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

INSERT INTO Account (username, alias, email, password_hash, acc_role, ignore_leaderboard)
VALUES ('Fofão', 'Fofão', 'theffz@oracle.mystical', '$2y$10$CAKmCDI6hJlIB/poPYR0zO9H6aM5yu46q.HyeJNRq0AmevhtW8NDu', 'player', TRUE)
ON DUPLICATE KEY UPDATE
    email = VALUES(email),
    password_hash = VALUES(password_hash),
    ignore_leaderboard = TRUE;

INSERT IGNORE INTO CtfInfo (user_id)
SELECT acc_id FROM Account WHERE username = 'Fofão';


USE MYSTICAL_ORACLE_LOGIN_DB;
ALTER TABLE Account ADD COLUMN IF NOT EXISTS is_banned BOOLEAN NOT NULL DEFAULT FALSE;
CREATE TABLE IF NOT EXISTS SystemLogs (
    log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    username VARCHAR(32) NOT NULL,
    email VARCHAR(254) NULL,
    event_type VARCHAR(40) NOT NULL,
    success BOOLEAN NULL,
    challenge_name VARCHAR(20) NULL,
    submitted_flag VARCHAR(255) NULL,
    detail VARCHAR(500) NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    submission_id INT NULL UNIQUE,
    INDEX logs_account_cursor (user_id, log_id),
    INDEX logs_event_time (event_type, created_at),
    FOREIGN KEY (user_id) REFERENCES Account(acc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS Warnings (
    warning_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    log_id BIGINT NOT NULL UNIQUE,
    user_id INT NULL,
    ip_address VARCHAR(45) NULL,
    reason VARCHAR(500) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (log_id) REFERENCES SystemLogs(log_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES Account(acc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS AdminLogReads (
    admin_id INT NOT NULL,
    user_id INT NOT NULL,
    last_log_id BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (admin_id, user_id),
    FOREIGN KEY (admin_id) REFERENCES Account(acc_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES Account(acc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO SystemLogs (user_id, username, email, event_type, success, challenge_name, submitted_flag, detail, ip_address, created_at, submission_id)
SELECT f.user_id, a.username, a.email, 'flag_submit', f.is_correct, f.challenge_name, f.submitted_flag,
    IF(f.is_correct, 'Flag correta.', 'Flag incorreta.'), f.ip_address, f.submitted_at, f.sub_id
FROM FlagSubmissions f JOIN Account a ON a.acc_id = f.user_id;
