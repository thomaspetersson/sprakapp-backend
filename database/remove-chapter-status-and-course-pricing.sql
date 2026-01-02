-- Migration: Remove chapter status/is_published and course pricing
-- This migration:
-- 1. Removes status and is_published columns from chapters (should NEVER have existed)
-- 2. Removes price_monthly and currency from courses (pricing is now subscription-based only)
-- 3. Removes is_published from courses (we use status ENUM instead)

-- Step 1: Remove idx_published index from chapters if it exists
ALTER TABLE sprakapp_chapters 
DROP INDEX IF EXISTS idx_published;

-- Step 2: Remove status column from chapters if it exists
ALTER TABLE sprakapp_chapters 
DROP COLUMN IF EXISTS status;

-- Step 3: Remove is_published column from chapters (should never have existed)
-- Note: This might fail if column doesn't exist, that's OK
ALTER TABLE sprakapp_chapters 
DROP COLUMN IF EXISTS is_published;

-- Step 4: Remove idx_status index from chapters if it exists
ALTER TABLE sprakapp_chapters 
DROP INDEX IF EXISTS idx_status;

-- Step 5: Remove price_monthly column from courses
ALTER TABLE sprakapp_courses 
DROP COLUMN IF EXISTS price_monthly;

-- Step 6: Remove currency column from courses
ALTER TABLE sprakapp_courses 
DROP COLUMN IF EXISTS currency;

-- Step 7: Remove is_published column from courses (we use status ENUM instead)
ALTER TABLE sprakapp_courses 
DROP COLUMN IF EXISTS is_published;

-- Step 8: Remove idx_published index from courses if it exists
ALTER TABLE sprakapp_courses 
DROP INDEX IF EXISTS idx_published;

-- Verify the changes
SELECT 'Chapters table columns:' AS info;
DESCRIBE sprakapp_chapters;

SELECT 'Courses table columns:' AS info;
DESCRIBE sprakapp_courses;
