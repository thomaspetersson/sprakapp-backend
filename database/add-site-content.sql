-- Site Content Table for editable texts
-- Allows admin to edit site texts in multiple languages

CREATE TABLE IF NOT EXISTS sprakapp_site_content (
    id VARCHAR(36) PRIMARY KEY DEFAULT (UUID()),
    content_key VARCHAR(100) NOT NULL UNIQUE,
    title_sv TEXT,
    title_en TEXT,
    description_sv TEXT,
    description_en TEXT,
    content_sv TEXT,
    content_en TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (content_key),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default content
INSERT INTO sprakapp_site_content (content_key, title_sv, title_en, description_sv, description_en, content_sv, content_en) VALUES 
('landing_hero', 
 'Välkommen till Polyverbo', 
 'Welcome to Polyverbo',
 'Lär dig nya språk på ett enkelt och effektivt sätt',
 'Learn new languages in a simple and effective way',
 'Med Polyverbo kan du lära dig språk i din egen takt med interaktiva övningar och kurser anpassade efter din nivå.',
 'With Polyverbo you can learn languages at your own pace with interactive exercises and courses tailored to your level.'),

('landing_features',
 'Våra Funktioner',
 'Our Features', 
 'Allt du behöver för att lära dig ett nytt språk',
 'Everything you need to learn a new language',
 'Interaktiva övningar, ljudinspelningar, grammatikförklaringar och mycket mer.',
 'Interactive exercises, audio recordings, grammar explanations and much more.'),

('landing_cta',
 'Kom igång idag',
 'Get Started Today',
 'Börja din språkresa nu',
 'Start your language journey now',
 'Registrera dig gratis och få tillgång till våra kurser.',
 'Sign up for free and get access to our courses.');
