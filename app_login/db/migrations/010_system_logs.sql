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
