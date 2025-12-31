-- SNABB ADMIN-ÅTERSTÄLLNING
-- Fyll i din email och önskat lösenord nedan, sedan kör hela detta script

-- STEG 1: Ändra dessa värden
SET @email = 'travoyazse@gmail.com';  -- DIN EMAIL
SET @password = 'ditt_nya_lösenord';  -- DITT ÖNSKADE LÖSENORD

-- STEG 2: Kör detta script - det skapar eller uppdaterar admin-användaren

-- Hitta befintlig användare eller skapa ny
SET @existing_user_id = (SELECT id FROM sprakapp_users WHERE email = @email LIMIT 1);

-- Om användaren finns, uppdatera lösenord och sätt som admin
UPDATE sprakapp_users 
SET password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- lösenord: "password"
    email_verified = 1 
WHERE email = @email;

UPDATE sprakapp_profiles 
SET role = 'admin' 
WHERE id = @existing_user_id;

-- Om användaren INTE finns, skapa ny (detta körs bara om UPDATE ovan inte hittade något)
INSERT INTO sprakapp_users (id, email, password_hash, email_verified, referral_code, trial_expires_at)
SELECT 
    UUID(),
    @email,
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- lösenord: "password"
    1,
    CONCAT('ADM', UPPER(SUBSTRING(MD5(RAND()), 1, 7))),
    DATE_ADD(NOW(), INTERVAL 365 DAY)
WHERE NOT EXISTS (SELECT 1 FROM sprakapp_users WHERE email = @email);

-- Skapa profil om den inte finns
INSERT INTO sprakapp_profiles (id, role, first_name, last_name)
SELECT 
    u.id,
    'admin',
    'Admin',
    'User'
FROM sprakapp_users u
WHERE u.email = @email
AND NOT EXISTS (SELECT 1 FROM sprakapp_profiles WHERE id = u.id);

-- Visa resultat
SELECT 
    u.id, 
    u.email, 
    p.role, 
    u.email_verified,
    'Lösenord är: password' as info
FROM sprakapp_users u
LEFT JOIN sprakapp_profiles p ON u.id = p.id
WHERE u.email = @email;

-- OBS! Lösenordet är nu "password" (utan citattecken)
-- Logga in med din email och lösenord: password
-- Byt sedan lösenord via kontoinställningar!
