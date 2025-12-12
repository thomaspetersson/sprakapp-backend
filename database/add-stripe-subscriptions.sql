-- Add Stripe subscription tracking to user course access
ALTER TABLE sprakapp_user_course_access
ADD COLUMN stripe_subscription_id VARCHAR(255) NULL AFTER end_date,
ADD COLUMN stripe_customer_id VARCHAR(255) NULL AFTER stripe_subscription_id,
ADD COLUMN subscription_status ENUM('active', 'cancelled', 'expired', 'none') DEFAULT 'none' AFTER stripe_customer_id;

-- Add index for faster lookups
CREATE INDEX idx_stripe_subscription ON sprakapp_user_course_access(stripe_subscription_id);
CREATE INDEX idx_subscription_status ON sprakapp_user_course_access(subscription_status);
