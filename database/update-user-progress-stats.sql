-- Add stats columns to user_progress table
-- Run this in phpMyAdmin on one.com

ALTER TABLE sprakapp_user_progress
ADD COLUMN vocabulary_correct INT DEFAULT 0 AFTER score,
ADD COLUMN vocabulary_incorrect INT DEFAULT 0 AFTER vocabulary_correct,
ADD COLUMN exercises_correct INT DEFAULT 0 AFTER vocabulary_incorrect,
ADD COLUMN exercises_incorrect INT DEFAULT 0 AFTER exercises_correct,
ADD COLUMN last_accessed TIMESTAMP NULL AFTER exercises_incorrect;
