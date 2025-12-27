-- Check if referral tables exist and create a simple test query file
-- Run this to verify your referral system is set up correctly

-- 1. Check if config table exists
SELECT 'Config table exists' as status, COUNT(*) as config_count 
FROM sprakapp_referral_config;

-- 2. Check if users have referral codes
SELECT 'Users with referral codes' as status, COUNT(*) as count 
FROM sprakapp_users 
WHERE referral_code IS NOT NULL;

-- 3. Check referral events
SELECT 'Total referral events' as status, COUNT(*) as count 
FROM sprakapp_referral_events;

-- 4. Check signup events
SELECT 'Signup events' as status, COUNT(*) as count 
FROM sprakapp_referral_events 
WHERE event_type = 'signup';

-- 5. Check onboarding events
SELECT 'Onboarding events' as status, COUNT(*) as count 
FROM sprakapp_referral_events 
WHERE event_type = 'completed_onboarding';

-- 6. Check rewards
SELECT 'Total rewards' as status, COUNT(*) as count 
FROM sprakapp_referral_rewards;

-- 7. Show all events (debug)
SELECT 
    re.event_type,
    re.created_at,
    u1.email as referrer_email,
    u2.email as invited_email
FROM sprakapp_referral_events re
LEFT JOIN sprakapp_users u1 ON re.referrer_user_id = u1.id
LEFT JOIN sprakapp_users u2 ON re.invited_user_id = u2.id
ORDER BY re.created_at DESC
LIMIT 10;
