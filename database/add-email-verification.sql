-- Add email verification support to users table

-- Add columns for email verification
ALTER TABLE sprakapp_users 
ADD COLUMN email_verified BOOLEAN DEFAULT FALSE AFTER password_hash,
ADD COLUMN verification_token VARCHAR(64) NULL AFTER email_verified,
ADD COLUMN verification_token_expires TIMESTAMP NULL AFTER verification_token,
ADD INDEX idx_verification_token (verification_token);

-- Verify existing users (optional - uncomment if you want to mark existing users as verified)
-- UPDATE sprakapp_users SET email_verified = TRUE WHERE email_verified = FALSE;
