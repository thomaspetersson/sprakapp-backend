-- Add 'write' to exercises type ENUM
-- Run this in phpMyAdmin on one.com

ALTER TABLE sprakapp_exercises 
MODIFY COLUMN type ENUM('multiple_choice', 'fill_blank', 'translation', 'matching', 'listening', 'write') NOT NULL;
