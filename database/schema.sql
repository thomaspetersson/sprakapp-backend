-- SpråkApp Database Schema for MariaDB
-- Version: 1.0
-- Note: Run this script on your existing database. Tables use 'sprakapp_' prefix.

-- Users table
CREATE TABLE IF NOT EXISTS sprakapp_users (
    id VARCHAR(36) PRIMARY KEY DEFAULT (UUID()),
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Profiles table
CREATE TABLE IF NOT EXISTS sprakapp_profiles (
    id VARCHAR(36) PRIMARY KEY,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    avatar_url VARCHAR(500),
    role ENUM('user', 'admin') DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (id) REFERENCES sprakapp_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Courses table
CREATE TABLE IF NOT EXISTS sprakapp_courses (
    id VARCHAR(36) PRIMARY KEY DEFAULT (UUID()),
    title VARCHAR(255) NOT NULL,
    description TEXT,
    level ENUM('beginner', 'intermediate', 'advanced') DEFAULT 'beginner',
    language VARCHAR(10) DEFAULT 'sv',
    cover_image VARCHAR(500),
    is_published BOOLEAN DEFAULT FALSE,
    order_index INT DEFAULT 0,
    created_by VARCHAR(36),
    speech_voice_name VARCHAR(255),
    audio_file_url VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_published (is_published),
    INDEX idx_language (language),
    FOREIGN KEY (created_by) REFERENCES sprakapp_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chapters table
CREATE TABLE IF NOT EXISTS sprakapp_chapters (
    id VARCHAR(36) PRIMARY KEY DEFAULT (UUID()),
    course_id VARCHAR(36) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    order_number INT DEFAULT 1,
    target_text TEXT,
    translation TEXT,
    grammar_explanation TEXT,
    image_url VARCHAR(500),
    speech_voice_name VARCHAR(255),
    audio_file_url VARCHAR(500),
    speech_rate DECIMAL(3,2) DEFAULT 1.00,
    is_published BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES sprakapp_courses(id) ON DELETE CASCADE,
    INDEX idx_course (course_id),
    INDEX idx_order (course_id, order_number),
    INDEX idx_published (is_published)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vocabulary table
CREATE TABLE IF NOT EXISTS sprakapp_vocabulary (
    id VARCHAR(36) PRIMARY KEY DEFAULT (UUID()),
    chapter_id VARCHAR(36) NOT NULL,
    word VARCHAR(255) NOT NULL,
    translation VARCHAR(255) NOT NULL,
    pronunciation VARCHAR(255),
    audio_url VARCHAR(500),
    example_sentence TEXT,
    order_index INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (chapter_id) REFERENCES sprakapp_chapters(id) ON DELETE CASCADE,
    INDEX idx_chapter (chapter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exercises table
CREATE TABLE IF NOT EXISTS sprakapp_exercises (
    id VARCHAR(36) PRIMARY KEY DEFAULT (UUID()),
    chapter_id VARCHAR(36) NOT NULL,
    type ENUM('multiple_choice', 'fill_blank', 'translation', 'matching', 'listening', 'write') NOT NULL,
    question TEXT NOT NULL,
    correct_answer TEXT NOT NULL,
    options JSON,
    explanation TEXT,
    order_index INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (chapter_id) REFERENCES sprakapp_chapters(id) ON DELETE CASCADE,
    INDEX idx_chapter (chapter_id),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User Progress table
CREATE TABLE IF NOT EXISTS sprakapp_user_progress (
    id VARCHAR(36) PRIMARY KEY DEFAULT (UUID()),
    user_id VARCHAR(36) NOT NULL,
    chapter_id VARCHAR(36) NOT NULL,
    completed BOOLEAN DEFAULT FALSE,
    score INT DEFAULT 0,
    vocabulary_correct INT DEFAULT 0,
    vocabulary_incorrect INT DEFAULT 0,
    exercises_correct INT DEFAULT 0,
    exercises_incorrect INT DEFAULT 0,
    last_accessed TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES sprakapp_users(id) ON DELETE CASCADE,
    FOREIGN KEY (chapter_id) REFERENCES sprakapp_chapters(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_chapter (user_id, chapter_id),
    INDEX idx_user (user_id),
    INDEX idx_chapter (chapter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exercise Attempts table
CREATE TABLE IF NOT EXISTS sprakapp_exercise_attempts (
    id VARCHAR(36) PRIMARY KEY DEFAULT (UUID()),
    user_id VARCHAR(36) NOT NULL,
    exercise_id VARCHAR(36) NOT NULL,
    is_correct BOOLEAN NOT NULL,
    user_answer TEXT,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES sprakapp_users(id) ON DELETE CASCADE,
    FOREIGN KEY (exercise_id) REFERENCES sprakapp_exercises(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_exercise (exercise_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User Course Access table (for premium/paid courses)
CREATE TABLE IF NOT EXISTS sprakapp_user_course_access (
    id VARCHAR(36) PRIMARY KEY DEFAULT (UUID()),
    user_id VARCHAR(36) NOT NULL,
    course_id VARCHAR(36) NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    chapter_limit INT NULL,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES sprakapp_users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES sprakapp_courses(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_course (user_id, course_id),
    INDEX idx_user (user_id),
    INDEX idx_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions table (for managing user sessions)
CREATE TABLE IF NOT EXISTS sprakapp_sessions (
    id VARCHAR(36) PRIMARY KEY DEFAULT (UUID()),
    user_id VARCHAR(36) NOT NULL,
    token VARCHAR(500) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES sprakapp_users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default admin user (password: admin123 - CHANGE THIS!)
INSERT INTO sprakapp_users (id, email, password_hash) VALUES 
('550e8400-e29b-41d4-a716-446655440000', 'admin@sprakapp.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

INSERT INTO sprakapp_profiles (id, first_name, last_name, role) VALUES 
('550e8400-e29b-41d4-a716-446655440000', 'Admin', 'User', 'admin');
