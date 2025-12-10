-- Make a user admin by email
-- Replace 'user@example.com' with the actual email address

UPDATE sprakapp_profiles 
SET role = 'admin' 
WHERE id = (
    SELECT id 
    FROM sprakapp_users 
    WHERE email = 'user@example.com'
);

-- Verify the change
SELECT u.email, p.role, p.first_name, p.last_name
FROM sprakapp_users u
LEFT JOIN sprakapp_profiles p ON u.id = p.id
WHERE u.email = 'user@example.com';
