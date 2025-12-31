-- Add Reward Tiers to Referral System
-- This extends the existing single-reward system to support multiple reward tiers

-- =====================================================
-- First, update existing tables to use free_days instead of free_month
-- =====================================================

-- Step 1: Add new enum value to existing tables
ALTER TABLE sprakapp_referral_config 
MODIFY COLUMN reward_type ENUM('free_month', 'free_days', 'credits') NOT NULL DEFAULT 'free_days';

ALTER TABLE sprakapp_referral_rewards 
MODIFY COLUMN reward_type ENUM('free_month', 'free_days', 'credits') NOT NULL;

-- Step 2: Update all existing free_month entries to free_days
UPDATE sprakapp_referral_config SET reward_type = 'free_days' WHERE reward_type = 'free_month';
UPDATE sprakapp_referral_rewards SET reward_type = 'free_days' WHERE reward_type = 'free_month';

-- Step 3: Remove free_month from enum (now that all data is migrated)
ALTER TABLE sprakapp_referral_config 
MODIFY COLUMN reward_type ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days';

ALTER TABLE sprakapp_referral_rewards 
MODIFY COLUMN reward_type ENUM('free_days', 'credits') NOT NULL;

-- =====================================================
-- Reward Tiers Table (Dynamic reward levels)
-- =====================================================
CREATE TABLE IF NOT EXISTS sprakapp_referral_reward_tiers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    -- How many successful invites needed for this tier
    required_invites INT NOT NULL,
    -- Type of reward given
    reward_type ENUM('free_days', 'credits') NOT NULL DEFAULT 'free_days',
    -- Value of reward (days for free_month, credits count)
    reward_value INT NOT NULL,
    -- Chapter limit for this reward period (NULL = unlimited)
    chapter_limit INT DEFAULT NULL,
    -- Whether this tier is active
    is_active BOOLEAN DEFAULT TRUE,
    -- Display order (lower numbers show first)
    display_order INT DEFAULT 0,
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Ensure unique required_invites per configuration
    UNIQUE KEY uk_required_invites (required_invites),
    INDEX idx_required_invites (required_invites),
    INDEX idx_display_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default reward tiers (example configuration)
INSERT INTO sprakapp_referral_reward_tiers (required_invites, reward_type, reward_value, chapter_limit, display_order) VALUES
(1, 'free_days', 14, NULL, 1),   -- First reward: 1 invite → 14 days
(2, 'free_days', 30, NULL, 2),   -- Second reward: 2 invites → 30 days
(3, 'free_days', 60, NULL, 3)    -- Third reward: 3 invites → 60 days
ON DUPLICATE KEY UPDATE 
    reward_value = VALUES(reward_value),
    display_order = VALUES(display_order);

-- Add tier_id reference to existing rewards table to track which tier was used
ALTER TABLE sprakapp_referral_rewards 
    ADD COLUMN IF NOT EXISTS tier_id INT DEFAULT NULL COMMENT 'Reference to reward tier used',
    ADD INDEX IF NOT EXISTS idx_tier_id (tier_id);

-- Add foreign key constraint (optional, can be done separately if needed)
-- ALTER TABLE sprakapp_referral_rewards 
--     ADD CONSTRAINT fk_tier_id FOREIGN KEY (tier_id) REFERENCES sprakapp_referral_reward_tiers(id) ON DELETE SET NULL;

-- =====================================================
-- Updated View: Enhanced Referral Statistics
-- =====================================================
CREATE OR REPLACE VIEW sprakapp_referral_stats_enhanced AS
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
    -- Next available reward tier
    (SELECT t.id FROM sprakapp_referral_reward_tiers t 
     WHERE t.required_invites > (
         SELECT COUNT(*) FROM sprakapp_referral_events e 
         WHERE e.referrer_user_id = u.id AND e.event_type = 'completed_onboarding'
     ) AND t.is_active = TRUE
     ORDER BY t.required_invites ASC LIMIT 1) AS next_tier_id,
    -- Next reward requirements
    (SELECT t.required_invites FROM sprakapp_referral_reward_tiers t 
     WHERE t.required_invites > (
         SELECT COUNT(*) FROM sprakapp_referral_events e 
         WHERE e.referrer_user_id = u.id AND e.event_type = 'completed_onboarding'
     ) AND t.is_active = TRUE
     ORDER BY t.required_invites ASC LIMIT 1) AS next_reward_at_invites,
    -- Available reward tiers (those user has qualified for but not claimed)
    (SELECT COUNT(*) FROM sprakapp_referral_reward_tiers t 
     WHERE t.required_invites <= (
         SELECT COUNT(*) FROM sprakapp_referral_events e 
         WHERE e.referrer_user_id = u.id AND e.event_type = 'completed_onboarding'
     ) AND t.is_active = TRUE
     AND NOT EXISTS (
         SELECT 1 FROM sprakapp_referral_rewards r 
         WHERE r.user_id = u.id AND r.tier_id = t.id
     )) AS available_rewards_count,
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