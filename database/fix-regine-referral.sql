-- Manual Fix Script for Regine's Referral
-- Run this to retroactively fix the referral link between langleendale → regine

-- 1. First, let's check current state
SELECT 
    'BEFORE FIX - Regine' as label,
    id, email, referral_code, referred_by, trial_expires_at, onboarding_completed 
FROM sprakapp_users 
WHERE email = 'reginepetersson7@gmail.com';

SELECT 
    'BEFORE FIX - Langleendale' as label,
    id, email, referral_code, referred_by, trial_expires_at, onboarding_completed 
FROM sprakapp_users 
WHERE email = 'langleendale@gmail.com';

-- 2. Get the config for trial days
SELECT * FROM sprakapp_referral_config ORDER BY id DESC LIMIT 1;

-- 3. FIX: Set referred_by for Regine to point to Langleendale
UPDATE sprakapp_users 
SET 
    referred_by = (SELECT id FROM sprakapp_users WHERE email = 'langleendale@gmail.com'),
    trial_expires_at = DATE_ADD(NOW(), INTERVAL 14 DAY)  -- Assuming 14 days for invited users
WHERE email = 'reginepetersson7@gmail.com';

-- 4. Create the missing signup event
INSERT INTO sprakapp_referral_events (referrer_user_id, invited_user_id, event_type, created_at)
SELECT 
    (SELECT id FROM sprakapp_users WHERE email = 'langleendale@gmail.com'),
    (SELECT id FROM sprakapp_users WHERE email = 'reginepetersson7@gmail.com'),
    'signup',
    '2025-12-27 15:41:55'  -- Use Regine's registration time
ON DUPLICATE KEY UPDATE created_at = created_at;

-- 5. Since Regine has logged in, mark onboarding as complete and create event
UPDATE sprakapp_users 
SET onboarding_completed = 1
WHERE email = 'reginepetersson7@gmail.com';

INSERT INTO sprakapp_referral_events (referrer_user_id, invited_user_id, event_type)
SELECT 
    (SELECT id FROM sprakapp_users WHERE email = 'langleendale@gmail.com'),
    (SELECT id FROM sprakapp_users WHERE email = 'reginepetersson7@gmail.com'),
    'completed_onboarding'
ON DUPLICATE KEY UPDATE created_at = created_at;

-- 6. Check if this should trigger a reward (if required_invites_for_reward = 1)
-- This will be checked by the API, but we can see the count:
SELECT 
    'Langleendale Stats' as label,
    COUNT(CASE WHEN event_type = 'signup' THEN 1 END) as total_invites,
    COUNT(CASE WHEN event_type = 'completed_onboarding' THEN 1 END) as successful_invites
FROM sprakapp_referral_events
WHERE referrer_user_id = (SELECT id FROM sprakapp_users WHERE email = 'langleendale@gmail.com');

-- 7. Verify the fix
SELECT 
    'AFTER FIX - Regine' as label,
    id, email, referral_code, referred_by, trial_expires_at, onboarding_completed 
FROM sprakapp_users 
WHERE email = 'reginepetersson7@gmail.com';

SELECT 
    'AFTER FIX - Events' as label,
    re.event_type,
    re.created_at,
    u1.email as referrer,
    u2.email as invited
FROM sprakapp_referral_events re
LEFT JOIN sprakapp_users u1 ON re.referrer_user_id = u1.id
LEFT JOIN sprakapp_users u2 ON re.invited_user_id = u2.id
WHERE u1.email = 'langleendale@gmail.com' OR u2.email = 'reginepetersson7@gmail.com';

-- SUMMARY:
-- After running this script:
-- 1. Regine will show as referred by Langleendale
-- 2. Langleendale will see 1 total invite and 1 successful invite
-- 3. Both signup and completed_onboarding events will exist
-- 4. Regine will have 14 days trial (adjust if needed)
