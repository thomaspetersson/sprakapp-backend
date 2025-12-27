-- Cleanup script: Delete subscriptions without stripe_subscription_id (never paid)
-- These are subscriptions created but checkout was never completed

-- First, check what will be deleted:
SELECT id, user_id, plan_id, status, stripe_subscription_id, created_at 
FROM sprakapp_user_subscriptions 
WHERE stripe_subscription_id IS NULL OR stripe_subscription_id = '';

-- Then delete them (remove the -- to execute):
-- DELETE FROM sprakapp_user_subscriptions 
-- WHERE stripe_subscription_id IS NULL OR stripe_subscription_id = '';
