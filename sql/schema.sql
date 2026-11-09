-- Schema for the PHP/MySQL yearbook (spec §5.2). Run once in phpMyAdmin.
-- Connection must use utf8mb4; times are Vietnam time (SET time_zone = '+07:00').

CREATE TABLE IF NOT EXISTS alumni_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL,          -- Normalized on INSERT: strtolower(trim())
    fullname VARCHAR(191) NOT NULL,
    graduation_year SMALLINT UNSIGNED NOT NULL,
    education TEXT NOT NULL,
    work TEXT NULL,
    photo_filename VARCHAR(255) NULL,     -- File in static/img/; NULL -> default.jpg
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- Vietnam time
    submitter_ip VARCHAR(45) NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_email (email, is_deleted, id),
    INDEX idx_submitted (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_otps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,       -- password_hash() of the 6-digit code
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    requester_ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,         -- NOW() + INTERVAL 10 MINUTE
    consumed_at DATETIME NULL,            -- Set on success or 5th failure: single use
    INDEX idx_email_active (email, expires_at),
    INDEX idx_ip_rate (requester_ip, created_at),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    success TINYINT(1) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip, created_at),
    INDEX idx_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
