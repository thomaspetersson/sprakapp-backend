-- Update tables to match Supabase structure (based on TypeScript types)

-- Update courses table
ALTER TABLE sprakapp_courses
ADD COLUMN cover_image VARCHAR(500) AFTER language,
ADD COLUMN created_by VARCHAR(36) AFTER order_index,
ADD COLUMN speech_voice_name VARCHAR(255) AFTER created_by,
ADD COLUMN audio_file_url VARCHAR(500) AFTER speech_voice_name,
ADD FOREIGN KEY (created_by) REFERENCES sprakapp_users(id) ON DELETE SET NULL;

-- Update chapters table
ALTER TABLE sprakapp_chapters
CHANGE COLUMN order_index order_number INT DEFAULT 1,
ADD COLUMN target_text TEXT AFTER order_number,
ADD COLUMN translation TEXT AFTER target_text,
ADD COLUMN grammar_explanation TEXT AFTER translation,
ADD COLUMN image_url VARCHAR(500) AFTER grammar_explanation,
ADD COLUMN speech_voice_name VARCHAR(255) AFTER image_url,
ADD COLUMN audio_file_url VARCHAR(500) AFTER speech_voice_name,
ADD COLUMN speech_rate DECIMAL(3,2) DEFAULT 1.00 AFTER audio_file_url;
