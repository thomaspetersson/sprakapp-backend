-- Migration: Add brute force protection tables
-- This migration adds:
-- 1. Failed login tracking table
-- 2. Account lockout table

-- Track failed login attempts
CREATE TABLE IF NOT EXISTS sprakapp_failed_logins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_ip (ip_address),
    INDEX idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track locked accounts
CREATE TABLE IF NOT EXISTS sprakapp_account_lockouts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    email VARCHAR(255) NOT NULL,
    locked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    locked_until TIMESTAMP NOT NULL,
    reason VARCHAR(255) DEFAULT 'Too many failed login attempts',
    is_permanent BOOLEAN DEFAULT FALSE,
    INDEX idx_user_id (user_id),
    INDEX idx_email (email),
    INDEX idx_locked_until (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cleanup old failed attempts (optional - run periodically)
-- DELETE FROM sprakapp_failed_logins WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
