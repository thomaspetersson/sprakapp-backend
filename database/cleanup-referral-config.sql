-- Clean up referral config table after tier system migration
-- Remove fields that are now handled by reward tiers system

USE your_database_name; -- Replace with actual database name

-- Remove columns that are now handled by tiers
-- These should be done one by one to be safe

-- 1. Remove required_invites_for_reward (now handled by tiers.required_invites)
ALTER TABLE sprakapp_referral_config 
DROP COLUMN IF EXISTS required_invites_for_reward;

-- 2. Remove reward_type (now handled by tiers.reward_type)  
ALTER TABLE sprakapp_referral_config
DROP COLUMN IF EXISTS reward_type;

-- 3. Remove reward_value (now handled by tiers.reward_value)
ALTER TABLE sprakapp_referral_config
DROP COLUMN IF EXISTS reward_value;

-- 4. Remove reward_chapter_limit (now handled by tiers.chapter_limit)
ALTER TABLE sprakapp_referral_config
DROP COLUMN IF EXISTS reward_chapter_limit;

-- Show final structure
DESCRIBE sprakapp_referral_config;

-- Expected remaining fields:
-- - id
-- - new_user_trial_days  
-- - invited_user_trial_days
-- - trial_chapter_limit
-- - is_active
-- - created_at
-- - updated_at