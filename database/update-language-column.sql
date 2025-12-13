-- Increase language column size to allow longer language names
ALTER TABLE sprakapp_courses MODIFY COLUMN language VARCHAR(50) DEFAULT 'sv';
