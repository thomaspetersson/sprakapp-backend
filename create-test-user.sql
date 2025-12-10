-- Create test admin user
-- Password: admin123 (hashed with bcrypt)

-- Insert user
INSERT INTO sprakapp_users (id, email, password_hash, created_at) 
VALUES (
    'admin-test-001', 
    'admin@test.com', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    NOW()
);

-- Insert profile with admin role
INSERT INTO sprakapp_profiles (id, role, first_name, last_name, created_at) 
VALUES (
    'admin-test-001', 
    'admin',
    'Test',
    'Admin',
    NOW()
);

-- Create regular test user
-- Password: user123

INSERT INTO sprakapp_users (id, email, password_hash, created_at) 
VALUES (
    'user-test-001', 
    'user@test.com', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    NOW()
);

INSERT INTO sprakapp_profiles (id, role, first_name, last_name, created_at) 
VALUES (
    'user-test-001', 
    'user',
    'Test',
    'User',
    NOW()
);
