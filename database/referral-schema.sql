-- Referral System Database Schema
-- Version: 1.0
-- Tables use 'sprakapp_' prefix to match existing schema

-- =====================================================
-- Referral Configuration Table (Admin-controlled)
-- =====================================================
CREATE TABLE IF NOT EXISTS sprakapp_referral_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    -- Trial days for new users who sign up normally
    new_user_trial_days INT NOT NULL DEFAULT 7,
    -- Trial days for users invited via referral link
    invited_user_trial_days INT NOT NULL DEFAULT 14,
    -- Chapter limit for trial users (NULL = unlimited)
    trial_chapter_limit INT DEFAULT 3,
    -- Number of successful invites required for reward
    required_invites_for_reward INT NOT NULL DEFAULT 3,
    -- Type of reward given
    reward_type ENUM('free_month', 'credits') NOT NULL DEFAULT 'free_month',
    -- Value of reward (days for free_month, credits count)
    reward_value INT NOT NULL DEFAULT 30,
    -- Chapter limit for reward period (NULL = unlimited)
    reward_chapter_limit INT DEFAULT 3,
    -- Whether referral system is active
    is_active BOOLEAN DEFAULT TRUE,
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add reward_chapter_limit column if it doesn't exist (for existing installations)
SET @dbname = DATABASE();
SET @tablename = 'sprakapp_referral_config';
SET @columnname = 'reward_chapter_limit';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  CONCAT("ALTER TABLE ", @tablename, " ADD COLUMN ", @columnname, " INT DEFAULT 3 COMMENT 'Chapter limit during reward period (NULL = unlimited)' AFTER reward_value")
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Insert default configuration (safe for both new and existing installations)
INSERT INTO sprakapp_referral_config (
    new_user_trial_days, 
    invited_user_trial_days, 
    trial_chapter_limit,
    required_invites_for_reward, 
    reward_type, 
    reward_value,
    reward_chapter_limit,
    is_active
) VALUES (7, 14, 3, 3, 'free_month', 30, 3, TRUE)
ON DUPLICATE KEY UPDATE 
    reward_chapter_limit = COALESCE(reward_chapter_limit, 3);

-- =====================================================
-- Add referral columns to existing users table
-- =====================================================
ALTER TABLE sprakapp_users 
    ADD COLUMN IF NOT EXISTS referral_code VARCHAR(10) UNIQUE,
    ADD COLUMN IF NOT EXISTS referred_by VARCHAR(36),
    ADD COLUMN IF NOT EXISTS trial_expires_at DATETIME,
    ADD COLUMN IF NOT EXISTS onboarding_completed BOOLEAN DEFAULT FALSE,
    ADD INDEX IF NOT EXISTS idx_referral_code (referral_code),
    ADD INDEX IF NOT EXISTS idx_referred_by (referred_by);

-- Add foreign key for referred_by (self-reference)
-- Note: Run this separately if ALTER fails
-- ALTER TABLE sprakapp_users 
--     ADD CONSTRAINT fk_referred_by FOREIGN KEY (referred_by) REFERENCES sprakapp_users(id) ON DELETE SET NULL;

-- =====================================================
-- Referral Events Table (Tracks all referral activities)
-- =====================================================
CREATE TABLE IF NOT EXISTS sprakapp_referral_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    referrer_user_id VARCHAR(36) NOT NULL,
    invited_user_id VARCHAR(36) NOT NULL,
    event_type ENUM('signup', 'completed_onboarding') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Prevent duplicate events for same user+type
    UNIQUE KEY uk_user_event (invited_user_id, event_type),
    INDEX idx_referrer (referrer_user_id),
    INDEX idx_invited (invited_user_id),
    INDEX idx_event_type (event_type),
    FOREIGN KEY (referrer_user_id) REFERENCES sprakapp_users(id) ON DELETE CASCADE,
    FOREIGN KEY (invited_user_id) REFERENCES sprakapp_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Referral Rewards Table (Tracks earned rewards)
-- =====================================================
CREATE TABLE IF NOT EXISTS sprakapp_referral_rewards (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id VARCHAR(36) NOT NULL,
    reward_type ENUM('free_month', 'credits') NOT NULL,
    reward_value INT NOT NULL,
    -- Which course they chose (required for free_month reward)
    course_id VARCHAR(36) DEFAULT NULL,
    -- Chapter limit for this reward period
    chapter_limit INT DEFAULT NULL,
    -- Status of reward
    reward_status ENUM('pending', 'granted', 'expired') NOT NULL DEFAULT 'pending',
    -- How many invites triggered this reward (for tracking tiers)
    invites_at_grant INT NOT NULL,
    -- When they can claim this reward (usually immediately)
    claimable_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- When they claimed it
    claimed_at TIMESTAMP NULL,
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_status (reward_status),
    FOREIGN KEY (user_id) REFERENCES sprakapp_users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES sprakapp_courses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add chapter_limit column if it doesn't exist (for existing installations)
SET @dbname = DATABASE();
SET @tablename = 'sprakapp_referral_rewards';
SET @columnname = 'chapter_limit';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  CONCAT("ALTER TABLE ", @tablename, " ADD COLUMN ", @columnname, " INT DEFAULT NULL COMMENT 'Chapter limit for this reward period' AFTER course_id")
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- =====================================================
-- Referral Credits Table (For credit-based rewards)
-- =====================================================
CREATE TABLE IF NOT EXISTS sprakapp_referral_credits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id VARCHAR(36) NOT NULL UNIQUE,
    credits_balance INT NOT NULL DEFAULT 0,
    credits_earned_total INT NOT NULL DEFAULT 0,
    credits_spent_total INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES sprakapp_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Credit Transactions Log
-- =====================================================
CREATE TABLE IF NOT EXISTS sprakapp_credit_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id VARCHAR(36) NOT NULL,
    transaction_type ENUM('earned', 'spent', 'expired', 'admin_adjustment') NOT NULL,
    amount INT NOT NULL,
    balance_after INT NOT NULL,
    description VARCHAR(255),
    reference_id INT DEFAULT NULL, -- Links to referral_rewards.id or other source
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_type (transaction_type),
    FOREIGN KEY (user_id) REFERENCES sprakapp_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- View: Referral Statistics per User
-- =====================================================
CREATE OR REPLACE VIEW sprakapp_referral_stats AS
SELECT 
    u.id AS user_id,
    u.referral_code,
    u.referred_by,
    u.trial_expires_at,
    -- Count total signups
    (SELECT COUNT(*) FROM sprakapp_referral_events e 
     WHERE e.referrer_user_id = u.id AND e.event_type = 'signup') AS invites_count,
    -- Count completed onboardings (successful invites)
    (SELECT COUNT(*) FROM sprakapp_referral_events e 
     WHERE e.referrer_user_id = u.id AND e.event_type = 'completed_onboarding') AS successful_invites,
    -- Count pending rewards
    (SELECT COUNT(*) FROM sprakapp_referral_rewards r 
     WHERE r.user_id = u.id AND r.reward_status = 'pending') AS pending_rewards,
    -- Count granted rewards
    (SELECT COUNT(*) FROM sprakapp_referral_rewards r 
     WHERE r.user_id = u.id AND r.reward_status = 'granted') AS granted_rewards,
    -- Credits balance
    COALESCE((SELECT credits_balance FROM sprakapp_referral_credits c 
     WHERE c.user_id = u.id), 0) AS credits_balance
FROM sprakapp_users u;
