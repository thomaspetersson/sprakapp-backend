-- Add date and chapter limit columns to user_course_access table
-- Run this in phpMyAdmin on one.com

ALTER TABLE sprakapp_user_course_access
ADD COLUMN start_date DATE NULL AFTER course_id,
ADD COLUMN end_date DATE NULL AFTER start_date,
ADD COLUMN chapter_limit INT NULL AFTER end_date;
