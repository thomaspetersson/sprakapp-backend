-- Add pricing fields to courses table
ALTER TABLE sprakapp_courses
ADD COLUMN price_monthly DECIMAL(10,2) DEFAULT 99.00 AFTER description,
ADD COLUMN stripe_price_id VARCHAR(255) NULL AFTER price_monthly,
ADD COLUMN currency VARCHAR(3) DEFAULT 'SEK' AFTER stripe_price_id;
