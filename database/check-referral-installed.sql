-- Quick check if referral system is installed
-- Run this FIRST before the fix script

-- Check if referred_by column exists in users table
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'sprakapp_users'
  AND COLUMN_NAME IN ('referral_code', 'referred_by', 'trial_expires_at', 'onboarding_completed');

-- Check if referral tables exist
SELECT 
    TABLE_NAME,
    TABLE_ROWS
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME LIKE 'sprakapp_referral%';

-- If you see NO results above, you need to run: referral-schema.sql first!
