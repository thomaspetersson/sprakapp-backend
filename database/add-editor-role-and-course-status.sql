-- Migration: Add editor role and change course publishing to status-based system
-- This migration:
-- 1. Adds 'editor' role to user profiles
-- 2. Changes is_published boolean to status ENUM (draft, preview, published)
-- 3. Preserves existing data during migration

-- Step 1: Add 'editor' role to profiles
ALTER TABLE sprakapp_profiles 
MODIFY COLUMN role ENUM('user', 'editor', 'admin') DEFAULT 'user';

-- Step 2: Add new status column to courses
ALTER TABLE sprakapp_courses 
ADD COLUMN status ENUM('draft', 'preview', 'published') DEFAULT 'draft' AFTER is_published;

-- Step 3: Migrate existing data
-- Convert TRUE (1) to 'published' and FALSE (0) to 'draft'
UPDATE sprakapp_courses 
SET status = CASE 
    WHEN is_published = 1 THEN 'published'
    ELSE 'draft'
END;

-- Step 4: Remove old is_published column (optional - uncomment when ready)
-- ALTER TABLE sprakapp_courses DROP COLUMN is_published;

-- Step 5: Add index on status for performance
ALTER TABLE sprakapp_courses 
ADD INDEX idx_status (status);

-- Step 6: Do the same for chapters
ALTER TABLE sprakapp_chapters 
ADD COLUMN status ENUM('draft', 'preview', 'published') DEFAULT 'draft' AFTER is_published;

UPDATE sprakapp_chapters 
SET status = CASE 
    WHEN is_published = 1 THEN 'published'
    ELSE 'draft'
END;

-- Step 7: Add index on chapters status
ALTER TABLE sprakapp_chapters 
ADD INDEX idx_status (status);

-- Step 8: Remove old chapter is_published column (optional - uncomment when ready)
-- ALTER TABLE sprakapp_chapters DROP COLUMN is_published;
